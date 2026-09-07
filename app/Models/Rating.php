<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rating cannot exist without a completed deal. That makes rating farming
 * cost a real transaction instead of a click.
 */
class Rating extends Model
{
    use HasFactory;

    protected $fillable = ['deal_id', 'rater_id', 'ratee_id', 'score', 'role', 'comment'];

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean'];
    }

    public function deal(): BelongsTo  { return $this->belongsTo(Deal::class); }
    public function rater(): BelongsTo { return $this->belongsTo(User::class, 'rater_id'); }
    public function ratee(): BelongsTo { return $this->belongsTo(User::class, 'ratee_id'); }
}
