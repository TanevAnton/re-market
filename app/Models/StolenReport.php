<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody's claim that an item with this serial was taken from them.
 *
 * A CLAIM, NEVER A FINDING. The platform cannot verify a theft: no public
 * register exists to check a police reference against, and nothing here may
 * imply the check happened. What a confirmed report means is „a person filed
 * this with the police and gave us the number" — which is worth acting on, and
 * is not the same as knowing.
 *
 * That distinction decides the wording everywhere it surfaces, and it is the
 * reason `confirmed` flags a listing into the moderation queue rather than
 * branding it publicly: a human decides what to do about a named seller, with
 * a statement of reasons the seller can dispute.
 */
class StolenReport extends Model
{
    use HasUuids;

    public const PENDING   = 'pending';
    public const CONFIRMED = 'confirmed';
    public const REJECTED  = 'rejected';

    protected $fillable = [];   // written only by StolenRegistry

    public function uniqueIds(): array        { return ['uuid']; }
    public function getRouteKeyName(): string { return 'uuid'; }

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function reporter(): BelongsTo { return $this->belongsTo(User::class, 'reporter_id'); }
    public function decider(): BelongsTo  { return $this->belongsTo(User::class, 'decided_by'); }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::PENDING);
    }

    /** The register: confirmed claims and nothing else. */
    public function scopeConfirmed(Builder $q): Builder
    {
        return $q->where('status', self::CONFIRMED);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::CONFIRMED => 'Потвърден',
            self::REJECTED  => 'Отхвърлен',
            default         => 'Чака проверка',
        };
    }

    public function masked(): string
    {
        return $this->last4 ? '…'.$this->last4 : '—';
    }
}
