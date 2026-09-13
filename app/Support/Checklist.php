<?php

namespace App\Support;

use App\Models\Listing;

/**
 * „Какво да провериш" for a category, optionally sharpened by one listing.
 *
 * The content lives in config/checklists.php. What happens here is the part
 * that makes it worth more than a static paragraph: an item can name a facet,
 * and when the seller left that facet blank the line is rewritten as the
 * question the buyer should now be asking. A gap in the form becomes a prompt
 * instead of silence.
 */
class Checklist
{
    /**
     * The generic list, with no listing to sharpen it against.
     *
     * Used on the catalogue page, where there is a model but no single item -
     * so every line reads in its neutral form and nothing is marked missing.
     *
     * @return list<array{text: string, critical: bool, gap: bool}>
     */
    public static function forCategory(string $category): array
    {
        return array_values(array_map(
            fn (array $item) => [
                'text'     => $item['bg'],
                'critical' => (bool) ($item['critical'] ?? false),
                'gap'      => false,
            ],
            config("checklists.{$category}", []),
        ));
    }

    /**
     * The list for one actual item on sale.
     *
     * Three things can happen to an item that names a `spec`:
     *
     *   the seller filled it in   -> the normal line
     *   they left it blank        -> `missing`, flagged as a gap, if there is
     *                                one; otherwise the normal line
     *   blank and `missing` is '' -> dropped entirely, for lines that only
     *                                mean anything when the spec IS set
     *                                (ECC memory is the example: telling
     *                                somebody to check ECC support on a kit
     *                                that has no ECC is noise)
     *
     * @return list<array{text: string, critical: bool, gap: bool}>
     */
    public static function forListing(Listing $listing): array
    {
        $out = [];

        foreach (config("checklists.{$listing->category}", []) as $item) {
            $spec = $item['spec'] ?? null;

            if ($spec === null) {
                $out[] = self::line($item['bg'], $item);

                continue;
            }

            if (self::hasSpec($listing, $spec)) {
                $out[] = self::line($item['bg'], $item);

                continue;
            }

            // Blank. An explicitly empty `missing` means "say nothing here".
            if (array_key_exists('missing', $item) && $item['missing'] === '') {
                continue;
            }

            $out[] = self::line($item['missing'] ?? $item['bg'], $item, gap: isset($item['missing']));
        }

        return $out;
    }

    /**
     * Is this spec answered for this listing?
     *
     * Listing-scoped specs live on the listing; part-scoped ones come from the
     * catalogue entry, and a buyer cannot tell which is which - both are just
     * "is this question answered on the page in front of me". Checking only
     * one of the two would mark half the answered facets as gaps.
     */
    private static function hasSpec(Listing $listing, string $key): bool
    {
        if (filled($listing->specs[$key] ?? null)) {
            return true;
        }

        return filled($listing->part?->specs[$key] ?? null);
    }

    /** @return array{text: string, critical: bool, gap: bool} */
    private static function line(string $text, array $item, bool $gap = false): array
    {
        return [
            'text'     => $text,
            // A gap is worth reading even when the underlying item was not
            // marked urgent: an unanswered question is its own signal.
            'critical' => (bool) ($item['critical'] ?? false) || $gap,
            'gap'      => $gap,
        ];
    }
}
