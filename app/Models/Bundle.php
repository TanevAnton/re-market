<?php

namespace App\Models;

use App\Enums\BundleStatus;
use App\Enums\ListingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * „Продавам цялата конфигурация, или на части."
 *
 * A grouping of one seller's own listings, with an optional package price. Not
 * a listing that contains other listings: mark-sold, the offer floor and the
 * moderation gate all assume a listing is one item, and a bundle modelled as a
 * listing would inherit every one of those assumptions and break each
 * differently.
 *
 * THE RULE THAT SHAPES THE WHOLE CLASS: the package price is only real while
 * every member is still for sale. It is derived rather than stored — see
 * BundleStatus for why — so there is nothing to remember to update when a
 * member sells, expires, or is taken down by a moderator, and no way for the
 * site to advertise a machine at a package price with its graphics card gone.
 */
class Bundle extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['user_id', 'title', 'description', 'price_cents'];

    public function uniqueIds(): array        { return ['uuid']; }
    public function getRouteKeyName(): string { return 'uuid'; }

    protected function casts(): array
    {
        return [
            'status'       => BundleStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** Ordered dearest first: the expensive part is what the bundle is about. */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class)->orderByDesc('price_cents');
    }

    public function moderationItems(): MorphMany
    {
        return $this->morphMany(ModerationItem::class, 'subject');
    }

    // --- what it is worth -------------------------------------------------

    /** The members' own asking prices, added up. */
    public function sumCents(): int
    {
        return (int) $this->listings->sum('price_cents');
    }

    /**
     * Every member still for sale?
     *
     * Reserved counts as gone. A listing with an accepted offer on it is spoken
     * for, and including it would price a machine around a part somebody else
     * has already agreed to buy.
     */
    public function isComplete(): bool
    {
        return $this->listings->isNotEmpty()
            && $this->listings->every(fn (Listing $l) => $l->status === ListingStatus::Active);
    }

    /** The members that are no longer available, for saying so on the page. */
    public function missing()
    {
        return $this->listings->filter(fn (Listing $l) => $l->status !== ListingStatus::Active);
    }

    /**
     * The package price, or null when there is no longer a package.
     *
     * This is the withdrawal rule, and it is one method rather than a status
     * column precisely so nothing has to remember to apply it.
     */
    public function packagePrice(): ?int
    {
        if (! $this->price_cents || ! $this->isComplete()) {
            return null;
        }

        return (int) $this->price_cents;
    }

    public function savingCents(): ?int
    {
        $package = $this->packagePrice();

        if ($package === null) {
            return null;
        }

        $saving = $this->sumCents() - $package;

        // A „package price" at or above the sum of the parts is not a discount,
        // and printing „спестяваш -20 €" would be worse than printing nothing.
        return $saving > 0 ? $saving : null;
    }

    public function savingPercent(): ?int
    {
        $saving = $this->savingCents();
        $sum    = $this->sumCents();

        return $saving && $sum > 0 ? (int) round($saving / $sum * 100) : null;
    }

    // --- money, formatted -------------------------------------------------
    // Same rule as Listing: cents become euros HERE and nowhere else. A view
    // that divides by 100 inline is a view that will one day divide twice.

    public function formatted(?int $cents): ?string
    {
        return $cents === null ? null : number_format($cents / 100, 2, ',', ' ').' €';
    }

    public function formattedSum(): string
    {
        return (string) $this->formatted($this->sumCents());
    }

    public function formattedPackagePrice(): ?string
    {
        return $this->formatted($this->packagePrice());
    }

    public function formattedSaving(): ?string
    {
        return $this->formatted($this->savingCents());
    }

    // --- scopes -----------------------------------------------------------

    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('status', BundleStatus::Active);
    }

    /**
     * A bundle with nothing in it is not a bundle.
     *
     * Members can leave — a listing sells, or its seller detaches it — so a row
     * can end up empty without anybody deleting it, and an empty bundle page is
     * a dead end with a price on it.
     */
    public function scopeNotEmpty(Builder $q): Builder
    {
        return $q->has('listings');
    }
}
