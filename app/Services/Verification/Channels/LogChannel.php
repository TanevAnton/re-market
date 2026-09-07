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

    public function isAvailable(): bool
    {
        return ! app()->environment('production');
    }

    public function send(string $e164, string $code): bool
    {
        Log::info("[verification] {$e164} -> code {$code}");

        return true;
    }
}
