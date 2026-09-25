<?php

namespace App\Livewire\Wanted;

use App\Livewire\Concerns\ChecksTurnstile;
use App\Models\City;
use App\Models\Part;
use App\Models\WantedAd;
use App\Services\Wanted\WantedService;
use App\Support\SpecFilter;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Posting a request.
 *
 * SHORT ON PURPOSE. A wanted ad has to be cheaper to write than a listing or
 * nobody writes one — the whole value of the feature is that a buyer who found
 * nothing leaves a trace instead of leaving, and a six-step form is indis-
 * tinguishable from leaving. Category, what you want, and optionally a budget.
 *
 * The model field is optional and free-typed against the catalogue rather than
 * required: „търся видеокарта до 300 €" is a real request from a real buyer
 * who does not know which card they want yet, and that buyer is exactly the
 * one a seller can help.
 */
class ManageWanted extends Component
{
    use ChecksTurnstile;

    public string $category = '';
    public string $title    = '';
    public string $detail   = '';
    public string $budget   = '';      // euros, as typed
    public string $city     = '';
    public ?int $partId     = null;

    /** Catalogue suggestions for what was typed in the title. */
    public array $suggestions = [];

    public function mount(): void
    {
        $this->city = (string) auth()->user()?->city?->slug;
    }

    /**
     * Offer catalogue models as the buyer types, without making them pick one.
     *
     * A matched part makes the reverse match exact — only that model's listings
     * notify the buyer — so it is worth asking for. Making it mandatory would
     * cost the requests from people who do not know the model name, which are
     * the ones worth the most.
     */
    public function updatedTitle(): void
    {
        $this->partId      = null;
        $this->suggestions = [];

        if (mb_strlen(trim($this->title)) < 3 || $this->category === '') {
            return;
        }

        $this->suggestions = Part::search(trim($this->title))
            ->where('category', $this->category)
            ->limit(5)
            ->get()
            ->map(fn (Part $p) => ['id' => $p->id, 'name' => $p->fullName()])
            ->all();
    }

    public function pickPart(int $id, string $name): void
    {
        $this->partId      = $id;
        $this->title       = $name;
        $this->suggestions = [];
    }

    public function save(WantedService $wanted): void
    {
        $this->validate([
            'category' => ['required', 'string'],
            'title'    => ['required', 'string', 'min:3', 'max:160'],
            'detail'   => ['nullable', 'string', 'max:2000'],
            // Typed in euros because that is what the rest of the site shows.
            'budget'   => ['nullable', 'numeric', 'min:1', 'max:100000'],
            'city'     => ['nullable', 'string'],
        ], [
            'category.required' => 'Избери категория.',
            'title.required'    => 'Напиши какво търсиш.',
            'title.min'         => 'Напиши какво търсиш — поне няколко букви.',
            'budget.numeric'    => 'Бюджетът е число в евро.',
        ]);

        if (! $this->passesTurnstile()) {
            return;
        }

        try {
            $ad = $wanted->create(auth()->user(), [
                'category'         => $this->category,
                'part_id'          => $this->partId,
                'title'            => $this->title,
                'detail'           => $this->detail,
                'budget_max_cents' => $this->budget === '' ? null : (int) round((float) $this->budget * 100),
                'city_id'          => $this->city === ''
                    ? null
                    : City::where('slug', $this->city)->value('id'),
            ]);
        } catch (RuntimeException $e) {
            // The open-request cap lands here — a sentence they can act on.
            $this->addError('title', $e->getMessage());

            return;
        }

        $this->redirectRoute('wanted.show', $ad, navigate: true);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.wanted.manage-wanted', [
            'categories' => SpecFilter::categories(),
            'cities'     => City::orderByDesc('population')->get(),
            'mine'       => WantedAd::where('user_id', auth()->id())
                ->withCount(['responses as response_count' => fn ($q) => $q->pending()])
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }
}
