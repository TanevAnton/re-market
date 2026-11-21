<?php

namespace App\Support\Market;

/**
 * Is this ad about THIS graphics card?
 *
 * A search for „rtx 3060" on a general classifieds site returns the 3060, the
 * 3060 Ti, laptops with a 3060 in them, whole computers, boxes, waterblocks and
 * wanted ads. Averaging that would put a figure on the screen that is wrong in a
 * way nobody could see, so the match is deliberately strict and the default is
 * to leave an ad out. Fewer, right ads beat more, mixed ones: the sample size
 * is reported, and a model that cannot reach the minimum simply gets no guide.
 *
 * Four things must hold: the chipset appears as a whole token and is not
 * followed by Ti/Super/XT and the like unless the part itself carries it; the
 * memory size, when the title states one, is the part's; where the same chipset
 * exists in several sizes a title that states none is skipped; and none of the
 * exclusion patterns (laptops, bundles, broken, wanted) hits.
 */
final class TitleMatcher
{
    /** Words that turn „3060" into a different card when they follow it. */
    private const SUFFIXES = ['ti', 'super', 'xt', 'xtx', 'gre', 'x', 'plus'];

    private const SIZES = [2, 3, 4, 5, 6, 8, 10, 11, 12, 16, 20, 24, 32];

    /**
     * @param  list<string>  $exclude  regular expressions, matched case-insensitively against the title
     */
    public static function matches(
        string $chipset,
        ?int $vramGb,
        string $title,
        bool $sizeIsAmbiguous,
        array $exclude = [],
    ): bool {
        $t = self::normalise($title);

        foreach ($exclude as $pattern) {
            if (@preg_match('/'.$pattern.'/iu', $t) === 1) {
                return false;
            }
        }

        $tokens = preg_split('/\s+/', mb_strtolower(trim($chipset)), -1, PREG_SPLIT_NO_EMPTY);

        if (! $tokens) {
            return false;
        }

        $core = implode('[\s\-]?', array_map(fn ($tok) => preg_quote($tok, '/'), $tokens));

        if (! preg_match('/(?<![a-z0-9])'.$core.'(?![a-z0-9])(?<tail>[\s\-]*[a-z]+)?/iu', $t, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        // The word right after the chipset must not turn it into another card -
        // unless the chipset we are matching ends with that very word.
        $next = isset($m['tail']) ? mb_strtolower(trim($m['tail'][0], " -\t")) : '';

        if ($next !== '' && in_array($next, self::SUFFIXES, true) && ! in_array(end($tokens), self::SUFFIXES, true)) {
            return false;
        }

        $mentioned = self::sizesIn($t);

        if ($vramGb !== null && $mentioned !== []) {
            return in_array($vramGb, $mentioned, true);
        }

        return ! $sizeIsAmbiguous;
    }

    /** Lower case, Cyrillic spellings of the brand prefixes turned back to Latin. */
    private static function normalise(string $title): string
    {
        $t = mb_strtolower($title);

        return (string) preg_replace(
            ['/(?<![\p{L}])ртх(?=\s*\d)/u', '/(?<![\p{L}])гтх(?=\s*\d)/u', '/(?<![\p{L}])рх(?=\s*\d)/u'],
            ['rtx', 'gtx', 'rx'],
            $t,
        );
    }

    /** @return list<int> memory sizes the title states, e.g. „12GB", „12 гб" */
    private static function sizesIn(string $title): array
    {
        preg_match_all('/(?<!\d)(\d{1,2})\s?(?:gb|гб)(?![a-z])/iu', $title, $m);

        return array_values(array_unique(array_filter(
            array_map('intval', $m[1]),
            fn ($n) => in_array($n, self::SIZES, true),
        )));
    }
}
