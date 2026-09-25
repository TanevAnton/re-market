<?php

namespace App\Livewire\Wanted;

use App\Models\WantedAd;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * „Търсения" — demand, readable by anyone.
 *
 * ITS OWN PAGE, NOT MIXED INTO THE LISTINGS GRID. Wanted ads among the
 * listings is what makes an OLX category page unusable: a buyer scanning for
 * something to buy has to read past requests from other buyers. The browse
 * grid stays what it is.
 *
 * PUBLIC, including to visitors with no account, and that is the whole point.
 * The person this page is for is a dealer with stock and no reason to log in
 * yet — and Google indexing „търся RTX 4070" is free supply-side traffic for a
 * site whose actual problem is not having enough to sell.
 */
class BrowseWanted extends Component
{
    use WithPagination;

    #[Url(as: 'kat', except: '')]
    public string $category = '';

    #[Url(as: 'grad', except: '')]
    public string $city = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['category', 'city']);
        $this->resetPage();
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $query = WantedAd::query()
            ->visible()
            ->with(['user', 'city', 'part'])
            ->withCount(['responses as response_count' => fn ($q) => $q->pending()])
            ->when($this->category, fn ($q) => $q->where('category', $this->category))
            ->when($this->city, fn ($q) => $q->whereHas('city', fn ($c) => $c->where('slug', $this->city)))
            ->latest('id');

        return view('livewire.wanted.browse-wanted', [
            'ads'        => $query->paginate(20),
            'categories' => \App\Support\SpecFilter::categories(),
            'cities'     => \App\Models\City::orderByDesc('population')->get(),
        ])->layoutData([
            'title'       => 'Търсения — какво търсят купувачите',
            'description' => 'Купувачи търсят компютърни компоненти и гейминг техника. '
                .'Ако имаш такова нещо, предложи обявата си директно.',
            'canonical'   => route('wanted'),
        ]);
    }
}
