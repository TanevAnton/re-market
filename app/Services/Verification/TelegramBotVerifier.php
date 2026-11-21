<?php

namespace App\Services\Verification;

use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns Telegram updates into verified phone numbers.
 *
 * Two messages make a verification: `/start <nonce>`, which tells us which
 * logged-in user this chat belongs to, and a contact share, which carries the
 * number Telegram itself verified when the account was created.
 *
 * No code is ever sent. There is nothing to intercept, nothing to guess, and
 * nothing for a user to be talked into reading out over the phone.
 */
class TelegramBotVerifier
{
    public function __construct(
        private readonly TelegramBot $bot,
    ) {}

    /** @param array<string, mixed> $update one entry from getUpdates */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return;
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);

        if ($chatId === 0) {
            return;
        }

        if (isset($message['contact'])) {
            $this->handleContact($chatId, $message);

            return;
        }

        $text = (string) ($message['text'] ?? '');

        if (str_starts_with($text, '/start')) {
            $this->handleStart($chatId, trim(substr($text, 6)));
        }
    }

    private function handleStart(int $chatId, string $nonce): void
    {
        if ($nonce === '') {
            $this->bot->say($chatId, 'Здравей! За да потвърдиш номера си, отвори страницата '
                .'за потвърждение в сайта и натисни бутона там.');

            return;
        }

        $link = TelegramLink::usable()->where('nonce', $nonce)->first();

        if (! $link) {
            // Expired or already used. Say so plainly rather than leaving them
            // staring at a bot that ignored them.
            $this->bot->say($chatId, 'Тази връзка е изтекла. Върни се в сайта и опитай отново.');

            return;
        }

        if ($link->purpose === TelegramLink::PURPOSE_ALERTS) {
            $this->connectForAlerts($link, $chatId);

            return;
        }

        // Remember which chat is answering, so the contact message that follows
        // can be traced back to this user.
        $link->forceFill(['telegram_chat_id' => $chatId])->save();

        $this->bot->requestContact($chatId,
            'Натисни бутона отдолу, за да споделиш номера си. '
            .'Telegram го е потвърдил при регистрацията ти, така че няма код за въвеждане.');
    }

    /**
     * „Пиши ми тук": connect the chat and nothing else.
     *
     * No phone number is asked for and none is stored - the person pressed a
     * button on the site while logged in, then pressed Start in the bot, and
     * that pair is the whole proof that this chat belongs to this account.
     * It proves nothing about a phone, so `phone_verified_at` is not touched.
     *
     * One chat, one account. A second account claiming a chat that already
     * receives another account's messages would read that account's offers
     * and deal details aloud to a stranger, so it is refused rather than
     * quietly re-pointed.
     */
    private function connectForAlerts(TelegramLink $link, int $chatId): void
    {
        $taken = User::where('telegram_chat_id', $chatId)
            ->whereKeyNot($link->user_id)
            ->exists();

        if ($taken) {
            $this->bot->say($chatId, 'Този Telegram вече е свързан с друг профил.');

            return;
        }

        DB::transaction(function () use ($link, $chatId) {
            $user = $link->user;

            $user->telegram_chat_id = $chatId;
            $user->notify_telegram  = true;
            $user->save();

            $link->forceFill(['telegram_chat_id' => $chatId, 'consumed_at' => now()])->save();
        });

        Log::info('[telegram-bot] connected for alerts', ['user_id' => $link->user_id]);

        $this->bot->say($chatId, 'Готово — известията от сайта ще идват тук. '
            .'Можеш да ги спреш по всяко време от профила си.');
    }

    private function handleContact(int $chatId, array $message): void
    {
        $contact = $message['contact'];

        /*
         * THE SECURITY CHECK. Telegram lets anyone forward anyone else's
         * contact card, and such a card looks almost identical to this one.
         * The single thing that distinguishes "my own number", which Telegram
         * vouches for, from "a number I happen to have saved" is that
         * contact.user_id matches the sender.
         *
         * Without this line the whole flow verifies nothing: a user could
         * claim any number in their address book.
         */
        $contactUserId = (int) ($contact['user_id'] ?? 0);
        $senderId      = (int) ($message['from']['id'] ?? -1);

        if ($contactUserId === 0 || $contactUserId !== $senderId) {
            $this->bot->say($chatId, 'Това е чужд контакт. Използвай бутона '
                .'„Сподели номера си", за да изпратиш своя собствен номер.');

            return;
        }

        $link = TelegramLink::usable()->where('telegram_chat_id', $chatId)->latest('id')->first();

        if (! $link) {
            $this->bot->say($chatId, 'Няма активна заявка за този чат. Започни от сайта.');

            return;
        }

        $e164 = PhoneNumber::normalize((string) ($contact['phone_number'] ?? ''));

        if ($e164 === null) {
            $this->bot->say($chatId, 'Този номер не изглежда като български мобилен номер.');

            return;
        }

        $hash = User::hashPhone($e164);

        // One phone, one account - the same rule the code flow enforces, and
        // the reason it exists is unchanged: it is what makes a ban cost
        // something.
        $taken = User::where('phone_hash', $hash)
            ->whereKeyNot($link->user_id)
            ->withTrashed()
            ->exists();

        if ($taken) {
            $this->bot->say($chatId, 'Този номер вече е свързан с друг профил.');

            return;
        }

        DB::transaction(function () use ($link, $e164, $chatId) {
            $user = $link->user;

            $user->setPhone($e164);
            $user->phone_verified_at = now();

            /*
             * Keep the chat. It was being thrown away with the consumed link,
             * and it is the whole reason notifications can be free: the user
             * opened this conversation themselves to prove their number, so
             * there is no new channel to ask permission for.
             */
            $user->telegram_chat_id = $chatId;
            $user->save();

            // Single use. Consuming it inside the transaction is what stops a
            // replayed update verifying twice.
            $link->forceFill(['consumed_at' => now()])->save();
        });

        Log::info('[telegram-bot] verified', ['user_id' => $link->user_id]);

        $this->bot->say($chatId, 'Готово — номерът ти е потвърден. Върни се в сайта.');
    }
}
