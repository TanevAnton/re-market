<?php

namespace App\Services\Search;

use App\Models\Listing;
use App\Models\SavedSearch;
use App\Models\User;
use App\Support\SpecFilter;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Saved searches, and the alerts that make them worth saving.
 *
 * A saved search that never tells you anything is a bookmark, and nobody needs
 * this table to keep a bookmark. The point is the alert: on a used-hardware
 * marketplace the good listings go in hours, so "tell me when a 4070 under 500
 * appears" is the difference between a buyer who checks the site daily and one
 * who forgets it exists.
 *
 * The criteria are stored as the same shape BrowseListings puts in the query
 * string, deliberately. One vocabulary for "what am I looking for", so a saved
 * search can be re-opened as a normal browse URL and a browse URL can be saved
 * without translation - and nothing has to be migrated when a filter is added.
 */
class SavedSearchService
{
    /** Everything a criteria blob may contain. Anything else is discarded. */
    private const KEYS = ['kat', 'q', 'ot', 'do', 'sast', 'grad', 'f'];

    /**
     * How many a person may keep.
     *
     * Not an anti-abuse measure so much as an anti-noise one: someone with
     * forty saved searches gets forty alerts and mutes the lot, and then the
     * feature has actively cost us a user.
     */
    private const MAX_PER_USER = 20;

    public function save(User $user, string $name, array $criteria): SavedSearch
    {
        $criteria = $this->clean($criteria);

        if ($criteria === []) {
            throw new RuntimeException('Задай поне един филтър, преди да запазиш търсенето.');
        }

        /*
         * The same search saved twice is updated rather than duplicated.
         * Without this, clicking "save" a second time - which people do when
         * they are not sure it worked - silently doubles their alerts.
         */
        $existing = $user->savedSearches()->get()
            ->first(fn (SavedSearch $s) => $this->clean($s->criteria) == $criteria);

        if ($existing) {
            $existing->update(['name' => $name, 'notify' => true]);

            return $existing;
        }

        if ($user->savedSearches()->count() >= self::MAX_PER_USER) {
            throw new RuntimeException(
                'Достигна лимита от '.self::MAX_PER_USER.' запазени търсения. Изтрий някое старо.'
            );
        }

        /*
         * make() then forceFill, not create().
         *
         * `last_notified_at` is a system watermark, not user input, so it is
         * deliberately absent from $fillable - and with
         * preventSilentlyDiscardingAttributes on, passing it to create()
         * throws rather than being quietly dropped. Which is the guard working:
         * the fix is to say plainly that this write is ours, not to widen what
         * a form could set.
         *
         * It starts at "notified now", so the first alert covers listings
         * published AFTER the search was saved. Otherwise saving a broad
         * search immediately mails the person every matching listing already
         * on the site, which reads as spam on first contact.
         */
        $search = $user->savedSearches()->make([
            'name'     => $name,
            'criteria' => $criteria,
            'notify'   => true,
        ]);

        $search->forceFill(['last_notified_at' => now()])->save();

        return $search;
    }

    /**
     * The listings a saved search matches.
     *
     * Shares its filter vocabulary with BrowseListings but not its code: this
     * runs unauthenticated in a queue worker over every saved search on the
     * site, and reaching into a Livewire component from there would tie a
     * nightly job to a page's rendering.
     */
    public function matches(SavedSearch $search, ?\DateTimeInterface $since = null): Builder
    {
        $c = $this->clean($search->criteria);

        $query = Listing::query()
            ->visible()
            ->with(['images', 'city', 'part']);

        if ($category = $c['kat'] ?? null) {
            $query->where('listings.category', $category);
        }

        if ($city = $c['grad'] ?? null) {
            $query->whereHas('city', fn ($q) => $q->where('slug', $city));
        }

        if ($conditions = $c['sast'] ?? null) {
            $query->whereIn('condition', (array) $conditions);
        }

        if (($min = $c['ot'] ?? null) !== null) {
            $query->where('price_cents', '>=', (int) round((float) $min * 100));
        }

        if (($max = $c['do'] ?? null) !== null) {
            $query->where('price_cents', '<=', (int) round((float) $max * 100));
        }

        if ($term = $c['q'] ?? null) {
            $term = mb_strtolower(trim($term));
            $query->where(function ($outer) use ($term) {
                $outer->whereRaw('LOWER(listings.title) LIKE ?', ['%'.$term.'%'])
                      ->orWhereHas('part', fn ($p) => $p->matches($term));
            });
        }

        if ($specs = $c['f'] ?? null) {
            $filter = new SpecFilter($c['kat'] ?? '');

            foreach ($specs as $key => $value) {
                $query = $filter->apply($query, $key, $value);
            }
        }

        if ($since) {
            $query->where('published_at', '>', $since);
        }

        return $query;
    }

    /**
     * Strip a criteria blob down to filters that mean something.
     *
     * Empty strings and empty arrays are what a Livewire form sends for "not
     * set", and storing them would make two identical searches compare as
     * different - which is how the duplicate check above quietly stops working.
     *
     * Whitelisted rather than filtered: `criteria` is user input written into
     * jsonb and read back into a query builder, and an unexpected key is not
     * something to pass along and hope about.
     */
    private function clean(array $criteria): array
    {
        $clean = [];

        foreach (self::KEYS as $key) {
            $value = $criteria[$key] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $clean[$key] = is_array($value)
                ? array_filter($value, fn ($v) => $v !== '' && $v !== null)
                : $value;

            if ($clean[$key] === []) {
                unset($clean[$key]);
            }
        }

        ksort($clean);

        return $clean;
    }

    /** A criteria blob as browse URL parameters, so it opens as a normal page. */
    public function toQuery(SavedSearch $search): array
    {
        return $this->clean($search->criteria);
    }

    /**
     * A readable summary. Shown instead of the raw filters, because
     * `{"kat":"gpu","do":"500"}` tells nobody what they saved.
     */
    public function describe(SavedSearch $search): string
    {
        $c     = $this->clean($search->criteria);
        $parts = [];

        if ($kat = $c['kat'] ?? null) {
            $parts[] = SpecFilter::categoryLabel($kat);
        }

        if ($q = $c['q'] ?? null) {
            $parts[] = '„'.$q.'“';
        }

        $min = $c['ot'] ?? null;
        $max = $c['do'] ?? null;

        $parts[] = match (true) {
            $min !== null && $max !== null => "{$min}–{$max} €",
            $min !== null                  => "над {$min} €",
            $max !== null                  => "до {$max} €",
            default                        => null,
        };

        if ($grad = $c['grad'] ?? null) {
            $parts[] = $grad;
        }

        if ($n = count($c['f'] ?? [])) {
            $parts[] = $n.' филтъра';
        }

        return implode(' · ', array_filter($parts)) ?: 'всички обяви';
    }
}
