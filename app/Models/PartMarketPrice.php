<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The asking-price band for a model on another site, as of one day.
 *
 * Written only by `remarket:refresh-market-prices`. Aggregates and nothing
 * else - see the migration for why there is no column for an ad.
 */
class PartMarketPrice extends Model
{
    protected $fillable = [
        'part_id', 'source', 'p25_cents', 'median_cents', 'p75_cents', 'sample_size', 'captured_at',
    ];

    protected function casts(): array
    {
        return [
            // A string here is a fatal on the first ->lt() or ->diffForHumans().
            'captured_at' => 'datetime',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
