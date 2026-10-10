<?php

namespace App\Services\Verification\Channels;

use App\Services\Verification\VerificationChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BulkGate, used for both Viber and SMS - same API, different channel block.
 *
 * VIBER IS NOT IN THE CASCADE. Bulgaria carries a EUR 150/month minimum
 * commitment for Viber plus a 14-day sender registration, against roughly EUR 23
 * a month for SMS at the volumes expected. Leaving `viber` in VERIFY_CHANNELS
 * only buys a guaranteed failure before the fall through to SMS. Revisit past
 * ~2,000 messages a month. The viber block below is therefore UNVERIFIED - it
 * has never been sent and its shape is not checked against current docs.
 *
 * THIS CLASS WAS WRONG IN THREE WAYS UNTIL 10 Oct 2026, all of them invisible
 * because it had never once been executed:
 *
 *   - It posted to https://api.bulkgate.com/2.0/advanced/transactional. That
 *     host does not serve the API at all; it 301s to the marketing site, so
 *     every send would have followed a redirect to an HTML page and been read
 *     as a failure with nothing useful logged.
 *   - `text` sat only inside the channel block. It is a TOP-LEVEL field, so the
 *     request was missing a required parameter.
 *   - `unicode` was never set. Every message this sends is Cyrillic.
 *
 * Which is the whole argument for the note that used to be here: code written
 * from documentation and never run is a draft, not a feature. It is still a
 * draft now - the fix above is read off current docs, and the first real send
 * is the thing that settles it.
 */
class BulkGateChannel implements VerificationChannel
{
    private const ENDPOINT = 'https://portal.bulkgate.com/api/2.0/advanced/transactional';

    /**
     * Per-recipient outcomes that mean the message is on its way.
     *
     * The others - error, blacklisted, invalid_number, invalid_sender,
     * duplicity_message - arrive inside a perfectly successful HTTP 200, which
     * is exactly how a broken integration reports itself as healthy.
     */
    private const ACCEPTED = ['sent', 'accepted', 'scheduled'];

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
        $payload = [
            'application_id'    => config('remarket.verify.bulkgate_app_id'),
            'application_token' => config('remarket.verify.bulkgate_token'),
            'number'            => ltrim($e164, '+'),
            'text'              => $this->text($code),
            'channel'           => $this->channel === 'viber'
                ? ['viber' => ['sender' => config('remarket.verify.sender_id')]]
                : ['sms'   => [
                    'sender_id'       => 'gText',
                    'sender_id_value' => config('remarket.verify.sender_id'),

                    // Not optional here. The text is Cyrillic, which is UCS-2,
                    // and a gateway told it is GSM-7 either mangles it or
                    // refuses it.
                    'unicode'         => true,
                ]],
        ];

        try {
            $response = Http::timeout(10)->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            Log::warning("[verification] bulkgate {$this->channel} unreachable: ".$e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            /*
             * SAY WHY. This used to return false on a rejected send without
             * logging anything: the user saw „кодът не можа да се изпрати", the
             * cascade moved on, and the only record that BulkGate had refused
             * was in BulkGate's own dashboard.
             *
             * The number is NOT logged. A phone number in laravel.log is
             * personal data in a file nobody thinks of as a data store, and the
             * whole point of hashing it everywhere else is defeated by writing
             * it here in clear.
             */
            Log::warning("[verification] bulkgate {$this->channel} refused the send", [
                'http'   => $response->status(),
                'type'   => $response->json('type'),
                'error'  => $response->json('error'),
                'detail' => $response->json('detail'),
            ]);

            return false;
        }

        // A 200 is not delivery. The per-recipient status is the real answer.
        $status = $response->json('data.response.0.status');

        if (! in_array($status, self::ACCEPTED, true)) {
            Log::warning("[verification] bulkgate {$this->channel} returned 200 but did not accept the message", [
                'status' => $status,
                'totals' => $response->json('data.total.status'),
            ]);

            return false;
        }

        return true;
    }

    /**
     * ONE SEGMENT, AND THAT IS THE WHOLE DESIGN CONSTRAINT.
     *
     * Cyrillic forces UCS-2, so a single SMS is 70 characters, not 160 - and a
     * concatenated one is 67 per part. The wording filed with BulkGate as the
     * sample ("Кодът ти за потвърждение в RIGO е 123456. Валиден 10 минути. Ако
     * не си го поискал, игнорирай това съобщение.") is 108 characters, so every
     * code would cost two segments: roughly EUR 46 a month instead of EUR 23,
     * for politeness nobody reads.
     *
     * This is 64 characters with a six-digit code and a two-digit TTL, and it
     * still carries the three things that matter: who it is from, how long it
     * lasts, and what to do if you did not ask for it. The last one is not
     * manners - a code arriving unexpectedly is the first sign somebody is
     * trying to take an account, and the message is the only place to say so.
     *
     * CHECK THE LENGTH AGAIN if this text is ever edited. One character over
     * doubles the bill silently; nothing fails, nothing is logged, the code
     * still arrives.
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
