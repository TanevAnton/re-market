<?php

namespace App\Livewire\SavedSearches;

use App\Models\SavedSearch;
use App\Services\Search\SavedSearchService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Where saved searches are read, renamed, muted and deleted.
 *
 * The mute switch is the important one. Every alert this site sends carries a
 * line pointing here, because a notification you cannot turn off is a
 * notification people escape by abandoning the account.
 */
class MySearches extends Component
{
    #[Locked]
    public ?int $renaming = null;

    public string $name = '';

    public function startRename(int $id): void
    {
        $search = $this->find($id);

        if (! $search) {
            return;
        }

        $this->renaming = $id;
        $this->name     = $search->name;
        $this->resetErrorBag();
    }

    public function cancelRename(): void
    {
        $this->reset(['renaming', 'name']);
    }

    public function rename(): void
    {
        $this->validate(
            ['name' => ['required', 'string', 'min:2', 'max:80']],
            ['name.required' => 'Дай име на търсенето.'],
        );

        $this->find($this->renaming)?->update(['name' => trim($this->name)]);

        $this->reset(['renaming', 'name']);
    }

    public function toggleNotify(int $id): void
    {
        if ($search = $this->find($id)) {
            $search->update(['notify' => ! $search->notify]);
        }
    }

    public function delete(int $id): void
    {
        $this->find($id)?->delete();
    }

    /**
     * Scoped to the current user on every lookup, not just on the list query.
     *
     * These ids travel through the browser, so each action has to re-establish
     * ownership. Filtering only in render() would leave rename and delete
     * addressable by id alone.
     */
    private function find(?int $id): ?SavedSearch
    {
        return $id === null
            ? null
            : SavedSearch::where('user_id', auth()->id())->find($id);
    }

    #[Layout('components.layouts.app')]
    public function render(SavedSearchService $searches)
    {
        $rows = SavedSearch::where('user_id', auth()->id())
            ->latest()
            ->get()
            ->map(fn (SavedSearch $s) => [
                'model'   => $s,
                'summary' => $searches->describe($s),
                'url'     => route('browse', $searches->toQuery($s)),
            ])
            ->all();

        return view('livewire.saved-searches.my-searches', ['rows' => $rows])
            ->layoutData(['title' => 'Запазени търсения']);
    }
}
