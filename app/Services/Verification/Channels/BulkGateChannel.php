<?php

namespace App\Services\Verification\Channels;

use App\Services\Verification\VerificationChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BulkGate, used for both Viber and SMS - same API, different channel block.
 *
 * Viber first (~EUR 0.017 and dominant in Bulgaria), SMS as the last resort
 * (~EUR 0.04). For comparison, Twilio Verify would be about $0.20 per code to
 * a Bulgarian number, so this cascade is roughly a 5-10x saving.
 *
 * NOT YET VERIFIED against the live API - written from published docs and
 * never executed with real credentials.
 */
class BulkGateChannel implements VerificationChannel
{
    private const ENDPOINT = 'https://api.bulkgate.com/2.0/advanced/transactional';

    public function __construct(
        private readonly string $channel,   // 'viber' | 'sms'
    ) {}

    public function name(): string
    {
        return $this->channel;
    }

    public function isAvailable(): bool
    {
        return filled(config('remarket.verify.bulkgate_app_id'))
            && filled(config('remarket.verify.bulkgate_token'));
    }

    public function send(string $e164, string $code): bool
    {
        $text = "Кодът ти за ".config('app.name').": {$code}";

        $payload = [
            'application_id'    => config('remarket.verify.bulkgate_app_id'),
            'application_token' => config('remarket.verify.bulkgate_token'),
            'number'            => ltrim($e164, '+'),
            'channel'           => $this->channel === 'viber'
                ? ['viber' => ['sender' => config('remarket.verify.sender_id'), 'text' => $text]]
                : ['sms'   => ['sender_id' => 'gText', 'sender_id_value' => config('remarket.verify.sender_id'), 'text' => $text]],
        ];

        try {
            $response = Http::timeout(10)->post(self::ENDPOINT, $payload);

            return $response->successful() && blank($response->json('data.error'));
        } catch (\Throwable $e) {
            Log::warning("[verification] bulkgate {$this->channel} failed: ".$e->getMessage());

            return false;
        }
    }
}
