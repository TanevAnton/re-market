<?php

namespace App\Livewire;

use App\Models\Listing;
use Livewire\Attributes\Layout;
use Livewire\Component;

class ShowListing extends Component
{
    public Listing $listing;

    public function mount(Listing $listing): void
    {
        // A listing awaiting review is not public - but its owner must still be
        // able to see it, or publishing lands the seller on a 404 immediately
        // after we tell them the listing was submitted.
        $viewer = auth()->user();

        $canView = $listing->status->isPubliclyVisible()
            || $viewer?->id === $listing->user_id
            || $viewer?->is_admin;

        abort_unless($canView, 404);

        $this->listing = $listing->load(['part', 'city', 'user', 'images']);

        // Own views do not count, and there is no reason to touch updated_at.
        if ($viewer?->id !== $listing->user_id) {
            Listing::whereKey($listing->getKey())->increment('view_count');
        }
    }

    /** True when the viewer is seeing something the public cannot. */
    public function isPrivateView(): bool
    {
        return ! $this->listing->status->isPubliclyVisible();
    }

    /**
     * The specs this category defines, in the order the catalogue schema
     * declares - so a GPU leads with chipset and VRAM rather than whatever
     * order the jsonb happens to serialise in.
     *
     * Listing-level values win over part-level ones: the part says what the
     * model is, the listing says what THIS unit is.
     */
    public function specRows(): array
    {
        if (! $this->listing->part) {
            return [];
        }

        $schema = config("catalog.categories.{$this->listing->category}.specs", []);
        $specs  = array_merge($this->listing->part->specs ?? [], $this->listing->specs ?? []);
        $locale = app()->getLocale();

        $rows = [];
        foreach ($schema as $key => $spec) {
            if (! array_key_exists($key, $specs)) {
                continue;
            }

            $value = $specs[$key];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $rows[] = [
                'label' => $spec['label'][$locale] ?? $spec['label']['en'] ?? $key,
                'value' => match (true) {
                    is_bool($value)  => $value ? 'да' : 'не',
                    is_array($value) => implode(', ', $value),
                    default          => (string) $value,
                },
                'unit'  => $spec['unit'] ?? null,
                'order' => $spec['priority'] ?? 99,
            ];
        }

        usort($rows, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $rows;
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.show-listing');
    }
}
