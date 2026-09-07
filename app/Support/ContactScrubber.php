<?php

namespace App\Support;

/**
 * Removes contact details from a message body.
 *
 * The point is not censorship. A marketplace where the first move is always
 * "пиши ми на вайбър" is a marketplace with no record of anything: no offer,
 * no deal, no reputation, and no way to tell a scam from a misunderstanding
 * afterwards. Keeping the conversation here until the two sides have actually
 * agreed something is what makes the rest of the system mean anything.
 *
 * After they HAVE agreed - once a deal exists - the platform gets out of the
 * way and lets them swap details freely. See Thread::allowsContactExchange().
 *
 * This is a speed bump, not a wall. Someone determined will spell a number in
 * words and get through. That is fine: the flag it raises is the useful part,
 * because a seller doing it on every listing is a pattern worth seeing.
 */
class ContactScrubber
{
    private const MASK = '[скрито]';

    /**
     * Anything with eight or more digits, however the writer spaced it out.
     * A Bulgarian mobile is ten digits; prices in this app top out at seven
     * ("1 250,00"), so the threshold does not eat them.
     */
    private const PHONE = '/\+?\d(?:[\s\-.()\/]{0,3}\d){7,}/u';

    private const EMAIL = '/[\p{L}\d._%+-]+\s*(?:@|\(at\)|\[at\]|\sат\s)\s*[\p{L}\d.-]+\.[\p{L}]{2,}/iu';

    private const URL = '/\b(?:https?:\/\/|www\.)\S+/iu';

    /** @handles, which is how Telegram and Instagram are shared. */
    private const HANDLE = '/(?<![\p{L}\d])@[\p{L}\d_]{3,}/u';

    /**
     * Naming an app is not itself contact information, so these are flagged
     * rather than removed - "приемам преглед и тест с Еконт" is a normal
     * sentence and stripping it would make the product worse.
     */
    private const APPS = '/\b(viber|telegram|whatsapp|signal|messenger|вайбър|вибер|телеграм|скайп)\b/iu';

    private const PRICE_TALK = '/(\d[\d\s.,]*)\s*(лв|лева|bgn|eur|евро|€)|\b(цена|цената|отстъпка|намаление)\b/iu';

    /**
     * @return array{clean: string, had_contact_info: bool, had_price_talk: bool}
     */
    public static function scrub(string $body, bool $allowContact = false): array
    {
        $clean = $body;

        // Order matters: emails first, because the local part of an address
        // can contain a digit run that the phone pattern would otherwise eat,
        // leaving a mangled half-address behind instead of a clean mask.
        $clean = preg_replace(self::EMAIL, self::MASK, $clean) ?? $clean;
        $clean = preg_replace(self::URL, self::MASK, $clean) ?? $clean;
        $clean = preg_replace(self::HANDLE, self::MASK, $clean) ?? $clean;
        $clean = preg_replace(self::PHONE, self::MASK, $clean) ?? $clean;

        $hadContact = $clean !== $body || preg_match(self::APPS, $body) === 1;
        $hadPrice   = preg_match(self::PRICE_TALK, $body) === 1;

        return [
            // Once a deal is agreed the two of them need to arrange a handover,
            // and the platform storing an unreadable version of that helps
            // nobody. The flag is still recorded either way.
            'clean'            => $allowContact ? $body : self::tidy($clean),
            'had_contact_info' => $hadContact,
            'had_price_talk'   => $hadPrice,
        ];
    }

    /** Collapse "[скрито] [скрито]" and stray whitespace left by the masks. */
    private static function tidy(string $s): string
    {
        $s = preg_replace('/(?:'.preg_quote(self::MASK, '/').'[\s,;.-]*){2,}/u', self::MASK.' ', $s) ?? $s;

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $s) ?? $s);
    }
}
