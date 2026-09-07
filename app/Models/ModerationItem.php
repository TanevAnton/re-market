<?php

namespace App\Models;

use App\Enums\ModerationTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ModerationItem extends Model
{
    use HasFactory;

    protected $fillable = ['subject_type', 'subject_id', 'trigger', 'priority', 'context'];

    protected function casts(): array
    {
        return [
            'context'    => 'array',
            'trigger'    => ModerationTrigger::class,
            'decided_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo    { return $this->morphTo(); }
    public function decider(): BelongsTo  { return $this->belongsTo(User::class, 'decided_by'); }

    /** Fraud first, housekeeping last. */
    public function scopeQueue(Builder $q): Builder
    {
        return $q->where('status', 'pending')
                 ->orderBy('priority')
                 ->orderBy('created_at');
    }
}
