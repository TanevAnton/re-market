<?php

namespace App\Services\Traders;

use App\Models\User;
use App\Notifications\TraderDecision;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Confirming that a declared company exists and belongs to the seller.
 *
 * WHAT THIS IS NOT. It is not the Art. 6a disclosure — that is
 * `users.seller_type`, it already ships, it is required of everybody, and it is
 * the seller's own word. This is the optional second claim: somebody opened the
 * Commercial Register and the company was there, with that ЕИК, at that address.
 *
 * CHECKED BY A PERSON, and the interface is shaped so that stops being true
 * later without anything else moving. The Registry Agency publishes a web
 * service, but whether it can be queried without a certificate and an agreement
 * is a question nobody here has answered — so the check is a moderator with the
 * public portal open, and `verify()` takes the moderator who did it. An
 * automated lookup becomes a second caller of the same method rather than a
 * rewrite of this class.
 *
 * NOTHING HERE TOUCHES `trader_details`. That column is the seller's own
 * submission, fillable, and written from their settings form. The four
 * verification columns are not fillable at all — a moderation decision must sit
 * where user input cannot reach it.
 */
class TraderVerification
{
    /**
     * A trader asks to be checked.
     *
     * @throws RuntimeException when there is nothing to check
     */
    public function apply(User $user): User
    {
        if (! $user->isTrader()) {
            throw new RuntimeException(
                'Само профил, който продава като търговец, може да иска проверка. '
                .'Смени типа в настройките.'
            );
        }

        $details = $user->trader_details ?? [];

        /*
         * The three fields a moderator needs to find the company. Checked here
         * rather than trusted from the settings form, because a trader who
         * declared themselves before those fields were required still has an
         * account with blanks in it — and an application a moderator cannot act
         * on is worse than none, since it sits in the queue looking like work.
         */
        foreach (['company' => 'фирма', 'uic' => 'ЕИК', 'address' => 'адрес'] as $key => $what) {
            if (blank($details[$key] ?? null)) {
                throw new RuntimeException(
                    'Липсва '.$what.' в данните за фирмата. Попълни ги в настройките и опитай пак.'
                );
            }
        }

        if ($user->trader_status === User::TRADER_VERIFIED) {
            throw new RuntimeException('Фирмата вече е проверена.');
        }

        if ($user->trader_status === User::TRADER_PENDING) {
            // Not an error worth shouting about: they clicked twice, or came
            // back to check. Returning the same state is the honest answer.
            return $user;
        }

        $user->forceFill([
            'trader_status' => User::TRADER_PENDING,
            // The previous refusal is cleared on re-application, so the queue
            // never shows a moderator a note about a submission that changed.
            'trader_note'   => null,
        ])->save();

        return $user;
    }

    /**
     * A moderator found the company. `$note` records what they checked.
     */
    public function verify(User $user, User $moderator, string $note = ''): User
    {
        $this->assertPending($user);

        if ($user->id === $moderator->id) {
            // The same rule as approving your own listing: a check that reviews
            // itself is not a check.
            throw new RuntimeException('Не можеш да провериш собствената си фирма.');
        }

        $user = DB::transaction(function () use ($user, $moderator, $note) {
            $user->forceFill([
                'trader_status'      => User::TRADER_VERIFIED,
                'trader_verified_at' => now(),
                'trader_verified_by' => $moderator->id,
                'trader_note'        => $note === '' ? null : mb_substr($note, 0, 500),
            ])->save();

            return $user;
        });

        $user->notify(new TraderDecision(approved: true));

        return $user;
    }

    /**
     * The company could not be confirmed.
     *
     * `$note` is REQUIRED and the applicant reads it. „Не мина" teaches them
     * nothing and produces a support ticket; „ЕИК 123456789 е на друго
     * дружество" is something they can correct or dispute. Same rule as the
     * statement of reasons for a removed listing.
     */
    public function reject(User $user, User $moderator, string $note): User
    {
        $this->assertPending($user);

        if (mb_strlen(trim($note)) < 10) {
            throw new RuntimeException('Напиши какво не съвпада — продавачът го вижда.');
        }

        $user = DB::transaction(function () use ($user, $moderator, $note) {
            $user->forceFill([
                'trader_status'      => User::TRADER_REJECTED,
                'trader_verified_at' => null,
                'trader_verified_by' => $moderator->id,
                'trader_note'        => mb_substr(trim($note), 0, 500),
            ])->save();

            return $user;
        });

        $user->notify(new TraderDecision(approved: false, reason: $user->trader_note));

        return $user;
    }

    /**
     * Take a badge back.
     *
     * Needed because a verification is a statement about a present fact: a
     * company that was struck from the register, or an account that changed
     * hands, must stop wearing it. Silent by design — the note says why, and
     * telling a seller their badge is gone is a conversation rather than a
     * notification.
     */
    public function revoke(User $user, User $moderator, string $note): User
    {
        if ($user->trader_status !== User::TRADER_VERIFIED) {
            throw new RuntimeException('Тази фирма не е проверена.');
        }

        if (mb_strlen(trim($note)) < 10) {
            throw new RuntimeException('Напиши защо се отнема.');
        }

        $user->forceFill([
            'trader_status'      => User::TRADER_REJECTED,
            'trader_verified_at' => null,
            'trader_verified_by' => $moderator->id,
            'trader_note'        => mb_substr(trim($note), 0, 500),
        ])->save();

        return $user;
    }

    private function assertPending(User $user): void
    {
        if ($user->trader_status !== User::TRADER_PENDING) {
            throw new RuntimeException('Няма чакаща заявка за този профил.');
        }
    }
}
