<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day's price band for one model.
 *
 * Written by `remarket:refresh-part-stats` and never by anything else. There is
 * no editing a fact about a day that has already happened, which is why this
 * model carries no timestamps to manage and no update path: a correction to
 * today's figures goes through re-running the command, which overwrites the
 * row it wrote.
 */
class PartPricePoint extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'part_id', 'captured_on', 'p25_cents', 'median_cents', 'p75_cents', 'sample_size',
    ];

    protected function casts(): array
    {
        return [
            // Without this it comes back as a string and every ->diffInDays()
            // on the trend is a fatal - the same cast that was missing on
            // parts.price_stats_at and took down every part page with a band.
            'captured_on' => 'date',
            'created_at'  => 'datetime',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function medianEur(): float
    {
        return $this->median_cents / 100;
    }
}
