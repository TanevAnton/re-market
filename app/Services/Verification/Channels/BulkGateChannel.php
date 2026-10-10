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
 * VIBER IS NOT IN THE CASCADE. Bulgaria carries a EUR 150/month minimum
 * commitment for Viber plus a 14-day sender registration, against roughly EUR 23
 * a month for SMS at the volumes expected. Leaving `viber` in VERIFY_CHANNELS
 * only buys a guaranteed failure before the fall through to SMS. Revisit past
 * ~2,000 messages a month.
 *
 * Account activated 10 Oct 2026; until a real code reaches a real handset,
 * treat the first attempt as a test rather than a feature.
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
        $text = $this->text($code);

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

            if ($response->successful() && blank($response->json('data.error'))) {
                return true;
            }

            /*
             * SAY WHY. This used to return false on a rejected send without
             * logging anything: the user saw „кодът не можа да се изпрати", the
             * cascade moved on, and the only record that BulkGate had refused
             * was in BulkGate's dashboard.
             *
             * The number is NOT logged. A phone number in laravel.log is
             * personal data in a file nobody thought of as a data store, and
             * the whole point of hashing it elsewhere is defeated by writing it
             * here in clear.
             */
            Log::warning("[verification] bulkgate {$this->channel} refused the send", [
                'status' => $response->status(),
                'error'  => $response->json('data.error'),
                'body'   => mb_substr($response->body(), 0, 500),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning("[verification] bulkgate {$this->channel} failed: ".$e->getMessage());

            return false;
        }
    }

    /**
     * ONE SEGMENT, AND THAT IS THE WHOLE DESIGN CONSTRAINT.
     *
     * Cyrillic forces UCS-2 encoding, so a single SMS is 70 characters, not
     * 160 - and a concatenated one is 67 per part. The wording filed with
     * BulkGate as the sample ("Кодът ти за потвърждение в RIGO е 123456.
     * Валиден 10 минути. Ако не си го поискал, игнорирай това съобщение.") is
     * 108 characters, so every code would cost two segments: roughly EUR 46 a
     * month instead of EUR 23, for politeness nobody reads.
     *
     * This is 64 characters with a six-digit code and a two-digit TTL, and it
     * still carries the three things that matter: who it is from, how long it
     * lasts, and what to do if you did not ask for it. The last one is not
     * manners - a code arriving unexpectedly is the first sign somebody is
     * trying to take an account, and the message is the only place to say so.
     *
     * CHECK THE LENGTH AGAIN if this text is ever edited. Going one character
     * over doubles the bill silently; nothing fails, nothing is logged, the
     * code still arrives.
     */
    private function text(string $code): string
    {
        return sprintf(
            '%s код: %s. Валиден %d мин. Ако не си го искал, игнорирай.',
            config('app.name'),
            $code,
            (int) config('remarket.verify.code_ttl', 10),
        );
    }
}
