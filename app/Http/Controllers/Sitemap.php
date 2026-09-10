<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Part;
use Illuminate\Http\Response;

/**
 * What we are asking Google to read.
 *
 * An invokable controller rather than a Livewire component: this returns XML,
 * has no state and no interaction, and should keep working if every component
 * on the site is broken.
 *
 * Two rules shape the contents, and both are about not wasting the crawl:
 *
 *  - Only pages with something on them. A catalogue entry with no listings
 *    still works for anyone with the link, but submitting thousands of them
 *    teaches a crawler the site is thin, and thin sites get crawled less.
 *  - Only pages that will still exist. Listings expire; parts do not. So
 *    parts get a high priority and listings a low one, which is also the
 *    honest description of where the durable value is.
 */
class Sitemap
{
    public function __invoke(): Response
    {
        /*
         * A LAN box or a staging copy must never hand a crawler a map of
         * itself. Duplicate content under a second host is the hardest kind
         * to undo, and it would be competing with the real domain for the
         * real domain's own content.
         */
        abort_unless(config('remarket.seo.indexable', false), 404);

        $urls = [];

        $urls[] = $this->url(route('home'), now(), 'daily', '1.0');
        $urls[] = $this->url(route('browse'), now(), 'hourly', '0.9');

        foreach ($this->legalPages() as $name) {
            $urls[] = $this->url(route($name), null, 'yearly', '0.2');
        }

        Part::query()
            ->where('is_published', true)
            ->where('active_listings_count', '>=',
                (int) config('remarket.parts.sitemap_min_listings', 1))
            ->orderByDesc('active_listings_count')
            ->chunk(500, function ($parts) use (&$urls) {
                foreach ($parts as $part) {
                    $urls[] = $this->url(
                        route('part', $part),
                        $part->price_stats_at ?? $part->updated_at,
                        'daily',
                        // The pages worth ranking. Priority is relative and
                        // only meaningful within one sitemap, which is exactly
                        // the statement being made: these matter more here
                        // than the individual listings do.
                        '0.8',
                    );
                }
            });

        Listing::query()
            ->visible()
            ->orderByDesc('bumped_at')
            ->chunk(500, function ($listings) use (&$urls) {
                foreach ($listings as $listing) {
                    $urls[] = $this->url(
                        route('listing', $listing),
                        $listing->bumped_at ?? $listing->published_at,
                        'weekly',
                        '0.5',
                    );
                }
            });

        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .implode('', $urls)
            .'</urlset>';

        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** @return list<string> */
    private function legalPages(): array
    {
        return ['legal.terms', 'legal.privacy', 'legal.cookies', 'legal.contacts', 'legal.notice'];
    }

    private function url(string $loc, ?\DateTimeInterface $lastmod, string $freq, string $priority): string
    {
        $xml = "  <url>\n    <loc>".htmlspecialchars($loc, ENT_XML1)."</loc>\n";

        if ($lastmod) {
            // W3C datetime. A malformed lastmod makes the whole entry ignored,
            // which is worse than omitting it.
            $xml .= '    <lastmod>'.$lastmod->format('Y-m-d')."</lastmod>\n";
        }

        return $xml
            ."    <changefreq>{$freq}</changefreq>\n"
            ."    <priority>{$priority}</priority>\n"
            ."  </url>\n";
    }
}
