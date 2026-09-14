<?php

namespace App\Models;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Enums\MiningUse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Listing extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id', 'part_id', 'category', 'city_id', 'title', 'description',
        'condition', 'quantity', 'price_cents', 'offers_enabled', 'min_offer_cents',
        'warranty_until', 'has_receipt', 'mining_use', 'mining_months',
        'validation_url', 'accepts_inspect_test', 'specs', 'delivery_options',
    ];

    protected function casts(): array
    {
        return [
            'specs'                => 'array',
            'delivery_options'     => 'array',
            'status'               => ListingStatus::class,
            'condition'            => ListingCondition::class,
            'mining_use'           => MiningUse::class,
            'offers_enabled'       => 'boolean',
            'has_receipt'          => 'boolean',
            'accepts_inspect_test' => 'boolean',
            'warranty_until'       => 'date',
            'published_at'         => 'datetime',
            'bumped_at'            => 'datetime',
            'price_drop_notified_at' => 'datetime',
            'expires_at'           => 'datetime',
            'reserved_until'       => 'datetime',
            'sold_at'              => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::saving(function (self $listing) {
            if (blank($listing->slug)) {
                // Str::slug transliterates Cyrillic, so "Видеокарта RTX 4070"
                // becomes "videokarta-rtx-4070". The fallback covers a title
                // that is entirely punctuation or emoji.
                $listing->slug = Str::slug((string) $listing->title) ?: 'obiava';
            }
        });
    }

    // --- money -----------------------------------------------------------
    // Stored as integer cents. These accessors are the ONLY place cents
    // become euros; never do the division inline in a view.

    public function priceEur(): float
    {
        return $this->price_cents / 100;
    }

    public function minOfferEur(): ?float
    {
        return $this->min_offer_cents ? $this->min_offer_cents / 100 : null;
    }

    public function formattedPrice(): string
    {
        return number_format($this->priceEur(), 2, ',', ' ').' €';
    }

    // --- offer rules -----------------------------------------------------

    public function acceptsOffersFrom(?User $buyer): bool
    {
        return $this->offers_enabled
            && $this->status->acceptsOffers()
            && $buyer !== null
            // The account gate, while SMS is off. Email is a weaker proof than
            // a phone number - a throwaway address is free - so this leans on
            // the moderation queue rather than replacing it. Checked here
            // because both MakeOffer and OfferService::place come through this
            // one method, and a second copy would eventually disagree.
            && $buyer->hasVerifiedEmail()
            && $buyer->id !== $this->user_id
            && ! $buyer->offers_suspended
            && ! $buyer->isSuspended();
    }

    /**
     * The anti-lowball gate. An offer under the seller's private floor is
     * declined by the system and never reaches their inbox - which is the
     * whole reason the seller is not being pestered the way OLX pesters them.
     */
    public function isBelowFloor(int $amountCents): bool
    {
        return $this->min_offer_cents !== null && $amountCents < $this->min_offer_cents;
    }

    public function isWarrantied(): bool
    {
        return $this->warranty_until !== null && $this->warranty_until->isFuture();
    }

    /**
     * How far below the model's median this asking price sits, as a whole
     * percent — or null when the site has no business making the claim.
     *
     * This is the ONLY home for that decision, because the badge it drives
     * appears on the card, on the listing page and in the grid, and three
     * copies of a rule this loaded would eventually disagree about what a good
     * deal is.
     *
     * The rule is deliberately one-sided: a listing can be told it is cheap,
     * never that it is expensive. The band already lets a buyer work that out
     * for themselves, and a marketplace that publicly grades its own sellers'
     * prices as too high stops having sellers - which costs the buyers more
     * than the badge ever gave them.
     *
     * Four conditions, all of them about not making a claim we cannot support:
     *  - the model has a band, and it is fresh (Part::priceBand)
     *  - enough live listings that the median is a market and not an opinion
     *  - the price is in the bottom quartile, not merely under the middle
     *  - and far enough under it to be worth a buyer's attention
     */
    public function priceAdvantage(): ?int
    {
        // A sold or expired listing boasting about its price is advertising
        // something nobody can buy.
        if (! $this->status->isPubliclyVisible()) {
            return null;
        }

        $part = $this->part;

        if (! $part || ! $part->priceBand()) {
            return null;
        }

        if ($part->active_listings_count < (int) config('remarket.parts.deal_badge_min_listings', 5)) {
            return null;
        }

        $standing = $part->priceStanding((int) $this->price_cents);

        if (! $standing || $standing['position'] !== 'low') {
            return null;
        }

        // priceStanding returns a signed distance from the median; below it is
        // negative. The badge speaks in positive percentages because that is
        // how a person says it out loud.
        $under = -$standing['percent'];

        return $under >= (int) config('remarket.parts.deal_badge_min_percent', 7)
            ? $under
            : null;
    }

    // --- relations -------------------------------------------------------

    public function user(): BelongsTo     { return $this->belongsTo(User::class); }
    public function seller(): BelongsTo   { return $this->belongsTo(User::class, 'user_id'); }
    public function part(): BelongsTo     { return $this->belongsTo(Part::class); }
    public function city(): BelongsTo     { return $this->belongsTo(City::class); }
    public function images(): HasMany     { return $this->hasMany(ListingImage::class)->orderBy('position'); }
    public function offers(): HasMany     { return $this->hasMany(Offer::class); }
    public function deal(): HasOne        { return $this->hasOne(Deal::class); }
    public function threads(): HasMany    { return $this->hasMany(Thread::class); }

    public function coverImage(): ?ListingImage
    {
        return $this->images->first();
    }

    // --- queries ---------------------------------------------------------

    public function scopeVisible(Builder $q): Builder
    {
        return $q->whereIn('status', [ListingStatus::Active, ListingStatus::Reserved]);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', ListingStatus::Active);
    }

    /** Filters on the attached part's typed specs - the catalogue payoff. */
    public function scopeWherePartSpec(Builder $q, string $key, string $op, mixed $value): Builder
    {
        return $q->whereHas('part', fn ($p) => $p->whereRaw("(specs->>?)::numeric {$op} ?", [$key, $value]));
    }
}
