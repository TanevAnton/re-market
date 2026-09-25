<?php

namespace App\Models;

use App\Enums\BoostTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One purchase of visibility.
 *
 * THE ACTIVE STATE IS DERIVED, NEVER STORED. There is no `is_active` column
 * and there must not be one. A boost stops when its window closes, when the
 * listing sells, when a moderator removes it, or when the seller takes it
 * down — four events, in four different parts of the codebase, and a stored
 * flag needs all four to remember. The one that forgets leaves somebody's sold
 * graphics card pinned to the top of the category, which is the single most
 * visible way this feature can embarrass the site.
 *
 * Same rule the bundle package price follows, for the same reason.
 */
class Boost extends Model
{
    use HasUuids;

    protected $fillable = [];   // everything here is set by BoostService

    public function uniqueIds(): array        { return ['uuid']; }
    public function getRouteKeyName(): string { return 'uuid'; }

    protected function casts(): array
    {
        return [
            'tier'         => BoostTier::class,
            'starts_at'    => 'datetime',
            'ends_at'      => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo { return $this->belongsTo(Listing::class); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }

    // --- is it running? ---------------------------------------------------

    /**
     * A bump is never „running": it did its work the moment it was bought.
     *
     * Reporting a bump as live would put a „Промотирана" badge on a listing
     * whose paid effect finished days ago and has long since been buried by
     * everyone else's, which is a label that misleads in the direction the
     * Omnibus Directive exists to prevent.
     */
    public function isRunning(): bool
    {
        if ($this->cancelled_at !== null || $this->ends_at === null) {
            return false;
        }

        return $this->ends_at->isFuture();
    }

    public function scopeRunning(Builder $q): Builder
    {
        return $q->whereNull('cancelled_at')
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now());
    }

    public function scopePinned(Builder $q): Builder
    {
        return $q->where('tier', BoostTier::Pin);
    }

    /**
     * What is left, for showing the seller. Null once it is over.
     *
     * `diffInHours` returns a float in Carbon 3 — the codebase has been caught
     * by that before — so this rounds up on purpose: a boost with forty
     * minutes left has „1 час", not „0".
     */
    public function hoursLeft(): ?int
    {
        return $this->isRunning() ? (int) ceil(now()->diffInHours($this->ends_at)) : null;
    }

    /** Euros, like every other price on the site. Cents become euros here. */
    public function formattedPrice(): string
    {
        return number_format($this->price_cents / 100, 2, ',', ' ').' €';
    }
}
