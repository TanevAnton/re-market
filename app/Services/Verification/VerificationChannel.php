<?php

namespace App\Services\Verification;

interface VerificationChannel
{
    /** Machine name stored on the verification row: telegram | viber | sms | log */
    public function name(): string;

    /** False if this channel is not configured or cannot reach this number. */
    public function isAvailable(): bool;

    /**
     * Deliver the code. Returning false is NOT an error - it means "try the
     * next channel", which is the whole point of the cascade.
     */
    public function send(string $e164, string $code): bool;
}
