<?php

namespace App\Livewire\Listings;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Enums\MiningUse;
use App\Enums\ModerationTrigger;
use App\Models\City;
use App\Models\Listing;
use App\Models\ListingDraft;
use App\Models\ListingImage;
use App\Models\Part;
use App\Services\Images\ImageProcessor;
use App\Services\Moderation\ListingScreener;
use App\Services\Moderation\ModerationService;
use App\Support\PriceGuidance;
use App\Support\RequiredSpecs;
use App\Support\SpecFilter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use App\Livewire\Concerns\ChecksTurnstile;
use Livewire\Component;
use Livewire\WithFileUploads;

class CreateListing extends Component
{
    use ChecksTurnstile;

    use WithFileUploads;

    /*
     * #[Locked] on the three properties the client has no business setting.
     *
     * Livewire lets the browser update any public property. None of these are
     * bound with wire:model anywhere in the view - they are only ever written
     * by next()/back(), by the photo methods, and by restoring a draft - so
     * locking them costs nothing and closes three doors:
     *
     *   step           - jumping straight to 4 skips every earlier validation.
     *                    publish() re-validates all four steps, so this was not
     *                    exploitable, but it was one refactor away from being.
     *   stored         - a crafted update could point a listing at an arbitrary
     *                    path on the images disk, including somebody else's.
     *   timestampIndex - decides which photo carries the handwritten-note
     *                    badge, which is a trust signal buyers read.
     *
     * markTimestamp() and the rest still work: Locked blocks direct property
     * updates from the client, not server-side methods it calls.
     */
    #[Locked]
    public int $step = 1;

    public const LAST_STEP = 4;

    // --- 1. category ------------------------------------------------------
    public string $category = '';

    // --- 2. the part ------------------------------------------------------
    public string $partSearch = '';
    public ?int $partId = null;
    public bool $partNotListed = false;
    public string $customPart = '';

    // --- 3. the item ------------------------------------------------------
    public string $title = '';
    public string $description = '';
    public string $condition = 'used';
    public int $quantity = 1;
    public array $specs = [];
    public string $mining_use = 'no';
    public ?int $mining_months = null;
    public ?string $warranty_until = null;
    public bool $has_receipt = false;
    public string $validation_url = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $photos = [];
    /** Already processed and on disk: ['path','thumb','phash','width','height','bytes'] */
    #[Locked]
    public array $stored = [];

    #[Locked]
    public ?int $timestampIndex = null;

    // --- 4. price and delivery -------------------------------------------
    public string $price = '';
    public bool $offers_enabled = true;
    public string $min_offer = '';
    public array $delivery_options = ['econt'];
    public bool $accepts_inspect_test = true;
    public ?int $city_id = null;

    /**
     * A draft is waiting, and we are asking rather than restoring.
     *
     * Restoring silently is the tempting version and the wrong one: somebody
     * who came here to post a second, unrelated card would find last week's
     * half-written ad in the boxes and have to work out what happened.
     */
    #[Locked]
    public bool $draftAvailable = false;

    #[Locked]
    public ?string $draftAge = null;

    #[Locked]
    public ?int $draftPhotos = null;

    /** Set when the wizard was opened as a copy of an existing listing. */
    #[Locked]
    public bool $copiedFrom = false;

    public function mount(?Listing $from = null): void
    {
        $this->city_id = auth()->user()?->city_id;

        if ($from !== null) {
            $this->copyFrom($from);

            return;
        }

        if ($draft = $this->draft()) {
            if ($draft->isSubstantial()) {
                $this->draftAvailable = true;
                $this->draftAge       = $draft->updated_at->diffForHumans();
                $this->draftPhotos    = $draft->photoCount();
            } else {
                // Nothing worth offering back. Clear it now rather than
                // prompting about an empty form.
                $draft->discard();
            }
        }
    }

    // --- duplicating an existing listing ----------------------------------

    /**
     * Open the wizard already filled in from one of your own listings.
     *
     * A dealer with three identical RAM kits, or anyone relisting the same
     * model they sell every month, was filling four steps from scratch each
     * time. Supply is the bottleneck; this is the cheapest supply there is.
     *
     * Photos are deliberately NOT copied, and not only for tidiness:
     * ListingScreener matches perceptual hashes across every listing on the
     * site and queues anything within the threshold. It records whether the
     * match was the same seller, but it still queues it - so copying the
     * photos would send every duplicated listing to moderation and teach the
     * moderator to approve without looking, which is worse than not screening.
     * Each copy gets its own photographs, which is also what a buyer needs:
     * three kits are three items, and the one in the picture is the one being
     * described.
     */
    private function copyFrom(Listing $source): void
    {
        // 404 rather than 403: whether somebody else's listing exists is not
        // this screen's business to confirm. Matches the thread guard.
        abort_unless($source->user_id === auth()->id(), 404);

        // A listing a moderator took down is not a template. Copying it is the
        // one-click way to repost exactly what was removed.
        abort_if($source->status === ListingStatus::Removed, 404);

        $this->category             = $source->category;
        $this->partId               = $source->part_id;
        $this->partNotListed        = $source->part_id === null;
        // Copied, unlike the photos and the warranty: it is a fact about the
        // model rather than about the physical item, and a seller listing a
        // second one of something the catalogue still lacks should not have to
        // type its name again.
        $this->customPart           = (string) $source->custom_part;
        $this->title                = $source->title;
        $this->description          = $source->description;
        $this->condition            = $source->condition->value;
        $this->quantity             = $source->quantity;
        $this->specs                = $source->specs ?? [];
        $this->mining_use           = $source->mining_use->value;
        $this->mining_months        = $source->mining_months;
        $this->has_receipt          = $source->has_receipt;
        $this->validation_url       = (string) $source->validation_url;
        $this->price                = rtrim(rtrim(number_format($source->priceEur(), 2, '.', ''), '0'), '.');
        $this->offers_enabled       = $source->offers_enabled;
        $this->min_offer            = $source->min_offer_cents
            ? rtrim(rtrim(number_format($source->minOfferEur(), 2, '.', ''), '0'), '.')
            : '';
        $this->delivery_options     = $source->delivery_options ?: ['econt'];
        $this->accepts_inspect_test = $source->accepts_inspect_test;
        $this->city_id              = $source->city_id ?? auth()->user()?->city_id;

        /*
         * Warranty is NOT copied. It is a date on one physical item, and a
         * seller clicking through a prefilled form will not notice a stale one
         * carried over - which would be a warranty claim they cannot honour
         * and, under ЗЗП, a statement they are answerable for.
         */
        $this->warranty_until = null;

        // Straight to step 3: category and model are already answered, and
        // photos - the one thing that must be redone - live there.
        $this->step       = 3;
        $this->copiedFrom = true;
    }

    // --- drafts -----------------------------------------------------------

    private function draft(): ?ListingDraft
    {
        return ListingDraft::where('user_id', auth()->id())->first();
    }

    /**
     * Write the wizard state away.
     *
     * Called on every step change and whenever a step-3 field loses focus,
     * which is where the abandonment happens. Cheap enough to do often: one
     * upsert of a jsonb column, no photo work - the images are already on disk
     * by the time they reach $stored.
     */
    public function saveDraft(): void
    {
        if (! auth()->check() || $this->step < 2) {
            // Nothing before a category is chosen is worth keeping, and a
            // draft row created by merely opening the page would prompt on the
            // next visit about a form nobody filled in.
            return;
        }

        $payload = [];

        foreach (ListingDraft::PERSISTED as $key) {
            $payload[$key] = $this->{$key};
        }

        ListingDraft::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'payload'  => $payload,
                'step'     => $this->step,
                'title'    => $this->title ?: null,
                'category' => $this->category ?: null,
            ],
        );
    }

    public function resumeDraft(): void
    {
        $draft = $this->draft();

        if (! $draft) {
            $this->draftAvailable = false;

            return;
        }

        /*
         * Restored key by key from the allow-list, not by filling the component
         * with whatever the JSON happens to hold. The payload is the server's
         * own writing, but it is stored data coming back into typed properties,
         * and an allow-list costs one foreach.
         */
        foreach (ListingDraft::PERSISTED as $key) {
            if (array_key_exists($key, $draft->payload)) {
                $this->{$key} = $draft->payload[$key];
            }
        }

        $this->step           = max(1, min((int) $draft->step, self::LAST_STEP));
        $this->draftAvailable = false;
    }

    public function discardDraft(): void
    {
        $this->draft()?->discard();

        $this->draftAvailable = false;
    }

    // ---------------------------------------------------------------------

    public function filter(): SpecFilter
    {
        return new SpecFilter($this->category);
    }

    /** Listing-scoped specs only - part-scoped ones come from the catalogue. */
    public function itemSpecs(): array
    {
        return array_filter(
            $this->filter()->schema(),
            fn ($s) => ($s['scope'] ?? 'part') === 'listing'
        );
    }

    /**
     * The listing-scoped specs marked `required`, which until now were not.
     *
     * `required` was decorative on this side of the schema: the wizard
     * validated the scalar fields and left the spec blob alone, so
     * `monitor.dead_pixels`, `prebuilt.cpu_model` and `prebuilt.gpu_model` were
     * marked required and nothing enforced them. A flag that looks like a
     * guarantee and is not one is worse than no flag.
     *
     * They are also PULLED OUT of „Подробности по желание". Leaving them there
     * and merely validating them would reject a listing over a field inside a
     * collapsed panel labelled „by choice" — a field the seller cannot see and
     * was told was optional. If it is required it does not live under that
     * heading.
     *
     * @return array<string, array<string, mixed>>
     */
    public function requiredItemSpecs(): array
    {
        return RequiredSpecs::forCategory($this->category);
    }

    /** @return array<string, array<string, mixed>> */
    public function optionalItemSpecs(): array
    {
        return array_filter(
            $this->itemSpecs(),
            fn ($s) => ! ($s['required'] ?? false),
        );
    }

    public function partResults()
    {
        if (! $this->category || mb_strlen($this->partSearch) < 2) {
            return collect();
        }

        return Part::where('category', $this->category)
            ->search($this->partSearch)
            ->limit(12)
            ->get();
    }

    public function selectedPart(): ?Part
    {
        return $this->partId ? Part::find($this->partId) : null;
    }

    public function choosePart(int $id): void
    {
        $this->partId        = $id;
        $this->partNotListed = false;
        $this->customPart    = '';

        if ($part = $this->selectedPart()) {
            $this->title = $part->fullName();
        }
    }

    public function clearPart(): void
    {
        $this->partId = null;
        $this->title  = '';
    }

    public function useCustomPart(): void
    {
        $this->partNotListed = true;
        $this->partId        = null;
    }

    // --- photos -----------------------------------------------------------

    public function updatedPhotos(): void
    {
        $this->processPendingPhotos();
    }

    /**
     * Move anything sitting in $photos onto disk and into $stored.
     *
     * Called both from the updated hook and again on step change: if the hook
     * does not fire for any reason, the files still get processed rather than
     * the form insisting there are no photos while the user is looking at one.
     */
    public function processPendingPhotos(): void
    {
        if (empty($this->photos)) {
            return;
        }

        $this->validate([
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp,heic', 'max:12288'],
        ], [
            'photos.*.image' => 'Файлът трябва да е снимка.',
            'photos.*.mimes' => 'Поддържаме JPG, PNG, WEBP и HEIC.',
            'photos.*.max'   => 'Максимум 12 MB на снимка.',
        ]);

        $processor = new ImageProcessor();
        $limit     = max(1, (int) config('remarket.listings.max_images', 12));

        foreach ($this->photos as $photo) {
            if (count($this->stored) >= $limit) {
                $this->addError('photos', "Максимум {$limit} снимки.");
                break;
            }

            try {
                $this->stored[] = $processor->store($photo);
            } catch (\Throwable $e) {
                // Never fail silently here. A photo that vanishes without a
                // word is indistinguishable from a form that is broken.
                report($e);
                $this->addError('photos', 'Снимката не можа да се обработи: '.$e->getMessage());
            }
        }

        $this->photos = [];
    }

    public function removePhoto(int $index): void
    {
        if (! isset($this->stored[$index])) {
            return;
        }

        (new ImageProcessor())->delete(
            $this->stored[$index]['path'],
            $this->stored[$index]['thumb'],
        );

        unset($this->stored[$index]);
        $this->stored = array_values($this->stored);

        if ($this->timestampIndex === $index) {
            $this->timestampIndex = null;
        }
    }

    public function markTimestamp(int $index): void
    {
        $this->timestampIndex = $this->timestampIndex === $index ? null : $index;
    }

    /**
     * Promote a photo to be the one everyone sees first.
     *
     * The cover image is whichever photo sits at position 0 - `images()` orders
     * by position and `coverImage()` takes the first - so "make this the main
     * photo" is a reorder, not a new column. That keeps browse, the home page,
     * search results and the listing page in agreement without any of them
     * knowing this feature exists.
     *
     * Moved rather than swapped: a seller who picks the fourth photo wants it
     * first, and expects the rest to stay in the order they uploaded them.
     */
    public function makePrimary(int $index): void
    {
        if (! isset($this->stored[$index]) || $index === 0) {
            return;
        }

        $moved = $this->stored[$index];
        unset($this->stored[$index]);
        array_unshift($this->stored, $moved);
        $this->stored = array_values($this->stored);

        // timestampIndex points at a position in this same array, so it has to
        // follow the photo it was marking rather than the slot it used to be
        // in - otherwise reordering silently re-marks a different picture.
        $this->timestampIndex = match (true) {
            $this->timestampIndex === null    => null,
            $this->timestampIndex === $index  => 0,
            $this->timestampIndex < $index    => $this->timestampIndex + 1,
            default                           => $this->timestampIndex,
        };
    }

    // --- navigation -------------------------------------------------------

    protected function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'category' => ['required', Rule::in(array_keys(config('catalog.categories')))],
            ],
            2 => [
                'partId'     => ['nullable', 'exists:parts,id'],
                'customPart' => [Rule::requiredIf(fn () => $this->partNotListed), 'nullable', 'string', 'max:120'],
            ],
            3 => [
                'title'          => ['required', 'string', 'min:8', 'max:120'],
                'description'    => ['required', 'string', 'min:20', 'max:4000'],
                'condition'      => ['required', Rule::enum(ListingCondition::class)],
                'quantity'       => ['required', 'integer', 'min:1', 'max:50'],
                'mining_use'     => ['required', Rule::enum(MiningUse::class)],
                'mining_months'  => ['nullable', 'integer', 'min:1', 'max:120'],
                'warranty_until' => ['nullable', 'date', 'after:today'],
                'validation_url' => ['nullable', 'url', 'max:255'],
                'stored'         => ['array', 'min:1'],

                // The specs the schema says are required. Category-dependent,
                // so they are merged in rather than listed.
                ...RequiredSpecs::rules($this->category),
            ],
            4 => [
                'price'            => ['required', 'numeric', 'min:1', 'max:100000'],
                'min_offer'        => ['nullable', 'numeric', 'min:1', 'lte:price'],
                'delivery_options' => ['required', 'array', 'min:1'],
                'city_id'          => ['required', 'exists:cities,id'],
            ],
            default => [],
        };
    }

    protected function messages(): array
    {
        return RequiredSpecs::messages($this->category) + [
            'stored.min'       => 'Добави поне една снимка.',
            'title.min'        => 'Заглавието трябва да е поне 8 знака.',
            'description.min'  => 'Опиши състоянието с поне 20 знака - това спестява въпроси.',
            'min_offer.lte'    => 'Минималната оферта не може да е над цената.',
            'category.required' => 'Избери категория.',
            'city_id.required' => 'Избери град.',
            'delivery_options.required' => 'Избери поне един начин за доставка.',
        ];
    }

    /**
     * The optional half of step 3, folded away by default.
     *
     * Step 3 asks for more than the other three steps combined - title,
     * condition, description, mining history, every catalogue spec, warranty,
     * a benchmark link, a receipt, and photos. Most of that is optional, and
     * a seller who cannot tell which is which reads the whole wall as required
     * and closes the tab. Supply is the bottleneck; this screen is where it
     * leaks.
     */
    public bool $showOptional = false;

    public function toggleOptional(): void
    {
        $this->showOptional = ! $this->showOptional;
    }

    public function next(): void
    {
        // Belt and braces: if the upload hook did not run, do it now rather
        // than tell the user they have no photos while thumbnails are absent.
        if ($this->step === 3) {
            $this->processPendingPhotos();
        }

        try {
            $this->validate($this->rulesForStep($this->step));
        } catch (ValidationException $e) {
            /*
             * Step 3 is long enough that the field which failed is usually off
             * screen. Without this the button appears to do nothing at all,
             * which is indistinguishable from the site being broken - and the
             * error is sitting three scrolls up.
             */
            $this->dispatch('form-invalid');

            throw $e;
        }

        /*
         * The handwritten-note photo is encouraged, not enforced - see
         * config('remarket.listings.require_timestamp_photo_for_private'),
         * which now defaults to false. The mark still exists and still shows as
         * a badge on the listing; it just no longer blocks publishing.
         *
         * The check stays here rather than being deleted so that turning the
         * config back on restores the old behaviour exactly, with no code to
         * write under whatever pressure prompted it.
         */
        if ($this->step === 3 && $this->requiresTimestampPhoto() && $this->timestampIndex === null) {
            $this->addError('timestampIndex',
                'Отбележи коя снимка е с ръкописна бележка (име и дата).');

            return;
        }

        $this->step = min($this->step + 1, self::LAST_STEP);

        $this->saveDraft();
    }

    public function back(): void
    {
        $this->step = max($this->step - 1, 1);

        $this->saveDraft();
    }

    public function requiresTimestampPhoto(): bool
    {
        return config('remarket.listings.require_timestamp_photo_for_private', true)
            && ! auth()->user()?->isTrader();
    }

    // --- save -------------------------------------------------------------

    public function publish()
    {
        if (! $this->passesTurnstile()) {
            return null;
        }

        $this->processPendingPhotos();

        foreach (range(1, self::LAST_STEP) as $s) {
            $this->validate($this->rulesForStep($s));
        }

        $user = auth()->user();

        // New accounts are held for review. Cheap, and it is the highest-value
        // anti-spam measure we have. The rule lives in ModerationService so
        // that bundles hold the same sellers this does.
        $reviewed = app(ModerationService::class)->holdsNewSeller($user);

        $listing = DB::transaction(function () use ($user, $reviewed) {
            $listing = Listing::create([
                'user_id'              => $user->id,
                'part_id'              => $this->partId,

                /*
                 * The answer to „Име на модела" is kept, verbatim, whenever
                 * there is no catalogue row behind it. Until now it was
                 * validated and then dropped on the floor - which meant the one
                 * moment a seller tells us, unprompted, exactly what the
                 * catalogue is missing produced nothing at all.
                 *
                 * Stored only when it is actually the answer: a leftover string
                 * from someone who typed a model, changed their mind and picked
                 * from the catalogue is not a gap in the catalogue.
                 */
                'custom_part'          => $this->partNotListed && trim($this->customPart) !== ''
                    ? trim($this->customPart)
                    : null,
                'category'             => $this->category,
                'city_id'              => $this->city_id,
                'title'                => $this->title,
                'description'          => $this->description,
                'condition'            => $this->condition,
                'quantity'             => $this->quantity,
                'price_cents'          => (int) round((float) $this->price * 100),
                'offers_enabled'       => $this->offers_enabled,
                'min_offer_cents'      => $this->min_offer !== ''
                    ? (int) round((float) $this->min_offer * 100)
                    : null,
                'warranty_until'       => $this->warranty_until ?: null,
                'has_receipt'          => $this->has_receipt,
                'mining_use'           => $this->mining_use,
                'mining_months'        => $this->mining_use === 'yes' ? $this->mining_months : null,
                'validation_url'       => $this->validation_url ?: null,
                'accepts_inspect_test' => $this->accepts_inspect_test,
                'specs'                => array_filter($this->specs, fn ($v) => $v !== '' && $v !== null),
                'delivery_options'     => array_values($this->delivery_options),
            ]);

            foreach ($this->stored as $i => $image) {
                ListingImage::create([
                    'listing_id'         => $listing->id,
                    'path'               => $image['path'],
                    'width'              => $image['width'],
                    'height'             => $image['height'],
                    'bytes'              => $image['bytes'],
                    'phash'              => $image['phash'],
                    'is_timestamp_photo' => $this->timestampIndex === $i,
                    'position'           => $i,
                ]);
            }

            // forceFill, not update(): status/published_at/expires_at are
            // deliberately NOT mass-assignable, because Listing IS created from
            // user input and nobody should be able to post a pre-approved ad.
            // update() would silently drop all four and leave this a draft.
            $listing->forceFill([
                'status'       => $reviewed ? ListingStatus::PendingReview : ListingStatus::Active,
                'published_at' => now(),
                'bumped_at'    => now(),
                'expires_at'   => now()->addDays(config('remarket.listings.expire_after_days', 60)),
            ])->save();

            /*
             * Holding the listing and queueing it for review are the same
             * decision, so they happen in the same transaction. Setting the
             * status without the queue entry is how a listing becomes invisible
             * to the public AND to every moderator - the seller waits for a
             * review that was never scheduled.
             */
            if ($reviewed) {
                app(ModerationService::class)->enqueue(
                    $listing,
                    ModerationTrigger::NewAccount,
                    ['listings_so_far' => $user->listings()->count()],
                );
            }

            return $listing;
        });

        /*
         * The automated screens run after the transaction: they read the images
         * back and compare them against every other listing, and a listing that
         * fails to publish should not have left a queue entry behind. Neither
         * check decides anything - both put it in front of a person.
         */
        $flagged  = app(ListingScreener::class)->screen($listing);
        $reviewed = $reviewed || $flagged !== [];

        /*
         * delete(), NOT discard().
         *
         * discard() removes the draft's images from disk, which is right when
         * somebody abandons a wizard and wrong here: those exact files are now
         * the published listing's photographs. Calling the wrong one empties
         * every image off an ad that was created one line earlier.
         */
        $this->draft()?->delete();

        session()->flash('status', $reviewed
            ? 'Обявата е изпратена за преглед. Първите обяви от нов профил се проверяват ръчно.'
            : 'Обявата е публикувана.');

        return $this->redirectRoute('listing', $listing, navigate: true);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.listings.create-listing', [
            'categories' => SpecFilter::categories(),
            'conditions' => ListingCondition::cases(),
            'miningUses' => MiningUse::cases(),
            'cities'     => City::orderByDesc('population')->get(),
            'results'    => $this->partResults(),
            'part'       => $this->selectedPart(),
            'itemSpecs'  => $this->itemSpecs(),

            // Split, because the required ones render outside the „по желание"
            // panel. See requiredItemSpecs().
            'mustSpecs'  => $this->requiredItemSpecs(),

            /*
             * What the model is going for, for step 4. Null unless a catalogue
             * part was chosen AND it has a fresh enough band — an uncatalogued
             * listing has nothing to compare against, which is one more reason
             * the promotion queue matters.
             */
            'guidance'   => PriceGuidance::for(
                $this->selectedPart(),
                $this->price !== '' ? (int) round((float) str_replace(',', '.', $this->price) * 100) : null,
            ),
            'maySpecs'   => $this->optionalItemSpecs(),
        ]);
    }
}
