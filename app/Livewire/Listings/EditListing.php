<?php

namespace App\Livewire\Listings;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Enums\MiningUse;
use App\Enums\OfferStatus;
use App\Models\City;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Services\Images\ImageProcessor;
use App\Services\Listings\ListingService;
use App\Support\SpecFilter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Editing a published listing.
 *
 * One page rather than the four-step wizard: the wizard exists to walk someone
 * through decisions they have not made yet, and a seller fixing a price has
 * already made all of them.
 *
 * The category and the catalogue part are shown but NOT editable. Changing
 * either changes what is being sold, and everyone who has already seen the
 * listing - or offered on it - saw something else.
 */
class EditListing extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $listingId;

    public string $title = '';
    public string $description = '';
    public string $condition = 'used';
    public int $quantity = 1;
    public string $price = '';
    public bool $offers_enabled = true;
    public string $min_offer = '';
    public array $delivery_options = [];
    public bool $accepts_inspect_test = true;
    public ?int $city_id = null;
    public ?string $warranty_until = null;
    public bool $has_receipt = false;
    public string $mining_use = 'no';
    public ?int $mining_months = null;
    public string $validation_url = '';
    public array $specs = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $photos = [];

    public function mount(Listing $listing): void
    {
        abort_unless($listing->user_id === auth()->id(), 404);

        $this->listingId = $listing->id;

        $this->fill([
            'title'                => $listing->title,
            'description'          => $listing->description,
            'condition'            => $listing->condition->value,
            'quantity'             => $listing->quantity,
            'price'                => number_format($listing->price_cents / 100, 2, '.', ''),
            'offers_enabled'       => $listing->offers_enabled,
            'min_offer'            => $listing->min_offer_cents
                ? number_format($listing->min_offer_cents / 100, 2, '.', '')
                : '',
            'delivery_options'     => $listing->delivery_options ?? [],
            'accepts_inspect_test' => $listing->accepts_inspect_test,
            'city_id'              => $listing->city_id,
            'warranty_until'       => $listing->warranty_until?->format('Y-m-d'),
            'has_receipt'          => $listing->has_receipt,
            'mining_use'           => $listing->mining_use->value,
            'mining_months'        => $listing->mining_months,
            'validation_url'       => $listing->validation_url ?? '',
            'specs'                => $listing->specs ?? [],
        ]);
    }

    public function listing(): Listing
    {
        return Listing::with(['images', 'part'])->findOrFail($this->listingId);
    }

    /** Pending offers were made against the current price. Say so before they save. */
    public function pendingOffers(): int
    {
        return $this->listing()->offers()->where('status', OfferStatus::Pending)->count();
    }

    public function priceChanged(): bool
    {
        return $this->cents($this->price) !== (int) $this->listing()->price_cents;
    }

    // --- photos -----------------------------------------------------------

    public function updatedPhotos(): void
    {
        $listing = $this->listing();
        $limit   = max(1, (int) config('remarket.listings.max_images', 12));

        $this->validate(['photos.*' => ['image', 'mimes:jpg,jpeg,png,webp,heic', 'max:12288']], [
            'photos.*.image' => 'Файлът трябва да е снимка.',
            'photos.*.mimes' => 'Поддържаме JPG, PNG, WEBP и HEIC.',
            'photos.*.max'   => 'Максимум 12 MB на снимка.',
        ]);

        $processor = new ImageProcessor();
        $position  = (int) $listing->images()->max('position') + 1;

        foreach ($this->photos as $photo) {
            if ($listing->images()->count() >= $limit) {
                $this->addError('photos', "Максимум {$limit} снимки.");
                break;
            }

            try {
                $stored = $processor->store($photo);

                ListingImage::create([
                    'listing_id' => $listing->id,
                    'path'       => $stored['path'],
                    'width'      => $stored['width'],
                    'height'     => $stored['height'],
                    'bytes'      => $stored['bytes'],
                    'phash'      => $stored['phash'],
                    'position'   => $position++,
                ]);
            } catch (\Throwable $e) {
                // Never fail silently: a photo that vanishes without a word is
                // indistinguishable from a form that is broken.
                report($e);
                $this->addError('photos', 'Снимката не можа да се обработи: '.$e->getMessage());
            }
        }

        $this->photos = [];
    }

    public function removePhoto(int $imageId): void
    {
        $listing = $this->listing();
        $image   = $listing->images()->find($imageId);

        if (! $image) {
            return;
        }

        $min = max(1, (int) config('remarket.listings.min_images', 1));

        if ($listing->images()->count() <= $min) {
            $this->addError('photos', 'Обявата трябва да има поне една снимка.');

            return;
        }

        (new ImageProcessor())->delete($image->path, str_replace('.jpg', '_t.jpg', $image->path));
        $image->delete();
    }

    public function markTimestamp(int $imageId): void
    {
        $listing = $this->listing();
        $image   = $listing->images()->find($imageId);

        if (! $image) {
            return;
        }

        // Toggles. It matters more now that the note photo is optional: a
        // seller who marked the wrong picture had no way to unmark it, and the
        // badge on the listing would keep claiming a note that is not there.
        $wasMarked = $image->is_timestamp_photo;

        // Exactly one, so flipping a new one clears the old.
        $listing->images()->update(['is_timestamp_photo' => false]);

        if (! $wasMarked) {
            $listing->images()->whereKey($imageId)->update(['is_timestamp_photo' => true]);
        }
    }

    /**
     * Make this the photo the listing leads with.
     *
     * `images()` orders by position and `coverImage()` takes the first, so the
     * main photo is simply position 0 - no extra column, and browse, search and
     * the home page all follow without knowing this exists.
     *
     * Every row is renumbered rather than just swapping two, because positions
     * drift: photos deleted over the life of a listing leave gaps, and two rows
     * sharing a position makes "first" depend on insertion order. One pass
     * leaves them 0..n-1 with no duplicates.
     */
    public function makePrimary(int $imageId): void
    {
        $listing = $this->listing();
        $images  = $listing->images()->get();

        if (! $images->contains('id', $imageId)) {
            return;
        }

        $ordered = $images->sortBy(fn ($image) => $image->id === $imageId ? -1 : $image->position)
            ->values();

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $position => $image) {
                $image->forceFill(['position' => $position])->save();
            }
        });

        // No cache to clear: listing() re-queries on every call, so the next
        // render already reads the new order.
    }

    // --- save -------------------------------------------------------------

    public function save(ListingService $listings)
    {
        $data = $this->validate([
            'title'                => ['required', 'string', 'min:8', 'max:120'],
            'description'          => ['required', 'string', 'min:20', 'max:4000'],
            'condition'            => ['required', Rule::enum(ListingCondition::class)],
            'quantity'             => ['required', 'integer', 'min:1', 'max:50'],
            'price'                => ['required', 'numeric', 'min:1', 'max:100000'],
            // The database enforces min_offer <= price too; this is so the
            // seller is told rather than shown a constraint violation.
            'min_offer'            => ['nullable', 'numeric', 'min:1', 'lte:price'],
            'city_id'              => ['nullable', 'exists:cities,id'],
            'warranty_until'       => ['nullable', 'date'],
            'mining_use'           => ['required', Rule::enum(MiningUse::class)],
            'mining_months'        => ['nullable', 'integer', 'min:1', 'max:120'],
            'validation_url'       => ['nullable', 'url', 'max:255'],
            'delivery_options'     => ['array'],
        ], [
            'title.required'       => 'Заглавието е задължително.',
            'description.required' => 'Описанието е задължително.',
            'price.required'       => 'Цената е задължителна.',
            'min_offer.lte'        => 'Минималната оферта не може да е над цената.',
        ]);

        try {
            $listings->update($this->listing(), [
                'title'                => $data['title'],
                'description'          => $data['description'],
                'condition'            => $data['condition'],
                'quantity'             => $data['quantity'],
                'price_cents'          => $this->cents($data['price']),
                'offers_enabled'       => $this->offers_enabled,
                'min_offer_cents'      => $data['min_offer'] !== null && $data['min_offer'] !== ''
                    ? $this->cents((string) $data['min_offer'])
                    : null,
                'city_id'              => $data['city_id'],
                'warranty_until'       => $data['warranty_until'] ?: null,
                'has_receipt'          => $this->has_receipt,
                'mining_use'           => $data['mining_use'],
                'mining_months'        => $data['mining_use'] === 'yes' ? $data['mining_months'] : null,
                'validation_url'       => $data['validation_url'] ?: null,
                'accepts_inspect_test' => $this->accepts_inspect_test,
                'delivery_options'     => array_values($this->delivery_options),
                'specs'                => array_filter($this->specs, fn ($v) => $v !== '' && $v !== null),
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('listing', $e->getMessage());

            return null;
        }

        session()->flash('status', 'Промените са запазени.');

        return $this->redirectRoute('listings.mine', navigate: true);
    }

    private function cents(string $amount): int
    {
        return (int) round((float) str_replace(',', '.', $amount) * 100);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $listing = $this->listing();

        return view('livewire.listings.edit-listing', [
            'listing'    => $listing,
            'conditions' => ListingCondition::cases(),
            'miningUses' => MiningUse::cases(),
            'cities'     => City::orderByDesc('population')->get(),
            'itemSpecs'  => array_filter(
                (new SpecFilter($listing->category))->schema(),
                fn ($s) => ($s['scope'] ?? 'part') === 'listing',
            ),
            'locked'     => ! in_array($listing->status, [
                ListingStatus::Draft,
                ListingStatus::PendingReview,
                ListingStatus::Active,
                ListingStatus::Expired,
            ], true),
        ]);
    }
}
