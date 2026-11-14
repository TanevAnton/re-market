<?php

namespace App\Support;

/**
 * The link from this marketplace to RE-Tech's buyback.
 *
 * WHAT THIS IS, PLAINLY. RE-Tech is a shop run by the same company that runs
 * this site, and it buys used graphics cards outright. Somebody who would
 * rather have the money now than wait for a buyer is better served by being
 * told that than by not being told - so the offer is shown at the points where
 * a seller is deciding what to do with a card.
 *
 * WHY IT IS ONE CLASS AND ONE PARTIAL. The offer appears in four places, and
 * the thing that must never differ between them is the disclosure: that the
 * two are the same company. A marketplace whose operator also buys from its
 * users is fine when it says so and a quiet conflict of interest when it does
 * not, and the sentence that says so is in the partial, where no placement can
 * be added without it.
 *
 * OFF UNTIL IT HAS AN ADDRESS. With RETECH_BUYBACK_URL empty nothing renders
 * anywhere - no banner pointing at a page that does not exist yet, and one
 * line in `.env` to take the whole thing down again.
 *
 * NOTHING ABOUT THE VISITOR LEAVES WITH THE CLICK. The link carries where on
 * this site it was pressed and nothing else - no model, no account, no id.
 */
class RetechOffer
{
    /** The places the offer is drawn. The tag on the link says which one was pressed. */
    public const PLACEMENTS = ['valuation', 'listing-form', 'home', 'gpu-page'];

    public static function enabled(): bool
    {
        return self::baseUrl() !== null;
    }

    public static function name(): string
    {
        return (string) config('remarket.retech.name', 'RE-Tech');
    }

    /**
     * Whether the offer belongs beside this category.
     *
     * Graphics cards only, today, because that is what is being bought. The
     * list is config so that widening it is an edit somebody makes on purpose.
     */
    public static function appliesTo(?string $category): bool
    {
        return self::enabled()
            && in_array($category, (array) config('remarket.retech.buyback_categories', ['gpu']), true);
    }

    /**
     * The address to send somebody to, tagged with where they pressed it.
     *
     * The tags are the ordinary campaign parameters every analytics tool
     * reads, so „how many people came from the price check page" is a report
     * that already exists on the other side rather than something to build.
     */
    public static function url(string $placement): ?string
    {
        $base = self::baseUrl();

        if ($base === null) {
            return null;
        }

        // A fragment has to stay at the very end, after the query string.
        [$address, $fragment] = array_pad(explode('#', $base, 2), 2, null);

        $tags = http_build_query([
            // This site's own host rather than its name typed in: the name
            // lives in APP_NAME and nowhere else.
            'utm_source'   => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'marketplace',
            'utm_medium'   => 'referral',
            'utm_campaign' => 'buyback',
            'utm_content'  => in_array($placement, self::PLACEMENTS, true) ? $placement : 'other',
        ]);

        return $address
            .(str_contains($address, '?') ? '&' : '?').$tags
            .($fragment !== null ? '#'.$fragment : '');
    }

    /**
     * The configured address, or null if there is none worth linking to.
     *
     * https only. The value is typed into `.env` by a person, and anything
     * else there - a typo, a `javascript:` pasted from the wrong place - should
     * switch the offer off rather than become a link on four pages.
     */
    private static function baseUrl(): ?string
    {
        $url = trim((string) config('remarket.retech.buyback_url'));

        return preg_match('#^https://[^\s/]+#i', $url) === 1 ? $url : null;
    }
}
