<?php

namespace App\Models;

use App\Support\Cyrillic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A canonical piece of hardware. Listings point at it, which is what turns
 * "видеокарта 4070 спешно" into a row you can filter, price and rank.
 */
class Part extends Model
{
    use HasFactory;

    protected $fillable = [
        'category', 'manufacturer', 'model', 'variant', 'slug',
        'launch_year', 'msrp_cents', 'specs', 'aliases', 'image_path', 'is_published',
    ];

    protected function casts(): array
    {
        return [
            'specs'        => 'array',
            'aliases'      => 'array',
            'is_published' => 'boolean',

            // Without this it comes back as a string and every ->lt() /
            // ->diffForHumans() on the landing page is a fatal - which is
            // exactly what the price-band staleness check does.
            'price_stats_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /** One row per day, written nightly. See the migration for why it exists. */
    public function pricePoints(): HasMany
    {
        return $this->hasMany(PartPricePoint::class);
    }

    /**
     * The recorded band, oldest first.
     *
     * Gaps are real and are left in: a day with no row is a day this model had
     * too few live listings to price, and closing the gap by interpolation
     * would be drawing a price nobody asked.
     *
     * @return \Illuminate\Support\Collection<int, PartPricePoint>
     */
    public function priceHistory(?int $days = null): \Illuminate\Support\Collection
    {
        $days ??= (int) config('remarket.parts.history_days', 90);

        return $this->pricePoints()
            ->where('captured_on', '>=', now()->subDays($days)->toDateString())
            ->orderBy('captured_on')
            ->get();
    }

    /**
     * Which way the price has moved, or null when the series cannot support an
     * answer.
     *
     * Two separate refusals, and both matter more than having something to
     * show. Too FEW points and the "trend" is two numbers with a line between
     * them. Too SHORT a span and it is Tuesday against Thursday - on a market
     * where one seller relisting moves a thin median several percent, that is
     * noise reported as news, and a seller who drops their price because of it
     * has been actively misled.
     *
     * `direction` is flat inside the same 3% the price-drop notification uses,
     * because the threshold for "worth telling somebody" should not depend on
     * which screen they are looking at.
     *
     * @return array{from: int, to: int, percent: int, direction: string,
     *     days: int, points: int, sample: int}|null
     */
    public function priceTrend(?int $days = null): ?array
    {
        $history = $this->priceHistory($days);

        $minPoints = (int) config('remarket.parts.history_min_points', 4);
        $minDays   = (int) config('remarket.parts.history_min_days', 14);

        if ($history->count() < $minPoints) {
            return null;
        }

        $first = $history->first();
        $last  = $history->last();
        $span  = (int) $first->captured_on->diffInDays($last->captured_on);

        if ($span < $minDays || $first->median_cents <= 0) {
            return null;
        }

        $percent = (int) round(
            ($last->median_cents - $first->median_cents) / $first->median_cents * 100
        );

        $flat = (int) config('remarket.parts.history_flat_percent', 3);

        return [
            'from'      => (int) $first->median_cents,
            'to'        => (int) $last->median_cents,
            'percent'   => $percent,
            'direction' => match (true) {
                $percent <= -$flat => 'down',
                $percent >= $flat  => 'up',
                default            => 'flat',
            },
            'days'   => $span,
            'points' => $history->count(),
            // The thinnest day in the window. A series built from three
            // listings a day is a different claim from one built from forty,
            // and the caption is the only place that can still say so.
            'sample' => (int) $history->min('sample_size'),
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->manufacturer} {$this->model} {$this->variant}");
    }

    public function spec(string $key): mixed
    {
        return $this->specs[$key] ?? null;
    }

    /**
     * One spec value, as a string a view can print.
     *
     * THIS EXISTS BECAUSE `{{ $v }}` IS NOT TOTAL OVER A SPEC VALUE. Four specs
     * in the schema are `multiselect` and hold arrays — `cooler.sockets`,
     * `case.form_factor`, `gpu.outputs`, `macbook.ports` — and Blade's echo runs
     * htmlspecialchars(), which fatals on an array rather than degrading. Two
     * views were slicing the first few specs off a part and printing them raw,
     * so a browse page or a wizard step containing one cooler took the whole
     * page down with a 500.
     *
     * It was invisible for a long time because whether it fires depends on
     * where the array-valued spec happens to sit in its category's schema: the
     * card prints the first three, and `sockets` is the second cooler spec
     * while `outputs` is the ninth GPU one. Adding a spec at the top of a
     * category would have been enough to break a page that had always worked.
     *
     * So the rule lives here, once, rather than as a ternary repeated in every
     * view that touches a spec — which is how the three of them managed to
     * disagree about arrays in the first place.
     */
    public static function specLabel(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'да' : 'не';
        }

        if (is_array($value)) {
            return implode(' · ', array_map(
                static fn ($v) => self::specLabel($v),
                $value,
            ));
        }

        return $value === null ? '' : (string) $value;
    }

    /** The schema for this part's category, from config/catalog.php. */
    public function specSchema(): array
    {
        return config("catalog.categories.{$this->category}.specs", []);
    }

    // --- queries ---------------------------------------------------------

    /**
     * Filter on a typed attribute inside the jsonb blob. Backed by the GIN
     * index, so "12 GB cards shorter than 300 mm" stays fast.
     */
    public function scopeWhereSpec(Builder $q, string $key, string $op, mixed $value): Builder
    {
        return $q->whereRaw("(specs->>?)::numeric {$op} ?", [$key, $value]);
    }

    /**
     * People type the letter o where a zero belongs - "rtx 4o7o", "78oox3d".
     * Trigram similarity cannot rescue that (measured: 0.238, below the 0.3
     * threshold, and it scores a 4070 and a 4090 identically). Normalising the
     * homoglyph first turns a hopeless fuzzy match into an exact alias hit.
     *
     * Only o -> 0, and only in tokens that already contain a digit. Mapping
     * l/i -> 1 would turn "i5-13600k" into "15-13600k", and s -> 5 would turn
     * "4070s" into "40705".
     */
    public static function normalizeQuery(string $term): string
    {
        $tokens = preg_split('/\s+/', mb_strtolower(trim($term))) ?: [];

        return implode(' ', array_map(
            fn (string $t): string => preg_match('/\d/', $t)
                ? strtr($t, ['o' => '0', 'о' => '0'])
                : $t,
            $tokens
        ));
    }

    /**
     * Every spelling worth trying: as typed, with o/0 corrected, and folded
     * back to Latin.
     *
     * THE LATIN FOLD IS WHAT MAKES A CYRILLIC QUERY WORK AT ALL BEYOND AN EXACT
     * ALIAS. `model` is stored in Latin, and trigram similarity cannot bridge
     * scripts — „макбук еър" and "MacBook Air" share not one character, so
     * word_similarity scores them at zero and ILIKE finds nothing. Without this
     * fold, a Cyrillic search only ever matches an alias somebody seeded by
     * hand, which means it works for „айфон 13 про" (a seeded tail) and fails
     * for „макбук еър" and for the bare word „айпад" — the two shapes a real
     * buyer types most, because they are how you start a search before you know
     * which model you want.
     *
     * Cyrillic::toLatin is the reverse of the table the seeders already use, so
     * the two directions cannot drift apart.
     */
    public static function queryVariants(string $term): array
    {
        $raw   = mb_strtolower(trim($term));
        $latin = Cyrillic::toLatin($raw);

        return array_values(array_unique(array_filter([
            $raw,
            self::normalizeQuery($raw),
            $latin,
            self::normalizeQuery($latin),
        ])));
    }

    /** Exact alias hit - Cyrillic, Latin or shlyokavitsa. Uses the GIN index. */
    public function scopeMatchingAlias(Builder $q, string $term): Builder
    {
        return $q->where(function (Builder $inner) use ($term) {
            foreach (self::queryVariants($term) as $variant) {
                $inner->orWhereRaw('aliases @> ?', [
                    json_encode([$variant], JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
    }

    /** Trigram similarity, for when the buyer mistypes the model. */
    public function scopeFuzzy(Builder $q, string $term): Builder
    {
        return $q->whereRaw('model % ?', [$term])
                 ->orderByRaw('similarity(model, ?) DESC', [$term]);
    }

    /**
     * Match without ordering - for use inside a whereHas subquery, where an
     * ORDER BY is discarded anyway and only costs a sort.
     */
    public function scopeMatches(Builder $q, string $term): Builder
    {
        $variants = self::queryVariants($term);

        return $q->where(function (Builder $inner) use ($variants) {
            foreach ($variants as $v) {
                $inner->orWhereRaw('aliases @> ?', [json_encode([$v], JSON_UNESCAPED_UNICODE)])
                      ->orWhereRaw('word_similarity(?, model) > 0.4', [$v])
                      ->orWhereRaw('model ILIKE ?', ['%'.$v.'%']);
            }
        });
    }

    /**
     * What the search box should actually call: try the exact alias first,
     * because that is both fast and precise, and only fall back to fuzzy
     * matching when it misses. Doing it the other way round buries the right
     * answer under near-misses.
     */
    /**
     * The price band, or null when it cannot be stated honestly.
     *
     * Moved here from ShowPart the moment a second screen wanted it. Three
     * things now ask this question - the catalogue page, the valuation page
     * and the deal badge on a listing card - and a rule about when a number is
     * too thin or too old to print is exactly the kind that must not exist in
     * three versions.
     *
     * Two ways it is withheld, both more important than showing a number: too
     * few listings for a median to mean anything (the refresh command refuses
     * to compute one), and a band old enough to be wrong. A stale median is a
     * specific kind of harmful - it looks current, it gets quoted back in
     * negotiations, and nothing on the page says how old it is.
     *
     * @return array{p25: int, median: int, p75: int, at: \Illuminate\Support\Carbon}|null
     */
    public function priceBand(): ?array
    {
        if (! $this->price_median_cents || ! $this->price_stats_at) {
            return null;
        }

        $maxAge = (int) config('remarket.parts.price_band_max_age_days', 7);

        if ($this->price_stats_at->lt(now()->subDays($maxAge))) {
            return null;
        }

        return [
            'p25'    => (int) $this->price_p25_cents,
            'median' => (int) $this->price_median_cents,
            'p75'    => (int) $this->price_p75_cents,
            'at'     => $this->price_stats_at,
        ];
    }

    /**
     * Where one asking price sits against this model's band.
     *
     * Returns the signed percentage away from the median and which third of
     * the band it falls in. Deliberately NOT a verdict: "скъпо" is a judgement
     * about somebody's own listing, and a marketplace that grades its sellers
     * in public loses the sellers. The caller decides what, if anything, to
     * say - the browse card says something only when a listing is notably
     * CHEAP, because that is a buyer aid rather than a seller's report card.
     *
     * @return array{percent: int, position: string}|null
     */
    public function priceStanding(int $priceCents): ?array
    {
        $band = $this->priceBand();

        if (! $band || $band['median'] <= 0 || $priceCents <= 0) {
            return null;
        }

        return [
            'percent'  => (int) round(($priceCents - $band['median']) / $band['median'] * 100),
            'position' => match (true) {
                $priceCents <= $band['p25'] => 'low',
                $priceCents >= $band['p75'] => 'high',
                default                     => 'mid',
            },
        ];
    }

    public function scopeSearch(Builder $q, string $term): Builder
    {
        $variants = self::queryVariants($term);
        $primary  = $variants[0];
        $fixed    = end($variants);

        // word_similarity, not similarity: a search box gets short fragments
        // matched against longer titles, and word_similarity scores the best
        // matching extent rather than the whole string. Measured on real data,
        // "rtx 4o7o" scores 0.238 by similarity but 0.556 by word_similarity.
        return $q->where(function (Builder $inner) use ($variants) {
            foreach ($variants as $v) {
                $inner->orWhereRaw('aliases @> ?', [json_encode([$v], JSON_UNESCAPED_UNICODE)])
                      ->orWhereRaw('word_similarity(?, model) > 0.4', [$v])
                      ->orWhereRaw('model ILIKE ?', ['%'.$v.'%']);
            }
        })->orderByRaw(
            'CASE WHEN aliases @> ? OR aliases @> ? THEN 0 ELSE 1 END,
             word_similarity(?, model) DESC',
            [
                json_encode([$primary], JSON_UNESCAPED_UNICODE),
                json_encode([$fixed], JSON_UNESCAPED_UNICODE),
                $fixed,
            ]
        );
    }
}
