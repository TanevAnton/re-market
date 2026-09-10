<?php

namespace App\Models;

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

    public function fullName(): string
    {
        return trim("{$this->manufacturer} {$this->model} {$this->variant}");
    }

    public function spec(string $key): mixed
    {
        return $this->specs[$key] ?? null;
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

    /** Both spellings worth trying: as typed, and with o/0 corrected. */
    public static function queryVariants(string $term): array
    {
        $raw = mb_strtolower(trim($term));

        return array_values(array_unique([$raw, self::normalizeQuery($raw)]));
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
