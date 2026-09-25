<?php

namespace App\Models;

use App\Enums\WantedStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * „Търся" — a buyer saying what they want and what they will pay.
 *
 * Status is NOT fillable, the same rule as Listing and for the same reason:
 * nothing that takes user input may write a row that is already approved.
 * WantedService owns every state change.
 */
class WantedAd extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = ['category', 'part_id', 'title', 'detail', 'budget_max_cents', 'city_id'];

    public function uniqueIds(): array        { return ['uuid']; }
    public function getRouteKeyName(): string { return 'uuid'; }

    protected function casts(): array
    {
        return [
            'status'           => WantedStatus::class,
            'budget_max_cents' => 'integer',
            'expires_at'       => 'datetime',
            'fulfilled_at'     => 'datetime',
            'matched_at'       => 'datetime',
        ];
    }

    public function user(): BelongsTo      { return $this->belongsTo(User::class); }
    public function part(): BelongsTo      { return $this->belongsTo(Part::class); }
    public function city(): BelongsTo      { return $this->belongsTo(City::class); }
    public function responses(): HasMany   { return $this->hasMany(WantedResponse::class); }

    /**
     * What a visitor may see.
     *
     * Mirrors Listing::visible() — expiry is checked here rather than trusted
     * from the status column, so an ad whose sweeper has not run yet still
     * drops out of the list instead of sitting there asking for a card the
     * buyer bought six weeks ago.
     */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('status', WantedStatus::Active)
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [WantedStatus::PendingReview, WantedStatus::Active]);
    }

    public function formattedBudget(): ?string
    {
        if ($this->budget_max_cents === null) {
            return null;
        }

        return 'до '.number_format($this->budget_max_cents / 100, 2, ',', ' ').' €';
    }

    /** Days left, for the „изтича след" line. Null when it does not expire. */
    public function daysLeft(): ?int
    {
        return $this->expires_at ? (int) ceil(now()->diffInDays($this->expires_at)) : null;
    }
}
