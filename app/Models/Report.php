<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * DSA Art. 16 (notice and action) and Art. 17 (statement of reasons). These
 * apply to us regardless of size, so statement_of_reasons is not optional on
 * any decision that restricts a user's content.
 */
class Report extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'reporter_id', 'reporter_email', 'reportable_type', 'reportable_id',
        'reason', 'detail', 'evidence_url',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'resolved_at'     => 'datetime',
        ];
    }

    public function uniqueIds(): array        { return ['uuid']; }
    public function getRouteKeyName(): string { return 'uuid'; }

    public function reportable(): MorphTo  { return $this->morphTo(); }
    public function reporter(): BelongsTo  { return $this->belongsTo(User::class, 'reporter_id'); }
    public function handler(): BelongsTo    { return $this->belongsTo(User::class, 'handled_by'); }

    public function needsAcknowledgement(): bool
    {
        return $this->acknowledged_at === null;
    }
}
