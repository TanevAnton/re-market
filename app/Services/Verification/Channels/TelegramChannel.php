<?php

namespace App\Services\Verification\Channels;

use App\Services\Verification\VerificationChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Gateway - roughly $0.01 per code, and FREE to your own number.
 *
 * "Both checkSendAbility and sendVerificationMessage are always free of charge
 * when used to send codes to your own phone number" - which makes this the
 * right channel to develop against: the account that owns the token can verify
 * itself forever without spending anything.
 *
 * It fails for anyone who does not use Telegram. That is expected, and is the
 * reason it sits first in a cascade rather than alone: a false return here
 * falls through to Viber, then SMS.
 *
 * Written against https://core.telegram.org/gateway/api, re-checked September
 * 2026. Still never executed against a live token - the shapes below match the
 * published docs, and the logging is deliberately loud so the first real send
 * tells you what went wrong rather than just failing.
 */
class TelegramChannel implements VerificationChannel
{
    private const BASE = 'https://gatewayapi.telegram.org/';

    /** The API accepts 30-3600 seconds and errors outside it. */
    private const TTL_MIN = 30;
    private const TTL_MAX = 3600;

    public function name(): string
    {
        return 'telegram';
    }

    public function isAvailable(): bool
    {
        return filled(config('remarket.verify.telegram_token'));
    }

    public function send(string $e164, string $code): bool
    {
        // Ask first. This is what tells us "this number has no Telegram"
        // BEFORE we commit to a send, so the cascade can fall through to Viber
        // instead of burning the attempt. It costs nothing extra: within one
        // request_id only a single fee is ever charged.
        $ability = $this->call('checkSendAbility', ['phone_number' => $e164]);

        if ($ability === null || ($ability['ok'] ?? false) !== true) {
            $this->explain('checkSendAbility', $e164, $ability);

            return false;
        }

        $payload = [
            'phone_number' => $e164,
            'code'         => $code,
            'ttl'          => $this->ttlSeconds(),
        ];

        // Carrying the request_id forward is what makes the pair count as one
        // billable request rather than two.
        if (filled($ability['result']['request_id'] ?? null)) {
            $payload['request_id'] = $ability['result']['request_id'];
        }

        $response = $this->call('sendVerificationMessage', $payload);

        if ($response === null || ($response['ok'] ?? false) !== true) {
            $this->explain('sendVerificationMessage', $e164, $response);

            return false;
        }

        // Cost and balance in the log, because the alternative is discovering
        // the credit ran out by way of users not being able to register.
        Log::info('[verification] telegram sent', [
            'cost'      => $response['result']['request_cost'] ?? null,
            'remaining' => $response['result']['remaining_balance'] ?? null,
            'status'    => $response['result']['delivery_status']['status'] ?? null,
        ]);

        return true;
    }

    /** @return array<string, mixed>|null null means the call itself failed */
    private function call(string $method, array $payload): ?array
    {
        try {
            return Http::withToken(config('remarket.verify.telegram_token'))
                ->timeout(8)
                ->asJson()
                ->post(self::BASE.$method, $payload)
                ->json();
        } catch (\Throwable $e) {
            Log::warning("[verification] telegram {$method} threw: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Telegram returns a machine-readable code in `error`, and it is the only
     * thing that distinguishes "this person has no Telegram" (fine, fall
     * through) from "your token is wrong" (not fine, nothing will ever work).
     * Swallowing it is how you end up staring at "every channel failed".
     */
    private function explain(string $method, string $e164, ?array $response): void
    {
        Log::warning("[verification] telegram {$method} refused", [
            'phone' => substr($e164, -4),
            'error' => $response['error'] ?? 'no response',
        ]);
    }

    private function ttlSeconds(): int
    {
        $seconds = (int) config('remarket.verify.code_ttl', 10) * 60;

        return max(self::TTL_MIN, min(self::TTL_MAX, $seconds));
    }
}
