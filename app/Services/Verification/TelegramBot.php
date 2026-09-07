<?php

namespace App\Services\Verification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Telegram Bot API.
 *
 * Only the four calls this flow needs. Deliberately not a general client: the
 * less of that API surface is reachable from here, the less there is to get
 * wrong or to abuse if a token ever leaks.
 *
 * Everything is outbound HTTPS, which is why this works from a machine behind
 * NAT with no public address - webhooks would need one, long polling does not.
 */
class TelegramBot
{
    public function __construct(
        private readonly ?string $token = null,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->token());
    }

    /**
     * Long poll for updates.
     *
     * `timeout` is seconds Telegram will hold the connection open waiting for
     * something to happen - that is what makes this near-instant for the user
     * without hammering the API. The HTTP timeout must exceed it or the client
     * hangs up on every quiet poll.
     */
    public function getUpdates(int $offset, int $timeout = 25): array
    {
        $response = $this->call('getUpdates', [
            'offset'          => $offset,
            'timeout'         => $timeout,
            // Anything else is noise for this flow, and asking for less means
            // Telegram does not queue it for us in the first place.
            'allowed_updates' => ['message'],
        ], httpTimeout: $timeout + 10);

        return $response['result'] ?? [];
    }

    /** Ask for the contact, with the button that shares it. */
    public function requestContact(int $chatId, string $text): void
    {
        $this->call('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $text,
            'reply_markup' => json_encode([
                'keyboard' => [[[
                    'text'            => 'Сподели номера си',
                    'request_contact' => true,
                ]]],
                'resize_keyboard'   => true,
                'one_time_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** Plain reply, and take the keyboard away again. */
    public function say(int $chatId, string $text): void
    {
        $this->call('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $text,
            'reply_markup' => json_encode(['remove_keyboard' => true]),
        ]);
    }

    /** Used by the health check to prove the token works. */
    public function getMe(): ?array
    {
        return $this->call('getMe')['result'] ?? null;
    }

    /**
     * A bot cannot use getUpdates while a webhook is registered - Telegram
     * answers 409 Conflict and hands the poller nothing, forever, in silence.
     * Worth checking explicitly, because the symptom is indistinguishable from
     * "nobody has messaged the bot".
     */
    public function webhookUrl(): ?string
    {
        $url = $this->call('getWebhookInfo')['result']['url'] ?? '';

        return $url !== '' ? $url : null;
    }

    public function deleteWebhook(): bool
    {
        return ($this->call('deleteWebhook', ['drop_pending_updates' => false])['ok'] ?? false) === true;
    }

    private function call(string $method, array $payload = [], int $httpTimeout = 15): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        try {
            $response = Http::timeout($httpTimeout)
                ->asJson()
                ->post("https://api.telegram.org/bot{$this->token()}/{$method}", $payload)
                ->json();

            if (($response['ok'] ?? false) !== true) {
                Log::warning("[telegram-bot] {$method} refused", [
                    'error' => $response['description'] ?? 'unknown',
                ]);
            }

            return is_array($response) ? $response : [];
        } catch (\Throwable $e) {
            // A poll loop must survive the network going away. Returning empty
            // means "nothing happened this round", and the caller sleeps and
            // tries again rather than the whole worker dying.
            Log::warning("[telegram-bot] {$method} failed: ".$e->getMessage());

            return [];
        }
    }

    private function token(): ?string
    {
        return $this->token ?? config('remarket.verify.telegram_bot_token');
    }
}
