<?php

namespace App\Services\Verification;

use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\Verification\Channels\BulkGateChannel;
use App\Services\Verification\Channels\LogChannel;
use App\Services\Verification\Channels\TelegramChannel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class PhoneVerifier
{
    public function __construct(
        private readonly ?string $ip = null,
    ) {}

    /**
     * Cheapest channel first. A channel returning false means "not reachable
     * this way" rather than "failed", so we fall through to the next one.
     */
    public function channels(): array
    {
        $available = [
            'telegram' => fn () => new TelegramChannel(),
            'viber'    => fn () => new BulkGateChannel('viber'),
            'sms'      => fn () => new BulkGateChannel('sms'),
            'log'      => fn () => new LogChannel(),
        ];

        $configured = collect(config('remarket.verify.channels', []))
            ->map(fn ($name) => $available[trim($name)] ?? null)
            ->filter()
            ->map(fn (callable $factory) => $factory())
            ->filter(fn (VerificationChannel $c) => $c->isAvailable())
            ->values()
            ->all();

        // With no credentials configured at all, fall back to the log channel
        // so local development works out of the box. isAvailable() blocks this
        // in production.
        if ($configured === []) {
            $log = new LogChannel();

            return $log->isAvailable() ? [$log] : [];
        }

        return $configured;
    }

    /**
     * @return array{sent: bool, channel: ?string, reason: ?string}
     */
    public function send(User $user, string $rawPhone): array
    {
        $e164 = PhoneNumber::normalize($rawPhone);

        if ($e164 === null) {
            return ['sent' => false, 'channel' => null, 'reason' => 'invalid_number'];
        }

        $hash = User::hashPhone($e164);

        // One phone, one account. Checked before we spend money on a message.
        $taken = User::where('phone_hash', $hash)
            ->whereKeyNot($user->getKey())
            ->withTrashed()
            ->exists();

        if ($taken) {
            return ['sent' => false, 'channel' => null, 'reason' => 'number_in_use'];
        }

        if (! $this->underLimits($hash)) {
            return ['sent' => false, 'channel' => null, 'reason' => 'rate_limited'];
        }

        $code = $this->generateCode();

        foreach ($this->channels() as $channel) {
            if (! $channel->send($e164, $code)) {
                continue;
            }

            PhoneVerification::create([
                'user_id'    => $user->getKey(),
                'phone_hash' => $hash,
                'channel'    => $channel->name(),
                'code_hash'  => Hash::make($code),
                'ip'         => $this->ip,
                'expires_at' => now()->addMinutes(config('remarket.verify.code_ttl', 10)),
            ]);

            $this->recordAttempt($hash);

            return ['sent' => true, 'channel' => $channel->name(), 'reason' => null];
        }

        Log::error("[verification] every channel failed for {$e164}");

        return ['sent' => false, 'channel' => null, 'reason' => 'no_channel'];
    }

    /**
     * @return array{verified: bool, reason: ?string}
     */
    public function verify(User $user, string $rawPhone, string $code): array
    {
        $e164 = PhoneNumber::normalize($rawPhone);

        if ($e164 === null) {
            return ['verified' => false, 'reason' => 'invalid_number'];
        }

        $hash = User::hashPhone($e164);

        $attempt = PhoneVerification::where('phone_hash', $hash)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $attempt || ! $attempt->isUsable()) {
            return ['verified' => false, 'reason' => 'expired'];
        }

        // Count the attempt BEFORE checking, so a wrong guess always costs one
        // even if the request dies midway.
        $attempt->increment('attempts');

        if (! $attempt->matches($code)) {
            return ['verified' => false, 'reason' => 'wrong_code'];
        }

        // forceFill: verified_at is not mass-assignable on purpose, so update()
        // was silently dropping it. The row stayed unverified, which meant the
        // same code could be replayed until it expired. Guarded now by
        // preventSilentlyDiscardingAttributes() in AppServiceProvider.
        $attempt->forceFill(['verified_at' => now()])->save();

        $user->setPhone($e164);
        $user->phone_verified_at = now();
        $user->save();

        return ['verified' => true, 'reason' => null];
    }

    /** Six digits, from a CSPRNG - never mt_rand for anything auth-shaped. */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function underLimits(string $hash): bool
    {
        // Per number: slow a targeted attack. Per IP: slow a broad one.
        return ! RateLimiter::tooManyAttempts("verify:phone:{$hash}", 5)
            && ! RateLimiter::tooManyAttempts('verify:ip:'.($this->ip ?? 'none'), 20);
    }

    private function recordAttempt(string $hash): void
    {
        RateLimiter::hit("verify:phone:{$hash}", 3600);
        RateLimiter::hit('verify:ip:'.($this->ip ?? 'none'), 3600);
    }
}
