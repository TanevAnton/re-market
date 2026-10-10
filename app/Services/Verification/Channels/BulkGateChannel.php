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
     * Per-recipient outcomes that mean the message did NOT go.
     *
     * Listed as the REJECTIONS rather than the acceptances, which is the whole
     * lesson of this class. The first version accepted anything that was not a
     * recognised failure, so an HTML error page counted as a sent SMS. The
     * second required one exact success shape, so a real send whose response
     * was shaped differently was reported as failed - while the message sat in
     * somebody's hand.
     *
     * Both were guesses about a response nobody had looked at. This version
     * refuses on evidence of failure, accepts otherwise, and writes the body to
     * the log whenever it meets a shape it does not recognise, so the next
     * person works from an observation instead of a third guess.
     */
    private const REJECTED = [
        'error', 'blacklisted', 'invalid_number', 'invalid_sender', 'duplicity_message',
    ];

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

        $json = $response->json();

        /*
         * Not an API answer at all. An unreachable or redirected endpoint ends
         * up here as an HTML page with a 200, which is exactly how the original
         * bug reported every failed send as a success for a month.
         */
        if (! $response->successful() || ! is_array($json) || ! array_key_exists('data', $json)) {
            Log::warning("[verification] bulkgate {$this->channel} refused the send", [
                'http'   => $response->status(),
                'type'   => data_get($json, 'type'),
                'error'  => data_get($json, 'error'),
                'detail' => data_get($json, 'detail'),
                'body'   => mb_substr($response->body(), 0, 500),
            ]);

            return false;
        }

        // `response` is a list for several recipients and may be a single
        // object for one, so handle both rather than betting on either.
        $block    = data_get($json, 'data.response');
        $statuses = [];

        if (is_array($block)) {
            $rows = array_is_list($block) ? $block : [$block];

            foreach ($rows as $row) {
                if (is_array($row) && isset($row['status'])) {
                    $statuses[] = (string) $row['status'];
                }
            }
        }

        if (array_intersect($statuses, self::REJECTED) !== []) {
            Log::warning("[verification] bulkgate {$this->channel} did not accept the message", [
                'statuses' => $statuses,
                'totals'   => data_get($json, 'data.total.status'),
            ]);

            return false;
        }

        if ($statuses === []) {
            // Accepted, but we could not find a status to confirm it with. Say
            // so once, with the body, so the shape stops being a mystery.
            Log::info("[verification] bulkgate {$this->channel} accepted, response shape unrecognised", [
                'body' => mb_substr($response->body(), 0, 500),
            ]);
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
