<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile.
 *
 * Chosen over reCAPTCHA because it sets no cookies and does no cross-site
 * tracking, which means it needs no consent banner entry under ЗЕС - and
 * because it is free at any volume.
 *
 * Unconfigured, it disables itself completely. That is deliberate: local
 * development and the LAN test box have no keys, and a challenge that cannot be
 * solved there would block the exact flows most in need of testing. The cost is
 * that a production deployment with an empty key silently has no bot defence,
 * so `remarket:doctor` should say so out loud once it exists.
 */
class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function enabled(): bool
    {
        return filled(config('remarket.turnstile.site_key'))
            && filled(config('remarket.turnstile.secret_key'));
    }

    public static function siteKey(): ?string
    {
        return config('remarket.turnstile.site_key');
    }

    /**
     * Verify a token with Cloudflare.
     *
     * A network failure returns TRUE. This is the one place where failing open
     * is right: if Cloudflare is unreachable, refusing every signup and every
     * offer turns their outage into ours, and the downside is a few minutes of
     * unfiltered traffic against the rest of the layers - phone verification,
     * the moderation queue, rate limits - which are all still standing.
     */
    public static function verify(?string $token, ?string $ip = null): bool
    {
        if (! self::enabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::timeout(8)->asForm()->post(self::VERIFY_URL, array_filter([
                'secret'   => config('remarket.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]))->json();
        } catch (\Throwable $e) {
            Log::warning('[turnstile] verification unreachable: '.$e->getMessage());

            return true;
        }

        if (($response['success'] ?? false) !== true) {
            Log::info('[turnstile] rejected', ['errors' => $response['error-codes'] ?? []]);

            return false;
        }

        return true;
    }
}
