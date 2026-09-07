<?php

namespace App\Livewire\Listings;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Enums\MiningUse;
use App\Models\City;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\Part;
use App\Services\Images\ImageProcessor;
use App\Support\SpecFilter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

class CreateListing extends Component
{
    use WithFileUploads;

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
    public array $stored = [];
    public ?int $timestampIndex = null;

    // --- 4. price and delivery -------------------------------------------
    public string $price = '';
    public bool $offers_enabled = true;
    public string $min_offer = '';
    public array $delivery_options = ['econt'];
    public bool $accepts_inspect_test = true;
    public ?int $city_id = null;

    public function mount(): void
    {
        $this->city_id = auth()->user()?->city_id;
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
        return [
            'stored.min'       => 'Добави поне една снимка.',
            'title.min'        => 'Заглавието е твърде кратко.',
            'description.min'  => 'Опиши състоянието по-подробно - това спестява въпроси.',
            'min_offer.lte'    => 'Минималната оферта не може да е над цената.',
            'category.required' => 'Избери категория.',
            'city_id.required' => 'Избери град.',
            'delivery_options.required' => 'Избери поне един начин за доставка.',
        ];
    }

    public function next(): void
    {
        // Belt and braces: if the upload hook did not run, do it now rather
        // than tell the user they have no photos while thumbnails are absent.
        if ($this->step === 3) {
            $this->processPendingPhotos();
        }

        $this->validate($this->rulesForStep($this->step));

        // A private seller must include the handwritten timestamp photo. It is
        // the single most effective thing we do against stock-photo scams.
        if ($this->step === 3 && $this->requiresTimestampPhoto() && $this->timestampIndex === null) {
            $this->addError('timestampIndex',
                'Отбележи коя снимка е с ръкописна бележка (име и дата).');

            return;
        }

        $this->step = min($this->step + 1, self::LAST_STEP);
    }

    public function back(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function requiresTimestampPhoto(): bool
    {
        return config('remarket.listings.require_timestamp_photo_for_private', true)
            && ! auth()->user()?->isTrader();
    }

    // --- save -------------------------------------------------------------

    public function publish()
    {
        $this->processPendingPhotos();

        foreach (range(1, self::LAST_STEP) as $s) {
            $this->validate($this->rulesForStep($s));
        }

        $user = auth()->user();

        // New accounts are held for review. Cheap, and it is the highest-value
        // anti-spam measure we have.
        $reviewed = $user->listings()->count()
            < config('remarket.antispam.moderated_listings_for_new_accounts', 2);

        $listing = DB::transaction(function () use ($user, $reviewed) {
            $listing = Listing::create([
                'user_id'              => $user->id,
                'part_id'              => $this->partId,
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

            return $listing;
        });

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
        ]);
    }
}
