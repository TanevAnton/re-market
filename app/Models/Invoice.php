<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The document. Immutable, and the database enforces that with a trigger that
 * raises — see the migration.
 *
 * Everything on it is a frozen copy: who issued it, who it was for, what the
 * VAT treatment was and why. An invoice is a statement about a past fact, so
 * nothing on it may be a join to a row that can still change.
 */
class Invoice extends Model
{
    use HasUuids;

    protected $fillable = [];   // written only by InvoiceService

    public function uniqueIds(): array        { return ['uuid']; }
    public function getRouteKeyName(): string { return 'uuid'; }

    protected function casts(): array
    {
        return ['issued_on' => 'date'];
    }

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }

    public function formattedTotal(): string
    {
        return self::money($this->total_cents);
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' €';
    }
}
