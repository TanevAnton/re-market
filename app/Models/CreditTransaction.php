<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One movement of money in a seller's balance. Append-only.
 *
 * THERE IS NO BALANCE COLUMN ANYWHERE. The balance is the sum of these rows,
 * every time it is asked for. A stored balance is a number that can disagree
 * with the history that produced it, and when it does there is no way to tell
 * which of the two is wrong — except that both are somebody's money.
 *
 * The database refuses UPDATE on this table with a trigger that raises, so an
 * edit fails loudly rather than appearing to work. A correction is a new row
 * with the opposite sign; the wrong row stays, because what happened happened.
 */
class CreditTransaction extends Model
{
    public const TOPUP  = 'topup';    // money in, from a payment
    public const SPEND  = 'spend';    // money out, for a boost
    public const REFUND = 'refund';   // money back, boost cut short
    public const GRANT  = 'grant';    // money in, given by an admin

    protected $fillable = ['user_id', 'amount_cents', 'kind', 'note'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function source(): MorphTo { return $this->morphTo(); }

    /**
     * Guard the one invariant a signed-amount ledger has: the sign must match
     * the kind, or the balance is a lie told in the right currency.
     *
     * In the model rather than a CHECK constraint because the message matters
     * — a developer posting a positive `spend` needs to be told what they did,
     * not handed constraint violation 23514.
     */
    protected static function booted(): void
    {
        static::creating(function (self $row) {
            $shouldBeNegative = $row->kind === self::SPEND;

            if ($row->amount_cents === 0) {
                throw new RuntimeException('A ledger row for zero records nothing.');
            }

            if ($shouldBeNegative && $row->amount_cents > 0) {
                throw new RuntimeException('A spend must be negative.');
            }

            if (! $shouldBeNegative && $row->amount_cents < 0) {
                throw new RuntimeException("A {$row->kind} must be positive.");
            }
        });
    }

    public function formattedAmount(): string
    {
        $sign = $this->amount_cents < 0 ? '−' : '+';

        return $sign.number_format(abs($this->amount_cents) / 100, 2, ',', ' ').' €';
    }
}
