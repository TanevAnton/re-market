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

        // Preferences, not permissions: the worst a forged value can do is
        // stop the forger's own notifications. telegram_chat_id is NOT here -
        // it is proof of a conversation and is only ever written by the bot.
        'notify_email', 'notify_telegram',
    ];

    /**
     * The same defaults the migration writes, repeated here on purpose.
     *
     * A model that has not been read back from the database has no value for a
     * column it never set, and `null` is falsy - so via() would quietly return
     * no channels at all and the notification would go nowhere, without an
     * error. Any user loaded from a row is fine; it is the freshly-created
     * instance still in hand that is not. Defaulting here means "notifiable"
     * never depends on whether someone remembered to refresh the model.
     */
    protected $attributes = [
        'notify_email'    => true,
        'notify_telegram' => true,

        /*
         * Two more of the same shape, both found by a test that rendered the
         * settings screen for a just-created user.
         *
         * `locale` is a typed `string` property on EditProfile, so a null read
         * from a freshly-created instance is not a wrong value — it is a
         * TypeError halfway through rendering the page. And `trader_status`
         * null means the verification block's status chip matches none of its
         * four cases and draws unstyled.
         *
         * Both columns have database defaults, and that is exactly why this is
         * needed: a default is applied by the INSERT, not read back into the
         * model that issued it.
         */
        'locale'        => 'bg',
        'trader_status' => self::TRADER_NONE,
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
            'trader_details'     => 'array',
            'seller_type'        => SellerType::class,
            'trader_verified_at' => 'datetime',
            'rating_avg'        => 'decimal:2',
            'offers_suspended'  => 'boolean',
            'is_admin'          => 'boolean',
            'notify_email'      => 'boolean',
            'notify_telegram'   => 'boolean',
        ];
    }

    /**
     * Account recovery, not a notification.
     *
     * Overridden so it never touches RemarketNotification's via() rules: that
     * class skips an unverified address, returns no channels at all when the
     * user has email switched off, and prefers Telegram when the bot knows the
     * chat. All three are right for an offer and wrong for this - a preference
     * about being pinged must not be able to lock somebody out of their own
     * account, and the one message whose purpose is to prove control of a
     * mailbox has to go to that mailbox.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordLink($token));
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

    // --- trader verification ---------------------------------------------

    public const TRADER_NONE     = 'none';
    public const TRADER_PENDING  = 'pending';
    public const TRADER_VERIFIED = 'verified';
    public const TRADER_REJECTED = 'rejected';

    /**
     * Has a human confirmed the declared company exists and matches?
     *
     * NOT THE SAME QUESTION AS isTrader(), and the difference is the whole
     * point of this feature. `isTrader()` is what the seller SAID — it drives
     * the Art. 6a consumer notice, it is required of everybody, and nobody
     * checks it. This is what somebody CHECKED against the Commercial Register.
     * A badge that conflates the two would tell a buyer a claim was verified
     * when it was only made.
     *
     * Requires isTrader() as well: a verification left standing on an account
     * that has since switched back to „частно лице" would be a badge for a
     * company the seller no longer claims to be.
     */
    public function isVerifiedTrader(): bool
    {
        return $this->isTrader() && $this->trader_status === self::TRADER_VERIFIED;
    }

    /** The company name to print, or null when there is nothing verified. */
    public function verifiedCompany(): ?string
    {
        return $this->isVerifiedTrader()
            ? ($this->trader_details['company'] ?? null)
            : null;
    }

    public function traderStatusLabel(): string
    {
        return match ($this->trader_status) {
            self::TRADER_PENDING  => 'Чака проверка',
            self::TRADER_VERIFIED => 'Проверена фирма',
            self::TRADER_REJECTED => 'Проверката не мина',
            default               => 'Непроверена',
        };
    }

    // --- relations -------------------------------------------------------

    public function city(): BelongsTo          { return $this->belongsTo(City::class); }
    public function listings(): HasMany        { return $this->hasMany(Listing::class); }
    public function bundles(): HasMany         { return $this->hasMany(Bundle::class); }
    public function favorites(): HasMany       { return $this->hasMany(Favorite::class); }
    public function savedSearches(): HasMany   { return $this->hasMany(SavedSearch::class); }
    public function sentOffers(): HasMany      { return $this->hasMany(Offer::class, 'buyer_id'); }
    public function receivedOffers(): HasMany  { return $this->hasMany(Offer::class, 'seller_id'); }
    public function purchases(): HasMany       { return $this->hasMany(Deal::class, 'buyer_id'); }
    public function sales(): HasMany           { return $this->hasMany(Deal::class, 'seller_id'); }
    public function ratingsReceived(): HasMany { return $this->hasMany(Rating::class, 'ratee_id'); }
    public function ratingsGiven(): HasMany    { return $this->hasMany(Rating::class, 'rater_id'); }

    /**
     * Who checked this company.
     *
     * Read only by the admin queue, which shows it next to the decision. A
     * verification is a claim the site makes to buyers, so the site has to be
     * able to say which of its people made it.
     */
    public function traderVerifier(): BelongsTo
    {
        return $this->belongsTo(self::class, 'trader_verified_by');
    }
}
