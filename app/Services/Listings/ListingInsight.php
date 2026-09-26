<?php

namespace App\Services\Listings;

use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\Offer;
use Illuminate\Support\Collection;

/**
 * „Why is my listing not selling?" — answered from data the site already has.
 *
 * THE NUMBERS ARE NOT THE FEATURE. „240 прегледания" tells a seller nothing
 * they can act on; the useful part is the SHAPE of the funnel, because each
 * break in it has a different cause and a different fix:
 *
 *   few views              → nobody is finding it. Title, category, photos —
 *                            or it is simply new and needs a day.
 *   views, no saves        → people look and move on. The photographs or the
 *                            asking price fail at a glance.
 *   saves, no offers       → THE STRONGEST PRICE SIGNAL THERE IS. Somebody
 *                            wanted it enough to keep it and still did not ask.
 *   offers, none accepted  → the seller's own floor is above what is being bid.
 *
 * AND IT MUST NOT BECOME A MACHINE FOR SELLING BOOSTS. Paid visibility is
 * suggested only where the diagnosis is actually „too few people have seen
 * this", and never when the price is the problem — a boost on an overpriced
 * listing spends the seller's money to show more people the same number they
 * already declined. That is worse than useless: it teaches sellers that paid
 * visibility does not work, which costs far more than the €9 earns.
 *
 * NO BENCHMARK WITHOUT A SAMPLE. „средно за този модел" over two listings is
 * not an average, it is a rumour. Below the threshold the comparison is simply
 * absent rather than softened — the same rule the price band already follows,
 * and for the same reason: a number that looks authoritative and is not gets
 * quoted back in negotiations.
 */
class ListingInsight
{
    /** Below this many comparable listings there is no comparison to make. */
    private const MIN_PEERS = 4;

    /**
     * @return array{
     *     views: int, saves: int, offers: int, pending: int, best_offer: ?int,
     *     days: int, peers: int, median_views: ?int, median_offers: ?int,
     *     price_percent: ?int, price_position: ?string,
     *     findings: list<array{tone: string, title: string, body: string, boost: bool}>
     * }
     */
    public function for(Listing $listing): array
    {
        $views  = (int) $listing->view_count;
        $saves  = Favorite::where('listing_id', $listing->id)->count();

        $offers = Offer::where('listing_id', $listing->id)->get();

        $days = max(1, (int) $listing->published_at?->diffInDays(now()) ?: 1);

        $peers = $this->peers($listing);

        $standing = $listing->part?->priceStanding((int) $listing->price_cents);

        $stats = [
            'views'   => $views,
            'saves'   => $saves,
            'offers'  => $offers->count(),
            'pending' => $offers->where('status', OfferStatus::Pending)->count(),

            // The best number anybody actually offered, accepted or not. A
            // seller deciding whether to move needs to know what the market
            // said, and „someone offered 260" is that in one figure.
            'best_offer' => $offers->isEmpty() ? null : (int) $offers->max('amount_cents'),

            'days'  => $days,
            'peers' => $peers->count(),

            'median_views'  => $peers->count() >= self::MIN_PEERS ? $this->median($peers->pluck('view_count')) : null,
            'median_offers' => $peers->count() >= self::MIN_PEERS ? $this->median($peers->pluck('offers_count')) : null,

            'price_percent'  => $standing['percent'] ?? null,
            'price_position' => $standing['position'] ?? null,
        ];

        $stats['findings'] = $this->findings($listing, $stats);

        return $stats;
    }

    /**
     * Comparable listings: the same model if the catalogue knows it, otherwise
     * the same category.
     *
     * Live listings only, and not this one. A median that includes sold
     * listings compares „how much attention did this get in three days" with
     * „how much did that get in its whole life", which flatters nothing and
     * explains nothing.
     */
    private function peers(Listing $listing): Collection
    {
        return Listing::query()
            ->where('status', ListingStatus::Active)
            ->whereKeyNot($listing->id)
            ->when(
                $listing->part_id,
                fn ($q) => $q->where('part_id', $listing->part_id),
                fn ($q) => $q->where('category', $listing->category),
            )
            ->withCount('offers')
            ->get(['id', 'view_count']);
    }

    private function median(Collection $values): ?int
    {
        $sorted = $values->map(fn ($v) => (int) $v)->sort()->values();

        if ($sorted->isEmpty()) {
            return null;
        }

        $mid = intdiv($sorted->count(), 2);

        return $sorted->count() % 2 === 1
            ? $sorted[$mid]
            : (int) round(($sorted[$mid - 1] + $sorted[$mid]) / 2);
    }

    /**
     * The diagnosis, in the order a seller should read it.
     *
     * At most three, because a list of eight suggestions is a list nobody acts
     * on. Ordered by what would change the outcome most, not by how confident
     * the site is about it.
     *
     * @return list<array{tone: string, title: string, body: string, boost: bool}>
     */
    private function findings(Listing $listing, array $s): array
    {
        $out = [];

        // Too young to diagnose. Said first, because everything below would be
        // noise and a seller told „your price is wrong" on day one will move it
        // for no reason.
        if ($s['days'] < 3 && $s['views'] < 30) {
            return [[
                'tone'  => 'neutral',
                'title' => 'Още е рано за изводи',
                'body'  => 'Обявата е от '.$s['days'].' ден(а). Дай ѝ няколко дни — повечето '
                    .'обяви събират основната си публика в първата седмица.',
                'boost' => false,
            ]];
        }

        $priceHigh = $s['price_position'] === 'high';
        $priceLow  = $s['price_position'] === 'low';

        /*
         * SAVES WITHOUT OFFERS. The clearest signal on the site, so it is first
         * whenever it is present: these people wanted the item enough to keep
         * it and still did not ask, which is almost always the number.
         */
        if ($s['saves'] >= 3 && $s['offers'] === 0) {
            $out[] = [
                'tone'  => 'warn',
                'title' => $s['saves'].' души я запазиха, но никой не предложи цена',
                'body'  => 'Това обикновено значи цена. Хората, които запазват обява, я искат '
                    .'— ако не пишат, чакат да поевтинее.'
                    .($priceHigh
                        ? ' Твоята е с '.$s['price_percent'].'% над средната за модела.'
                        : ' Пробвай да свалиш малко или да включиш оферти.'),
                'boost' => false,
            ];
        }

        /*
         * VIEWS WITHOUT SAVES. People are finding it and not keeping it — so
         * the problem is what they see, not whether they see it.
         */
        if ($s['views'] >= 40 && $s['saves'] === 0 && $s['offers'] === 0) {
            $out[] = [
                'tone'  => 'warn',
                'title' => $s['views'].' преглеждания, нито едно запазване',
                'body'  => 'Хората я намират, но не се връщат към нея. Обикновено е първата '
                    .'снимка или цената.'
                    .($priceHigh ? ' Цената ти е с '.$s['price_percent'].'% над средната за модела.' : ''),
                'boost' => false,
            ];
        }

        /*
         * PRICE ABOVE THE BAND, said plainly and only to the seller. The browse
         * card deliberately never grades a listing in public — see
         * Part::priceStanding() — but this panel is private and a seller who
         * cannot see where they stand cannot decide anything.
         */
        if ($priceHigh && $s['offers'] === 0 && ! $this->hasTitle($out, 'запазиха')) {
            $out[] = [
                'tone'  => 'warn',
                'title' => 'Цената е над средната за модела',
                'body'  => 'С '.$s['price_percent'].'% над средното. Това е нормално, ако '
                    .'състоянието или гаранцията го оправдават — но тогава си струва да го '
                    .'напишеш в описанието, защото от списъка се вижда само цената.',
                'boost' => false,
            ];
        }

        /*
         * PRICE BELOW THE BAND. A seller leaving money on the table is also a
         * failure of this screen, and saying so is what makes the panel worth
         * trusting when it says the opposite.
         */
        if ($priceLow) {
            $out[] = [
                'tone'  => 'good',
                'title' => 'Цената ти е под средната',
                'body'  => 'С '.abs((int) $s['price_percent']).'% под средната за модела. '
                    .'Ако не бързаш, имаш място да поискаш повече.',
                'boost' => false,
            ];
        }

        /*
         * OFFERS THAT WENT NOWHERE. The seller's own floor, or their own
         * answers, are where this ends — and the best number offered is the one
         * fact that decides it.
         */
        if ($s['offers'] > 0 && $s['best_offer'] !== null && $s['best_offer'] < (int) $listing->price_cents) {
            $gap = (int) round((1 - $s['best_offer'] / $listing->price_cents) * 100);

            if ($gap >= 5) {
                $out[] = [
                    'tone'  => 'neutral',
                    'title' => 'Най-високата оферта е с '.$gap.'% под искането',
                    'body'  => 'Най-доброто предложение досега е '
                        .number_format($s['best_offer'] / 100, 2, ',', ' ').' €. Ако няма да я '
                        .'задържиш още дълго, това е числото, с което пазарът вече се е съгласил.',
                    'boost' => false,
                ];
            }
        }

        /*
         * TOO FEW VIEWS — the ONLY finding that mentions paid visibility, and
         * it is last so it can never be the first thing a seller reads. It also
         * only appears when there is a real benchmark to say „fewer than" about.
         */
        if ($s['median_views'] !== null && $s['views'] < $s['median_views'] / 2 && $s['days'] >= 3) {
            $out[] = [
                'tone'  => 'neutral',
                'title' => 'По-малко преглеждания от подобните обяви',
                'body'  => 'Подобните обяви имат около '.$s['median_views'].' преглеждания, '
                    .'твоята — '.$s['views'].'. Първо провери заглавието: то трябва да съдържа '
                    .'точния модел, както го търсят хората. Повече снимки също помагат.',
                // The one place it is honest: the diagnosis IS „not enough
                // people have seen it".
                'boost' => true,
            ];
        }

        return array_slice($out, 0, 3);
    }

    private function hasTitle(array $findings, string $needle): bool
    {
        foreach ($findings as $f) {
            if (str_contains($f['title'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
