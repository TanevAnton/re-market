<?php

namespace App\Support;

use RuntimeException;

/**
 * Normalising, checking and hashing a serial number or an IMEI.
 *
 * THE HASH IS AN HMAC, NOT A DIGEST, and the difference is the whole security
 * argument. An IMEI is fifteen digits of which the first eight are a public
 * type-allocation code from a published list and the last is a checksum — so a
 * plain SHA-256 of an IMEI is recoverable by brute force in seconds on a
 * laptop. Peppered with a secret that lives in `.env` and never in the
 * database, the same dump is inert.
 *
 * THE PEPPER CAN NEVER CHANGE. Every stored hash was computed with it, so
 * rotating it does not invalidate the register — it silently empties it while
 * leaving every row in place. Nothing would fail; matches would simply stop
 * happening. `php artisan remarket:doctor` names this, and `enabled()` below is
 * what the screens use to hide the feature rather than store unmatchable rows.
 */
class ItemIdentifier
{
    public const SERIAL = 'serial';
    public const IMEI   = 'imei';

    /** Without a pepper the feature is off — see the class note. */
    public static function enabled(): bool
    {
        return filled(config('remarket.identifiers.pepper'));
    }

    /** @return array<string, string> kind => label, for a form */
    public static function kinds(): array
    {
        return [
            self::SERIAL => 'Сериен номер',
            self::IMEI   => 'IMEI',
        ];
    }

    /**
     * Strip everything a human adds and nothing that identifies the object.
     *
     * Uppercased, with spaces, dashes, dots and slashes removed — the same
     * serial written „SN: 1234-5678" and „12345678" has to hash identically or
     * the register matches nothing. IMEIs keep digits only, because an IMEI
     * with a letter in it is a typo rather than an IMEI.
     */
    public static function normalise(string $kind, string $value): string
    {
        $value = mb_strtoupper(trim($value));

        return $kind === self::IMEI
            ? preg_replace('/\D+/', '', $value)
            : preg_replace('/[^A-Z0-9]+/', '', $value);
    }

    /**
     * Is this plausibly the thing it claims to be?
     *
     * THE IMEI CHECKSUM IS THE USEFUL HALF. Fifteen digits ending in a Luhn
     * check digit means a typo is caught here rather than becoming a row that
     * can never match anything, and an invented number fails outright. A serial
     * has no checksum anywhere in the industry, so all that can be asked of one
     * is that it is long enough to identify something — four characters is a
     * batch code, not a serial.
     */
    public static function valid(string $kind, string $value): bool
    {
        $value = self::normalise($kind, $value);

        if ($kind === self::IMEI) {
            return strlen($value) === 15 && self::luhn($value);
        }

        return strlen($value) >= 6 && strlen($value) <= 64;
    }

    /** The reason it was refused, for a form. Null when it is fine. */
    public static function problem(string $kind, string $value): ?string
    {
        if (self::valid($kind, $value)) {
            return null;
        }

        if ($kind === self::IMEI) {
            $digits = strlen(self::normalise($kind, $value));

            return $digits === 15
                // Said plainly: an IMEI that fails its own checksum is almost
                // always one digit wrong, and „invalid" sends people looking in
                // the wrong place.
                ? 'IMEI-ът не минава проверката за контролна цифра — провери дали някоя цифра не е разменена.'
                : 'IMEI-ът е 15 цифри. Набери *#06# на телефона, за да го видиш.';
        }

        return 'Серийният номер изглежда твърде къс — въведи го както е изписан на устройството.';
    }

    /**
     * The stored value.
     *
     * @throws RuntimeException when there is no pepper, rather than quietly
     *                          writing a row nothing will ever match
     */
    public static function hash(string $kind, string $value): string
    {
        $pepper = (string) config('remarket.identifiers.pepper');

        if ($pepper === '') {
            throw new RuntimeException(
                'IDENTIFIER_PEPPER is not set — refusing to store an identifier that could never be matched.'
            );
        }

        // The kind is inside the message, so a serial that happens to read like
        // an IMEI does not collide with one.
        return hash_hmac('sha256', $kind.':'.self::normalise($kind, $value), $pepper);
    }

    /** The four characters shown back to a human. See the migration. */
    public static function last4(string $kind, string $value): string
    {
        return substr(self::normalise($kind, $value), -4);
    }

    /** The Luhn checksum every IMEI carries in its fifteenth digit. */
    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $len = strlen($digits);

        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$len - 1 - $i];

            if ($i % 2 === 1) {
                $d *= 2;

                if ($d > 9) {
                    $d -= 9;
                }
            }

            $sum += $d;
        }

        return $sum % 10 === 0;
    }
}
