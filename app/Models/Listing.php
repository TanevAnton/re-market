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
