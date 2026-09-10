<?php

namespace App\Livewire\SavedSearches;

use App\Models\Part;
use App\Models\SavedSearch;
use App\Services\Search\SavedSearchService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * "Tell me when one of these appears."
 *
 * The single most valuable control on a part landing page, and the reason the
 * empty state is not a dead end. Someone arriving from a search for a model
 * nobody is selling this week is the visitor we are most likely to lose - and
 * the one most likely to buy, because they know exactly what they want.
 *
 * It writes an ordinary saved search rather than a special kind of alert, so
 * it shows up alongside the rest and is switched off in the same place. A
 * second mechanism that only unsubscribes from one type of message is how
 * people end up unable to make the emails stop.
 */
class PartAlert extends Component
{
    public Part $part;

    #[Locked]
    public ?int $searchId = null;

    public string $error = '';

    public function mount(Part $part): void
    {
        $this->part = $part;
        $this->searchId = $this->existing()?->id;
    }

    private function criteria(): array
    {
        // The model name, scoped to its category. Not the part id: the buyer
        // wants an RTX 4070, not specifically the ASUS TUF variant somebody
        // happened to catalogue it under.
        return ['kat' => $this->part->category, 'q' => $this->part->model];
    }

    private function existing(): ?SavedSearch
    {
        if (! auth()->check()) {
            return null;
        }

        $wanted = $this->criteria();

        return auth()->user()->savedSearches()->get()
            ->first(fn (SavedSearch $s) => ($s->criteria['q'] ?? null) === ($wanted['q'] ?? null)
                && ($s->criteria['kat'] ?? null) === ($wanted['kat'] ?? null));
    }

    public function toggle(SavedSearchService $searches): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $this->error = '';

        if ($existing = $this->existing()) {
            $existing->delete();
            $this->searchId = null;

            return;
        }

        try {
            $this->searchId = $searches->save(
                auth()->user(),
                $this->part->fullName(),
                $this->criteria(),
            )->id;
        } catch (RuntimeException $e) {
            // The per-user cap, most likely. Said on the page rather than
            // thrown: someone hitting a limit they did not know existed should
            // be told what it is, not shown a 500.
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.saved-searches.part-alert');
    }
}
