<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A request to pay, and whether it arrived.
 *
 * The billing fields are a COPY of who the buyer was at this moment, not a
 * join to who they are now. See the migration for why that is deliberate.
 */
class Payment extends Model
{
    use HasUuids;

    public const BANK = 'bank';

    protected $fillable = [];   // written only by PaymentService

    public function uniqueIds(): array        { return ['uuid']; }
    public function getRouteKeyName(): string { return 'uuid'; }

    protected function casts(): array
    {
        return [
            'status'       => PaymentStatus::class,
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo    { return $this->belongsTo(User::class); }
    public function confirmer(): BelongsTo { return $this->belongsTo(User::class, 'confirmed_by'); }
    public function invoice(): HasOne    { return $this->hasOne(Invoice::class); }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', PaymentStatus::Pending);
    }

    public function formattedAmount(): string
    {
        return number_format($this->amount_cents / 100, 2, ',', ' ').' €';
    }

    /**
     * The reference the payer types into their transfer.
     *
     * Uppercase, no vowels, no characters that look like each other. Somebody
     * is going to copy this by hand off a phone screen into a banking app, and
     * a reference that arrives with an O instead of a 0 is a transfer that
     * cannot be matched to anybody — which is a support conversation about
     * real money.
     */
    public static function newReference(): string
    {
        // No 0/O, no 1/I/L, no 5/S, no 8/B, and no vowels — so it cannot
        // accidentally spell anything either. 24 characters over 8 places.
        $alphabet = '234679CDFHJKMNPQRTVWXZ';
        $out      = '';

        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'RIGO-'.substr($out, 0, 4).'-'.substr($out, 4);
    }
}
