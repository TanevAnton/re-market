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
     * @return array{sent: bool, channel: ?string, reason: ?string, retry_after: ?int}
     */
    public function send(User $user, string $rawPhone): array
    {
        $e164 = PhoneNumber::normalize($rawPhone);

        if ($e164 === null) {
            return $this->refuse('invalid_number');
        }

        $hash = User::hashPhone($e164);

        // One phone, one account. Checked before we spend money on a message.
        $taken = User::where('phone_hash', $hash)
            ->whereKeyNot($user->getKey())
            ->withTrashed()
            ->exists();

        if ($taken) {
            return $this->refuse('number_in_use');
        }

        if (! $this->underLimits($hash)) {
            return $this->refuse('rate_limited');
        }

        /*
         * THE CODE THEY ALREADY HAVE IS STILL GOOD.
         *
         * Sending a second one costs another message and makes nothing better:
         * the first is still live, still the one in their hand, and the usual
         * reason this method is called twice is that a carrier took ten seconds
         * and the person pressed the button again. The caller is told to show
         * the entry form, not an error - they are not being blocked, they are
         * being told they already have what they are asking for.
         */
        if ($live = $this->liveCodeFor($hash)) {
            return [
                'sent'        => false,
                'channel'     => $live->channel,
                'reason'      => 'cooldown',
                'retry_after' => $this->retryAfter($live),
            ];
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

            return ['sent' => true, 'channel' => $channel->name(), 'reason' => null, 'retry_after' => null];
        }

        Log::error("[verification] every channel failed for {$e164}");

        return $this->refuse('no_channel');
    }

    /** @return array{sent: bool, channel: ?string, reason: ?string, retry_after: ?int} */
    private function refuse(string $reason): array
    {
        return ['sent' => false, 'channel' => null, 'reason' => $reason, 'retry_after' => null];
    }

    /**
     * The most recent code for this number that is still worth using.
     *
     * `isUsable()` matters as much as the window: a code whose five guesses are
     * spent is dead, and refusing to replace it would lock the person out for
     * the rest of the cooldown with no way forward.
     */
    private function liveCodeFor(string $hash): ?PhoneVerification
    {
        $cooldown = (int) config('remarket.verify.resend_cooldown', 90);

        if ($cooldown <= 0) {
            return null;
        }

        $recent = PhoneVerification::where('phone_hash', $hash)
            ->whereNull('verified_at')
            ->where('created_at', '>', now()->subSeconds($cooldown))
            ->latest('id')
            ->first();

        return $recent?->isUsable() ? $recent : null;
    }

    /** Seconds left before another send is allowed. Never zero - zero reads as „now". */
    private function retryAfter(PhoneVerification $live): int
    {
        $cooldown = (int) config('remarket.verify.resend_cooldown', 90);
        $elapsed  = (int) $live->created_at->diffInSeconds(now());

        return max(1, $cooldown - $elapsed);
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

        /*
         * NO CLAIM-WIPING HERE, AND THAT IS NOT AN OVERSIGHT.
         *
         * An earlier version cleared other accounts' unproven claims on this
         * number at verification time. It could never fire: users.phone_hash
         * has a UNIQUE index, so by the time anybody verifies a number, no
         * other row can be holding it. The claim is moved at REGISTRATION
         * instead - see Register::register() - which is the only moment two
         * accounts could want the same number.
         *
         * Dead code that implies a case which cannot happen is worse than no
         * code: the next person to read it believes duplicates are possible.
         */

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
