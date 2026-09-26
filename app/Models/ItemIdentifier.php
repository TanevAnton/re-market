<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One serial or IMEI, attached to one listing, stored as a hash.
 *
 * There is deliberately no accessor that returns the serial: it is not here.
 * Everything anybody can do with this row is ask whether a value they already
 * hold hashes to the same thing — see App\Support\ItemIdentifier.
 */
class ItemIdentifier extends Model
{
    protected $fillable = [];   // written only by StolenRegistry

    public function listing(): BelongsTo { return $this->belongsTo(Listing::class); }

    public function scopeMatching(Builder $q, string $kind, string $hash): Builder
    {
        return $q->where('kind', $kind)->where('hash', $hash);
    }

    /** „…4821", the way it is shown back to a seller. */
    public function masked(): string
    {
        return $this->last4 ? '…'.$this->last4 : 'записан';
    }
}
