<?php

namespace App\Services\Catalogue;

use App\Models\Listing;
use App\Models\Part;
use App\Models\PartPromotionDismissal;
use App\Models\User;
use App\Support\PartPromotion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turning what sellers typed into catalogue rows.
 *
 * Three operations, and ATTACH is the one that will be used most. Most of what
 * shows up in the queue is not a missing model at all - it is somebody typing
 * „4070" while the catalogue's row is called „GeForce RTX 4070", or „томахоук"
 * for a board we already have. Those cost one click, and every one of them also
 * teaches the search box a spelling nobody had to imagine in advance.
 *
 * Creating a new row is the rarer, more expensive case, and the one PartSeeder's
 * comment was pointing at: variant-level entries („ASUS TUF RTX 4070 OC") with
 * the real dimensions, which a chipset-level catalogue can never carry.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: notify the seller. Attaching their
 * listing to a model gives it a catalogue page, a price band, working facet
 * filters and possibly a deal badge - it is strictly an improvement to what
 * they already published, requires nothing from them, and a message saying "we
 * tidied up your listing" is an interruption that asks for no decision.
 */
class PartPromotionService
{
    /**
     * Point a set of listings at a part that already exists.
     *
     * The spellings come along: they become aliases on the part, which is how
     * the catalogue learns „томахоук" without anybody adding it to a seeder.
     *
     * @param  list<int>  $listingIds
     * @param  array<string, int>|list<string>  $spellings
     */
    public function attach(array $listingIds, Part $part, array $spellings, User $admin): int
    {
        if ($listingIds === []) {
            throw CatalogueException::nothingToAttach();
        }

        return DB::transaction(function () use ($listingIds, $part, $spellings) {
            /*
             * whereNull('part_id') is not belt-and-braces: two admins working
             * the queue at once, or one with two tabs open, would otherwise
             * have the second write silently move listings that the first had
             * already attached somewhere else.
             */
            $moved = Listing::whereIn('id', $listingIds)
                ->whereNull('part_id')
                ->update(['part_id' => $part->id]);

            if ($moved === 0) {
                throw CatalogueException::nothingToAttach();
            }

            $this->learnAliases($part, $spellings);
            $this->refreshCounts($part);

            return $moved;
        });
    }

    /**
     * Create a catalogue row and attach the listings that asked for it.
     *
     * `$base` is the variant-level path: promoting „ASUS TUF RTX 4070 OC" while
     * „GeForce RTX 4070" already exists should start from that row's specs
     * rather than from an empty form, because everything except the physical
     * dimensions is identical and retyping it is how a spec sheet ends up
     * disagreeing with itself.
     *
     * @param  array{category: string, manufacturer: string, model: string,
     *     variant: ?string, launch_year: ?int, specs: array<string, mixed>,
     *     is_published: bool}  $data
     * @param  list<int>  $listingIds
     * @param  array<string, int>|list<string>  $spellings
     */
    public function promote(array $data, array $listingIds, array $spellings, User $admin, ?Part $base = null): Part
    {
        $category = $data['category'];
        $model    = trim($data['model'] ?? '');
        $variant  = trim((string) ($data['variant'] ?? '')) ?: null;

        if (! config("catalog.categories.{$category}")) {
            throw CatalogueException::unknownCategory($category);
        }

        if ($model === '') {
            throw CatalogueException::needsAModel();
        }

        $manufacturer = trim($data['manufacturer'] ?? '');

        /*
         * Checked before the insert rather than caught afterwards. The table
         * has a unique index on (manufacturer, model, variant), so a duplicate
         * would surface as a QueryException - a 500 on a screen whose whole job
         * is judgement calls, at the exact moment the admin needs to be told
         * the useful thing instead: this already exists, attach to it.
         */
        $clash = Part::where('manufacturer', $manufacturer)
            ->where('model', $model)
            ->where('variant', $variant)
            ->first();

        if ($clash) {
            throw CatalogueException::alreadyExists($clash->fullName());
        }

        return DB::transaction(function () use (
            $data, $listingIds, $spellings, $category, $manufacturer, $model, $variant, $base
        ) {
            $part = Part::create([
                'category'     => $category,
                'manufacturer' => $manufacturer,
                'model'        => $model,
                'variant'      => $variant,
                'slug'         => $this->uniqueSlug($manufacturer, $model, $variant),
                'launch_year'  => $data['launch_year'] ?? $base?->launch_year,

                // Specs from the form, falling back to the base part's. Merged
                // rather than replaced so an admin can override one dimension
                // on a variant and inherit the rest.
                'specs'        => array_filter(
                    array_merge($base?->specs ?? [], $data['specs'] ?? []),
                    fn ($v) => $v !== '' && $v !== null,
                ),
                'aliases'      => PartPromotion::aliasesFrom($spellings, PartPromotion::key($model)),

                /*
                 * A half-filled part is a thin page, and thin pages are what
                 * teach a crawler the site is not worth returning to. Unpublished
                 * rows still attach listings and still work as a grouping - they
                 * just do not get a public page until somebody finishes them.
                 */
                'is_published' => (bool) ($data['is_published'] ?? false),
            ]);

            if ($listingIds !== []) {
                Listing::whereIn('id', $listingIds)
                    ->whereNull('part_id')
                    ->update(['part_id' => $part->id]);
            }

            $this->refreshCounts($part);

            return $part->fresh();
        });
    }

    /** Hide a string that is not a model name. Reversible; see restore(). */
    public function dismiss(string $key, string $sample, User $admin): PartPromotionDismissal
    {
        return PartPromotionDismissal::updateOrCreate(
            ['key' => $key],
            ['sample' => mb_substr($sample, 0, 120), 'user_id' => $admin->id],
        );
    }

    public function restore(string $key): void
    {
        PartPromotionDismissal::where('key', $key)->delete();
    }

    // --- internals --------------------------------------------------------

    /**
     * Add the spellings people used to whatever the part already answers to.
     *
     * Merged, never replaced: the seeded aliases are the ones somebody thought
     * about, and losing them to a promotion would make search worse in exchange
     * for making it better.
     *
     * @param  array<string, int>|list<string>  $spellings
     */
    private function learnAliases(Part $part, array $spellings): void
    {
        $learned = PartPromotion::aliasesFrom($spellings, PartPromotion::key($part->model));
        $merged  = array_values(array_unique(array_merge($part->aliases ?? [], $learned)));

        if ($merged !== ($part->aliases ?? [])) {
            $part->forceFill(['aliases' => $merged])->save();
        }
    }

    /**
     * Bring the counts up to date now rather than at 04:10.
     *
     * `remarket:refresh-part-stats` owns these columns nightly, but a part
     * created at noon showing „0 обяви" on its own page for the rest of the day
     * makes the admin think the attach silently failed. The price band is NOT
     * computed here - that needs percentiles over the whole table and it is
     * honest for a brand-new row to have no band yet.
     */
    private function refreshCounts(Part $part): void
    {
        $part->forceFill([
            'listings_count'        => $part->listings()->count(),
            'active_listings_count' => $part->listings()->active()->count(),
        ])->save();
    }

    /**
     * The slug is in the URL of a page we want indexed, so it must be stable
     * and unique. A numeric suffix is ugly and rare; a collision that throws
     * mid-promotion is worse.
     */
    private function uniqueSlug(string $manufacturer, string $model, ?string $variant): string
    {
        $base = Str::slug(trim("{$manufacturer} {$model} {$variant}")) ?: 'model';
        $slug = $base;

        for ($i = 2; Part::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
