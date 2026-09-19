<?php

namespace App\Livewire\Bundles;

use App\Enums\BundleStatus;
use App\Enums\ListingStatus;
use App\Models\Bundle;
use App\Models\Listing;
use App\Services\Listings\BundleService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Building a bundle out of listings you already have.
 *
 * ONE COMPONENT FOR BOTH CREATE AND EDIT, unlike listings, and for a reason
 * rather than to save a file: the listing wizard is four steps with photo
 * uploads, validation per step and a draft that survives a closed tab. This is
 * a title, a price and a set of checkboxes. Splitting it would mean two copies
 * of the same selection logic — and the selection logic is where the rules are.
 *
 * Nothing here decides anything. Every mutation goes through BundleService,
 * which refuses other people's listings, listings already in another bundle,
 * listings that are not for sale, and groups of fewer than two. This class
 * shows those refusals on the screen instead of throwing a 500 at someone
 * halfway through.
 */
class ManageBundle extends Component
{
    #[Locked]
    public ?int $bundleId = null;

    public string $title       = '';
    public string $description = '';
    public string $price       = '';

    /** @var list<int> */
    public array $selected = [];

    public bool $confirmingDissolve = false;

    public function mount(?Bundle $bundle = null): void
    {
        if (! $bundle?->exists) {
            return;
        }

        /*
         * OWNER ONLY, admins included, and 404 rather than 403 because whether
         * somebody else's bundle exists is not public.
         *
         * A moderator has a way to act on a bundle already — reject it in the
         * queue, which records who decided, why, and what the seller was told.
         * Editing one from here would do the same damage with none of that
         * record, and `setMembers` would refuse every member anyway, since
         * they belong to the seller rather than to the moderator.
         */
        abort_unless($bundle->user_id === auth()->id(), 404);

        $this->bundleId    = $bundle->id;
        $this->title       = $bundle->title;
        $this->description = (string) $bundle->description;
        $this->price       = $bundle->price_cents ? (string) ($bundle->price_cents / 100) : '';
        $this->selected    = $bundle->listings()->pluck('id')->all();
    }

    public function bundle(): ?Bundle
    {
        return $this->bundleId ? Bundle::find($this->bundleId) : null;
    }

    /**
     * What this seller can put in a bundle: their own, active, and not already
     * spoken for by a different bundle.
     *
     * The query is the same set of rules BundleService enforces. That is
     * deliberate duplication: the query keeps a seller from being offered a
     * choice that will be refused, and the service keeps the refusal true even
     * when the request did not come from this screen.
     */
    public function available()
    {
        return Listing::query()
            ->where('user_id', auth()->id())
            ->where('status', ListingStatus::Active)
            ->where(function ($q) {
                $q->whereNull('bundle_id');

                if ($this->bundleId) {
                    $q->orWhere('bundle_id', $this->bundleId);
                }
            })
            ->with(['images', 'city'])
            ->orderByDesc('price_cents')
            ->get();
    }

    public function toggle(int $listingId): void
    {
        $this->selected = in_array($listingId, $this->selected, true)
            ? array_values(array_diff($this->selected, [$listingId]))
            : [...$this->selected, $listingId];
    }

    /** Live, because the number is the point of the whole screen. */
    public function sumCents(): int
    {
        return (int) Listing::whereIn('id', $this->selected)
            ->where('user_id', auth()->id())
            ->sum('price_cents');
    }

    public function savingCents(): ?int
    {
        $package = $this->priceCents();

        if ($package === null) {
            return null;
        }

        $saving = $this->sumCents() - $package;

        return $saving > 0 ? $saving : null;
    }

    private function priceCents(): ?int
    {
        $clean = str_replace([' ', ','], ['', '.'], trim($this->price));

        return $clean === '' ? null : (int) round((float) $clean * 100);
    }

    private function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'min:8', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price'       => ['nullable', 'numeric', 'min:1', 'max:1000000'],
            'selected'    => ['array', 'min:'.BundleService::MIN_MEMBERS],
        ];
    }

    private function messages(): array
    {
        return [
            'title.required' => 'Дай име на комплекта.',
            'title.min'      => 'Името е твърде кратко — напиши какво представлява машината.',
            'selected.min'   => 'Избери поне '.BundleService::MIN_MEMBERS.' обяви.',
            'price.numeric'  => 'Цената трябва да е число.',
        ];
    }

    public function save(): void
    {
        $this->validate($this->rules(), $this->messages());

        $service = app(BundleService::class);
        $user    = auth()->user();

        $data = [
            'title'       => trim($this->title),
            'description' => trim($this->description) ?: null,
            'price_cents' => $this->priceCents(),
        ];

        try {
            $bundle = ($existing = $this->bundle())
                ? $service->update($existing, $data, $this->selected, $user)
                : $service->create($user, $data, $this->selected);
        } catch (RuntimeException $e) {
            // The service's refusals are written for the seller to read. They
            // belong on the field that caused them, not in a log.
            $this->addError('selected', $e->getMessage());

            return;
        }

        $this->bundleId = $bundle->id;

        /*
         * Publishing is a separate call, but a seller who has just filled this
         * in has said what they mean. A draft that needs a second button is a
         * bundle that sits unpublished — and there is nothing else on this
         * screen for the draft state to be useful for.
         */
        if ($bundle->status === BundleStatus::Draft) {
            try {
                $bundle = $service->publish($bundle, $user);
            } catch (RuntimeException $e) {
                $this->addError('selected', $e->getMessage());

                return;
            }
        }

        session()->flash('status', $bundle->status === BundleStatus::PendingReview
            ? 'Комплектът е изпратен за преглед.'
            : 'Комплектът е запазен.');

        $this->redirectRoute('bundle', $bundle, navigate: true);
    }

    public function confirmDissolve(): void
    {
        $this->confirmingDissolve = true;
    }

    public function cancelDissolve(): void
    {
        $this->confirmingDissolve = false;
    }

    /**
     * Break the group up. Said in those words on the button, not „изтрий":
     * the listings survive, and a seller who thinks this deletes eight ads
     * will never press it.
     */
    public function dissolve(): void
    {
        if ($bundle = $this->bundle()) {
            app(BundleService::class)->dissolve($bundle, auth()->user());
        }

        session()->flash('status', 'Комплектът е разформирован. Обявите остават.');

        $this->redirectRoute('bundles.mine', navigate: true);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.bundles.manage-bundle', [
            'listings' => $this->available(),
        ])->layoutData([
            'title'       => $this->bundleId ? 'Редакция на комплект' : 'Нов комплект',
            'description' => 'Групирай свои обяви в комплект с обща цена.',
        ]);
    }
}
