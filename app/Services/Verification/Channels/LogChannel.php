<?php

namespace App\Services\Verification\Channels;

use App\Services\Verification\VerificationChannel;
use Illuminate\Support\Facades\Log;

/**
 * Local development. Writes the code to the log instead of spending money,
 * so the whole signup flow is testable with no API credentials at all.
 * Refuses to run in production - a channel that silently "delivers" nothing
 * would let anyone register as anyone.
 */
class LogChannel implements VerificationChannel
{
    public function name(): string
    {
        return 'log';
    }

    /**
     * Outside production this is always on, so local signup works with no
     * credentials and no spend.
     *
     * In production it stays off unless someone explicitly turns it on. The
     * flag is deliberately long and ugly, because what it does is dangerous:
     * a channel that reports success while delivering nothing means the code
     * is only ever visible in a log file, and anyone who can read that log can
     * verify anyone's phone number. Fine for a LAN test server with no real
     * users; a full account-takeover hole the moment there are.
     */
    public function isAvailable(): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        if (! config('remarket.verify.allow_log_channel_in_production', false)) {
            return false;
        }

        Log::warning('[verification] log channel is enabled IN PRODUCTION - '
            .'codes are being written to the log instead of sent. '
            .'Unset VERIFY_ALLOW_LOG_CHANNEL before real users exist.');

        return true;
    }

    public function send(string $e164, string $code): bool
    {
        Log::info("[verification] {$e164} -> code {$code}");

        return true;
    }
}
