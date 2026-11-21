<?php

namespace App\Support\Market;

/**
 * Turns an OLX.bg search page into `[title, price]` pairs - and nothing else.
 *
 * WHAT IT KEEPS. A title (used once, to decide whether the ad is about the right
 * card) and a price in euro cents. The ad's address is kept only so the same ad
 * seen on two result pages is counted once within a run. No seller, no
 * location, no photo, no description - the price guide needs a number, and the
 * less of other people's ads this code handles, the less there is to get wrong.
 *
 * WHY TWO STRATEGIES. The markup of a site we do not control changes without
 * notice, and a scraper that dies silently is worse than none: the guide would
 * go on showing a number that is weeks old. So the parser reads both the
 * structured data and the result-card markup, keeps whichever found more ads,
 * and reports WHICH one worked - `remarket:olx-probe` prints it - so that
 * "nothing parsed" is a loud, early, specific failure rather than a quiet gap.
 *
 * Plain PHP with no framework dependency, on purpose: it is the part most
 * likely to need changing, and it should be testable against a saved page
 * without booting the application.
 */
final class OlxParser
{
    /** The lev was fixed against the euro; some ads still quote it. */
    private const BGN_PER_EUR = 1.95583;

    /**
     * @return array{cards: list<array{title: string, cents: ?int, key: string}>, strategy: string}
     */
    public static function parse(string $html): array
    {
        // Both are tried and the fuller one wins: a page can carry structured
        // data for only a few promoted ads while the card list holds the rest,
        // and taking the first strategy that finds anything would quietly
        // price a model from five ads instead of forty.
        $best = ['cards' => [], 'strategy' => 'none'];

        foreach (['json-ld' => 'fromJsonLd', 'cards' => 'fromCards'] as $name => $method) {
            $cards = self::$method($html);

            if (count($cards) > count($best['cards'])) {
                $best = ['cards' => $cards, 'strategy' => $name];
            }
        }

        return $best;
    }

    /**
     * „782 €", „1 250 €", „1.250 €", „249,90 €", „1 500 лв." -> euro cents.
     * Null for „По договаряне", free, or anything that is not a price.
     */
    public static function parsePrice(string $text): ?int
    {
        // Non-breaking and thin spaces are how the site separates thousands.
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);

        if (! preg_match('/(\d[\d .]*(?:,\d{1,2})?)\s*(€|eur\b|лв\.?|лева|bgn\b)/iu', $text, $m)) {
            return null;
        }

        $number = trim($m[1]);
        $number = rtrim($number, '. ');

        // „1.250" is a thousand-separated 1250; „249.90" is a decimal.
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', str_replace(' ', '', $number))) {
            $number = str_replace('.', '', $number);
        }

        $number = str_replace([' ', ','], ['', '.'], $number);

        if (! is_numeric($number) || (float) $number <= 0) {
            return null;
        }

        $amount = (float) $number;

        if (preg_match('/лв|лева|bgn/iu', $m[2])) {
            $amount /= self::BGN_PER_EUR;
        }

        return (int) round($amount * 100);
    }

    // --- strategy 1: structured data -------------------------------------

    /** @return list<array{title: string, cents: ?int, key: string}> */
    private static function fromJsonLd(string $html): array
    {
        if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
            return [];
        }

        $found = [];

        foreach ($blocks[1] as $raw) {
            $data = json_decode(html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5), true);

            if (! is_array($data)) {
                continue;
            }

            self::walkJsonLd($data, $found);
        }

        return array_values($found);
    }

    /** @param array<string, array{title: string, cents: ?int, key: string}> $found */
    private static function walkJsonLd(array $node, array &$found): void
    {
        $offer = $node['offers'] ?? null;

        if (isset($node['name']) && is_array($offer)) {
            $offer = array_is_list($offer) ? ($offer[0] ?? []) : $offer;
            $price = $offer['price'] ?? null;
            $cur   = strtoupper((string) ($offer['priceCurrency'] ?? 'EUR'));

            $cents = null;
            if (is_numeric($price) && (float) $price > 0) {
                $amount = (float) $price / ($cur === 'BGN' ? self::BGN_PER_EUR : 1.0);
                $cents  = (int) round($amount * 100);
            }

            $key = (string) ($node['url'] ?? $offer['url'] ?? $node['name'].'|'.$cents);

            $found[$key] = ['title' => (string) $node['name'], 'cents' => $cents, 'key' => $key];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                self::walkJsonLd($child, $found);
            }
        }
    }

    // --- strategy 2: result cards ----------------------------------------

    /** @return list<array{title: string, cents: ?int, key: string}> */
    private static function fromCards(string $html): array
    {
        if (! class_exists(\DOMDocument::class) || trim($html) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $doc      = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query("//*[@data-cy='l-card' or @data-testid='l-card']");

        if ($nodes === false || $nodes->length === 0) {
            return [];
        }

        $cards = [];

        foreach ($nodes as $node) {
            $title = self::first($xpath, $node, ".//h4 | .//h6 | .//h3 | .//h2");

            $link = $xpath->query('.//a[@href]', $node)->item(0);
            $href = $link instanceof \DOMElement ? $link->getAttribute('href') : '';

            if ($title === '' && $link) {
                $title = trim(preg_replace('/\s+/u', ' ', $link->textContent));
            }

            $priceText = self::first($xpath, $node, ".//*[@data-testid='ad-price']");
            if ($priceText === '') {
                $priceText = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            }

            if ($title === '') {
                continue;
            }

            $cents = self::parsePrice($priceText);
            $key   = $href !== '' ? $href : $title.'|'.$cents;

            $cards[$key] = ['title' => $title, 'cents' => $cents, 'key' => $key];
        }

        return array_values($cards);
    }

    private static function first(\DOMXPath $xpath, \DOMNode $context, string $query): string
    {
        $found = $xpath->query($query, $context);

        return $found && $found->length > 0
            ? trim(preg_replace('/\s+/u', ' ', $found->item(0)->textContent))
            : '';
    }
}
