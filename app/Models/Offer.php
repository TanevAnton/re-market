<?php

namespace App\Models;

use App\Enums\OfferStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NON-BINDING by design and by terms of service. An offer is an expression of
 * interest, not a contract: no order confirmation, no checkout language. That
 * is what keeps the platform outside DSA Art. 30 and the DAC7 reporting regime
 * (see plan section 8.4) - do not let product copy drift on this.
 */
class Offer extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'listing_id', 'buyer_id', 'seller_id', 'amount_cents',
        'note', 'parent_offer_id', 'is_counter', 'expires_at', 'status',
    ];

    /** So a new instance knows its state without re-reading the row. */
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status'       => OfferStatus::class,
            'is_counter'   => 'boolean',
            'expires_at'   => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array   { return ['uuid']; }
    public function getRouteKeyName(): string { return 'uuid'; }

    public function amountEur(): float
    {
        return $this->amount_cents / 100;
    }

    public function formattedAmount(): string
    {
        return number_format($this->amountEur(), 2, ',', ' ').' €';
    }

    /** How far below asking, as a percentage. Useful for lowball analytics. */
    public function discountPercent(): float
    {
        return round(100 - ($this->amount_cents / $this->listing->price_cents * 100), 1);
    }

    public function isExpired(): bool
    {
        return $this->status === OfferStatus::Pending && $this->expires_at->isPast();
    }

    public function listing(): BelongsTo { return $this->belongsTo(Listing::class); }
    public function buyer(): BelongsTo   { return $this->belongsTo(User::class, 'buyer_id'); }
    public function seller(): BelongsTo  { return $this->belongsTo(User::class, 'seller_id'); }
    public function parent(): BelongsTo  { return $this->belongsTo(Offer::class, 'parent_offer_id'); }
    public function counters(): HasMany  { return $this->hasMany(Offer::class, 'parent_offer_id'); }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', OfferStatus::Pending);
    }

    public function scopeStale(Builder $q): Builder
    {
        return $q->where('status', OfferStatus::Pending)->where('expires_at', '<', now());
    }

    /**
     * Offers that are waiting on THIS user to do something.
     *
     * Which side owes an answer depends on is_counter, not on who is the buyer
     * and who is the seller: a plain offer waits on the seller, a counter waits
     * on the buyer. Reading it as "seller_id = me" - the obvious version - is
     * how a counter-offer ends up sitting unanswered forever, because the buyer
     * gets no badge, no count and no prompt anywhere in the interface.
     */
    public function scopeAwaitingResponseFrom(Builder $q, int $userId): Builder
    {
        return $q->where('status', OfferStatus::Pending)
            ->where(fn (Builder $outer) => $outer
                ->where(fn (Builder $b) => $b->where('is_counter', false)->where('seller_id', $userId))
                ->orWhere(fn (Builder $b) => $b->where('is_counter', true)->where('buyer_id', $userId)));
    }

    /**
     * The same question about one loaded offer.
     *
     * The scope answers it for a count in the nav; this answers it for a row
     * in a list. Both exist because both are needed, and they are next to each
     * other so the rule cannot be changed in one and not the other - which is
     * the mistake the scope's own comment above is about.
     */
    public function awaitsResponseFrom(?int $userId): bool
    {
        if ($userId === null || $this->status !== OfferStatus::Pending) {
            return false;
        }

        return $this->is_counter
            ? $this->buyer_id === $userId
            : $this->seller_id === $userId;
    }
}
