<?php

namespace App\Notifications;

use App\Models\Listing;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * New listings matched something the buyer asked to be told about.
 *
 * The only notification here the user opted into rather than one triggered by
 * something someone else did - which raises the bar for it. It has to be worth
 * the interruption every single time, so: never sent when nothing matched,
 * capped at a handful of examples, and the link goes to the search rather than
 * to one listing, because the buyer asked about a market and not an item.
 */
class SavedSearchMatches extends RemarketNotification
{
    /** @param  Collection<int, Listing>  $listings */
    public function __construct(
        private readonly SavedSearch $search,
        private readonly Collection $listings,
        private readonly string $url,
    ) {}

    public function subject(User $user): string
    {
        $n = $this->listings->count();

        return $n === 1
            ? 'Нова обява по „'.$this->search->name.'“'
            : $n.' нови обяви по „'.$this->search->name.'“';
    }

    public function lines(User $user): array
    {
        // Titles and prices, not a bare count. "3 new listings" makes someone
        // open the site to find out whether it was worth it; the titles let
        // them decide before they do, which is the more respectful of the two.
        $lines = $this->listings
            ->take(5)
            ->map(fn (Listing $l) => '· '.$l->title.' — '.$l->formattedPrice())
            ->all();

        if (($extra = $this->listings->count() - 5) > 0) {
            $lines[] = 'и още '.$extra.'.';
        }

        $lines[] = 'Известията за това търсене можеш да спреш от „Запазени търсения“.';

        return $lines;
    }

    public function url(User $user): string
    {
        return $this->url;
    }

    public function action(User $user): string
    {
        return 'Виж обявите';
    }
}
