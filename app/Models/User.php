<?php

namespace App\Models;

use App\Enums\SellerType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'username', 'email', 'password', 'city_id', 'avatar_path',
        'locale', 'seller_type', 'trader_details',
    ];

    /**
     * The public identifier. The primary key stays an auto-incrementing bigint
     * for join performance; `uuid` is what appears in URLs, so profile links
     * never advertise how many users the site has.
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $hidden = [
        'password', 'remember_token', 'phone_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'suspended_until'   => 'datetime',
            'banned_at'         => 'datetime',
            'last_seen_at'      => 'datetime',
            'password'          => 'hashed',
            'trader_details'    => 'array',
            'seller_type'       => SellerType::class,
            'rating_avg'        => 'decimal:2',
            'offers_suspended'  => 'boolean',
            'is_admin'          => 'boolean',
        ];
    }

    // --- phone -----------------------------------------------------------

    /**
     * We never store the number. The salt lives in PHONE_HASH_SALT and must
     * never change: rotating it orphans every hash and lets banned numbers
     * walk back in.
     */
    public static function hashPhone(string $e164): string
    {
        return hash_hmac('sha256', $e164, config('remarket.phone_hash_salt'));
    }

    public function setPhone(string $e164): void
    {
        $this->phone_hash    = self::hashPhone($e164);
        $this->phone_last4   = substr($e164, -4);
        $this->phone_country = str_starts_with($e164, '+359') ? 'BG' : null;
    }

    public function maskedPhone(): ?string
    {
        return $this->phone_last4 ? '•••• '.$this->phone_last4 : null;
    }

    // --- trust -----------------------------------------------------------

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null && $this->phone_verified_at !== null;
    }

    public function isSuspended(): bool
    {
        return $this->banned_at !== null
            || ($this->suspended_until !== null && $this->suspended_until->isFuture());
    }

    /**
     * The number worth showing on a profile. A seller who completes 92% of the
     * deals they agree to is telling you more than five stars ever will.
     */
    public function completionRate(): ?float
    {
        $total = $this->deals_completed + $this->deals_abandoned;

        return $total > 0 ? round($this->deals_completed / $total * 100, 1) : null;
    }

    public function isTrader(): bool
    {
        return $this->seller_type === SellerType::Trader;
    }

    // --- relations -------------------------------------------------------

    public function city(): BelongsTo          { return $this->belongsTo(City::class); }
    public function listings(): HasMany        { return $this->hasMany(Listing::class); }
    public function favorites(): HasMany       { return $this->hasMany(Favorite::class); }
    public function savedSearches(): HasMany   { return $this->hasMany(SavedSearch::class); }
    public function sentOffers(): HasMany      { return $this->hasMany(Offer::class, 'buyer_id'); }
    public function receivedOffers(): HasMany  { return $this->hasMany(Offer::class, 'seller_id'); }
    public function purchases(): HasMany       { return $this->hasMany(Deal::class, 'buyer_id'); }
    public function sales(): HasMany           { return $this->hasMany(Deal::class, 'seller_id'); }
    public function ratingsReceived(): HasMany { return $this->hasMany(Rating::class, 'ratee_id'); }
    public function ratingsGiven(): HasMany    { return $this->hasMany(Rating::class, 'rater_id'); }
}
