<?php

namespace App\Models;

use App\Enums\DealStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The handoff record. We never touch the money - it moves between the two
 * users through the courier's cash-on-delivery, with "преглед и тест" giving
 * the buyer escrow-grade protection at zero regulatory cost to us.
 */
class Deal extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'listing_id', 'offer_id', 'buyer_id', 'seller_id',
        'agreed_price_cents', 'price_band_low_cents', 'price_band_high_cents',
        'courier', 'inspect_test_selected', 'tracking_number', 'expires_at', 'status',
    ];

    /** So a new instance knows its state without re-reading the row. */
    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'status'                => DealStatus::class,
            'inspect_test_selected' => 'boolean',
            'buyer_confirmed_at'    => 'datetime',
            'seller_confirmed_at'   => 'datetime',
            'completed_at'          => 'datetime',
            'cancelled_at'          => 'datetime',
            'expires_at'            => 'datetime',
        ];
    }

    public function uniqueIds(): array        { return ['uuid']; }
    public function getRouteKeyName(): string { return 'uuid'; }

    public function formattedAgreedPrice(): string
    {
        return number_format($this->agreed_price_cents / 100, 2, ',', ' ').' €';
    }

    /** Whether this side has already done their part of the confirmation. */
    public function confirmedBy(?int $userId): bool
    {
        return match ($userId) {
            $this->buyer_id  => $this->buyer_confirmed_at !== null,
            $this->seller_id => $this->seller_confirmed_at !== null,
            default          => false,
        };
    }

    /** A deal completes only when BOTH sides say it did. */
    public function isMutuallyConfirmed(): bool
    {
        return $this->buyer_confirmed_at !== null && $this->seller_confirmed_at !== null;
    }

    public function hasLapsed(): bool
    {
        return $this->status === DealStatus::Open && $this->expires_at->isPast();
    }

    public function listing(): BelongsTo { return $this->belongsTo(Listing::class); }
    public function offer(): BelongsTo   { return $this->belongsTo(Offer::class); }
    public function buyer(): BelongsTo   { return $this->belongsTo(User::class, 'buyer_id'); }
    public function seller(): BelongsTo  { return $this->belongsTo(User::class, 'seller_id'); }
    public function ratings(): HasMany   { return $this->hasMany(Rating::class); }

    public function counterparty(User $viewer): User
    {
        return $viewer->id === $this->buyer_id ? $this->seller : $this->buyer;
    }
}
