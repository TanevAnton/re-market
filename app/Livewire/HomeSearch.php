<?php

namespace App\Livewire;

use App\Enums\ListingStatus;
use App\Models\Part;
use App\Support\GpuSeries;
use App\Support\SpecFilter;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The search field on the home page, with the catalogue answering as you type.
 *
 * ITS OWN COMPONENT, and the reason is cost rather than tidiness. The field
 * talks to the server on every pause in typing; as part of Home that would
 * re-run the whole front page - categories, three rails, the stats - to redraw
 * six lines under an input. Here a keystroke costs one query.
 *
 * WHAT IT OFFERS IS MODELS, NOT LISTINGS. Typing „3070" and being shown the
 * RTX 3070 itself - the page with the spec sheet, the price band and somewhere
 * to leave an alert - is the thing a general classifieds board cannot do, and
 * until now the front page gave no hint of it. A suggestion is also never a
 * dead end: the model page exists the week nobody is selling one.
 *
 * STILL AN ORDINARY FORM. Enter without choosing anything goes to /obiavi?q=
 * exactly as before, with JavaScript or without it. The suggestions are an
 * addition to the search, never a gate in front of it.
 */
class HomeSearch extends Component
{
    public string $q = '';

    /** Enough to be useful, few enough to stay on a phone screen above the keyboard. */
    private const LIMIT = 6;

    /** Below this a query matches half the catalogue and means nothing. */
    private const MIN_LENGTH = 2;

    /**
     * The series offered as shortcuts under the field.
     *
     * A fixed handful, newest generations of the two makers people ask for by
     * series. Not ranked by stock: an order that reshuffled as listings came
     * and went would move the link somebody clicked yesterday.
     */
    private const QUICK_SERIES = ['rtx-50', 'rtx-40', 'rtx-30', 'rx-9000', 'rx-7000', 'rx-6000'];

    /** What was typed, cleaned up - or '' when it is too short to search on. */
    public function term(): string
    {
        // Capped: this string goes into a similarity search on every pause,
        // and nobody's model name is two hundred characters long.
        $term = mb_substr(trim($this->q), 0, 60);

        return mb_strlen($term) >= self::MIN_LENGTH ? $term : '';
    }

    /**
     * Catalogue models matching what was typed.
     *
     * Part::search() is the same matcher the listing wizard and the valuation
     * page use, so „ртх 4070", "rtx4070" and "rtx 4o7o" all find the card here
     * for the same reason they do there.
     *
     * Published models only - an unpublished part has no page to link to.
     *
     * @return Collection<int, Part>
     */
    public function suggestions(): Collection
    {
        $term = $this->term();

        if ($term === '') {
            return collect();
        }

        return Part::query()
            ->where('is_published', true)
            ->search($term)
            // How many are for sale right now. Shown only when it is not
            // zero - see the view.
            ->withCount(['listings as live_count' => fn ($l) => $l->where('status', ListingStatus::Active)])
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public function quickLinks(): array
    {
        $links = [];

        foreach (self::QUICK_SERIES as $key) {
            // Asked rather than assumed: if a series is ever renamed or
            // removed, its link disappears instead of pointing nowhere.
            if ($series = GpuSeries::find($key)) {
                $links[] = [
                    'label' => $series['label'],
                    'url'   => route('browse', ['kat' => 'gpu', 'seriya' => $key]),
                ];
            }
        }

        return $links;
    }

    public function render()
    {
        return view('livewire.home-search', [
            'term'        => $this->term(),
            'suggestions' => $this->suggestions(),
            'quickLinks'  => $this->quickLinks(),
            'gpuLabel'    => SpecFilter::categoryLabel('gpu'),
        ]);
    }
}
