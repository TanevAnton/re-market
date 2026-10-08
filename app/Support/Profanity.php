<?php

namespace App\Support;

use RuntimeException;

/**
 * „Има ли нещо тук, което човек трябва да види."
 *
 * THE ONLY HOME FOR THE QUESTION. The listing screener asks it about a title
 * and a description; registration asks it about a username. Two copies of a
 * word list disagree within a month, and the half that is not maintained is
 * the half that lets something through.
 *
 * WHOLE WORDS, NEVER SUBSTRINGS. The pattern wraps every term in lookarounds
 * for „not a letter or digit" rather than using \b, which is ASCII-minded and
 * would cut Cyrillic words in the wrong places. See config/profanity.php for
 * why substring matching is not merely imprecise here but actively harmful —
 * „курс", „курсор" and, on this site above all, „педали".
 *
 * NOTHING HERE DECIDES ANYTHING. It answers a question; the caller queues a
 * listing for a person, or refuses a username at signup. An automated takedown
 * on a word match would be a restrictive decision under DSA Art. 17 and would
 * need a statement of reasons that a regular expression cannot write.
 */
class Profanity
{
    /** Built once per process; the list does not change mid-request. */
    private static ?string $pattern = null;

    /**
     * Which listed terms appear in the given texts.
     *
     * Returns the terms rather than a boolean, because the moderator needs to
     * know WHICH word held the listing. „Съдържа нецензурен език" with nothing
     * else is a queue entry somebody has to re-read the whole description to
     * act on, and after the tenth one they stop reading.
     *
     * @return list<string> matched terms, lowercased, without duplicates
     */
    public static function found(string ...$texts): array
    {
        if (! config('profanity.enabled', true)) {
            return [];
        }

        $pattern = self::pattern();

        if ($pattern === '') {
            return [];
        }

        $hits = [];

        foreach ($texts as $text) {
            // Both scripts, because the list is half Cyrillic and half Latin
            // and one substitution table cannot undo obfuscation in both.
            foreach (self::variants($text) as $candidate) {
                if (preg_match_all($pattern, $candidate, $m)) {
                    foreach ($m[0] as $hit) {
                        $hits[mb_strtolower($hit)] = true;
                    }
                }
            }
        }

        return array_keys($hits);
    }

    public static function clean(string ...$texts): bool
    {
        return self::found(...$texts) === [];
    }

    /**
     * @return list<string> the text as the matcher should see it, once per script
     */
    private static function variants(string $text): array
    {
        // Zero-width characters are the cheapest evasion there is and the only
        // one worth undoing unconditionally: nothing legitimate on this site
        // contains a zero-width joiner.
        $text = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text) ?? $text;

        $text = mb_strtolower($text);

        // „кууурва" and „курва" are the same word typed by the same person.
        // Collapsed to a single letter rather than two, because Bulgarian has
        // almost no genuine doubles and a seller writing „ммм" means nothing by
        // it either way.
        $text = preg_replace('/(\p{L})\1{2,}/u', '$1', $text) ?? $text;

        return [
            strtr($text, (array) config('profanity.digit_map_cyrillic', [])),
            strtr($text, (array) config('profanity.digit_map_latin', [])),
        ];
    }

    private static function pattern(): string
    {
        if (self::$pattern !== null) {
            return self::$pattern;
        }

        $hold  = array_map('mb_strtolower', (array) config('profanity.hold', []));
        $never = array_map('mb_strtolower', array_keys((array) config('profanity.never', [])));

        /*
         * The `never` list is a guard rail with teeth, not a comment.
         *
         * „педали" on this site means racing-sim pedals, and the edit that adds
         * it back will look entirely reasonable to whoever makes it six months
         * from now. Failing loudly at boot is the only version of that warning
         * anybody reads.
         */
        if ($clash = array_intersect($hold, $never)) {
            throw new RuntimeException(
                'config/profanity.php lists '.implode(', ', $clash).' in both `hold` and `never`. '
                .'The `never` entry explains why it must not be matched — read it before removing it.',
            );
        }

        $terms = array_filter(array_unique($hold), static fn ($t) => $t !== '');

        if ($terms === []) {
            return self::$pattern = '';
        }

        // Longest first so the matched term reported to the moderator is the
        // most specific one present, not whichever happened to be listed first.
        usort($terms, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $alternation = implode('|', array_map(
            static fn ($t) => preg_quote($t, '/'),
            $terms,
        ));

        /*
         * Lookarounds rather than \b.
         *
         * \b is defined against a word-character class that is ASCII unless the
         * pattern is compiled with Unicode properties, so on Cyrillic it finds
         * boundaries in the middle of words — which is precisely the failure
         * this whole class exists to avoid. „Not a letter and not a digit" on
         * both sides says what is actually meant.
         */
        return self::$pattern = '/(?<![\p{L}\p{N}])(?:'.$alternation.')(?![\p{L}\p{N}])/ui';
    }

    /** Tests rebuild config between cases; the cached pattern must not outlive it. */
    public static function flush(): void
    {
        self::$pattern = null;
    }
}
