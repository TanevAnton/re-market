<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A string an admin has judged not to be a model name.
 *
 * „не знам", „компютър", „както е на снимката". The promotion queue sorts by
 * how many people typed a thing, and the uninformative answers are exactly the
 * ones many people give - so without somewhere to put this judgement the queue
 * re-presents its own worst rows forever and stops being worked.
 */
class PartPromotionDismissal extends Model
{
    protected $fillable = ['key', 'sample', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
