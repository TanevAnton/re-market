<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * „I have one of these" — a seller pointing at one of their own listings.
 *
 * TWO STATES AND NO MORE, and the missing ones are the point. There is no
 * „accepted": accepting is the buyer going to the listing and making an offer,
 * which is a flow that already exists, already has a floor, already produces a
 * Deal and already leaves a rating. A parallel accept here would be a second
 * half-built pipeline to the same place, and the two would disagree the first
 * time either changed.
 *
 * `dismissed` exists only so the buyer can clear a card they are not
 * interested in without the seller being told they were rejected. Nobody is
 * notified of a dismissal, on purpose: a seller who answered honestly and got
 * a „no thanks" push notification learns to stop answering.
 */
class WantedResponse extends Model
{
    public const PENDING   = 'pending';
    public const DISMISSED = 'dismissed';

    protected $fillable = [];   // written only by WantedService

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }

    public function wantedAd(): BelongsTo { return $this->belongsTo(WantedAd::class); }
    public function listing(): BelongsTo  { return $this->belongsTo(Listing::class); }
    public function seller(): BelongsTo   { return $this->belongsTo(User::class, 'seller_id'); }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::PENDING);
    }
}
