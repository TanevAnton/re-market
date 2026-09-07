<?php

namespace App\Services\Verification\Channels;

use App\Services\Verification\VerificationChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Gateway - the cheapest channel at roughly $0.01 per code.
 *
 * Fails for anyone without Telegram, which is expected and is why it sits
 * first in a cascade rather than alone.
 *
 * NOT YET VERIFIED against the live API - written from the published docs at
 * https://core.telegram.org/gateway/api and never executed with a real token.
 * Confirm the request and response shape before trusting it in production.
 */
class TelegramChannel implements VerificationChannel
{
    private const ENDPOINT = 'https://gatewayapi.telegram.org/sendVerificationMessage';

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
        try {
            $response = Http::withToken(config('remarket.verify.telegram_token'))
                ->timeout(8)
                ->post(self::ENDPOINT, [
                    'phone_number' => $e164,
                    'code'         => $code,
                    'ttl'          => config('remarket.verify.code_ttl') * 60,
                ]);

            return $response->successful() && ($response->json('ok') === true);
        } catch (\Throwable $e) {
            Log::warning('[verification] telegram failed: '.$e->getMessage());

            return false;
        }
    }
}
