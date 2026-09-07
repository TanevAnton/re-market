<?php

namespace App\Services\Verification;

/**
 * Bulgarian mobile numbers, normalised to E.164.
 *
 * People type 0888123456, 359888123456, +359 88 812 34 56 and 88 812 3456,
 * all meaning the same number. If we do not fold those into one canonical
 * form, the same person registers four times and the phone-uniqueness rule -
 * which is most of our anti-bot defence - does nothing.
 */
final class PhoneNumber
{
    /** Mobile prefixes in use in Bulgaria: A1, Vivacom, Yettel and MVNOs. */
    private const MOBILE = '/^(8[7-9]|9[89])\d{7}$/';

    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if ($digits === '') {
            return null;
        }

        // 00359... international prefix
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $national = match (true) {
            str_starts_with($digits, '359') => substr($digits, 3),
            str_starts_with($digits, '0')   => substr($digits, 1),
            default                         => $digits,
        };

        return preg_match(self::MOBILE, $national) ? '+359'.$national : null;
    }

    public static function isValid(string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /** For display: +359 88 812 3456 */
    public static function format(string $e164): string
    {
        $n = substr($e164, 4);

        return sprintf('+359 %s %s %s', substr($n, 0, 2), substr($n, 2, 3), substr($n, 5));
    }

    public static function last4(string $e164): string
    {
        return substr($e164, -4);
    }
}
