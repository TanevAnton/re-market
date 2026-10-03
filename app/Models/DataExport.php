<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request for „a copy of everything you hold about me".
 *
 * Tracked as a row rather than a file dropped on disk, because the file is the
 * single most concentrated piece of personal data this system ever produces —
 * somebody's messages, addresses, phone numbers and trading history in one
 * archive — and an untracked copy of that is a breach waiting for a
 * misconfiguration. The row is what gives it an owner, an expiry and something
 * the purge can find.
 */
class DataExport extends Model
{
    use HasUuids;

    public const PENDING = 'pending';
    public const READY   = 'ready';
    public const FAILED  = 'failed';
    public const EXPIRED = 'expired';

    /**
     * Nothing is fillable. Every column here is set by the service or the job —
     * a `status` or a `path` arriving from request data is how a user reads
     * somebody else's archive.
     */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'completed_at'  => 'datetime',
            'downloaded_at' => 'datetime',
            'expires_at'    => 'datetime',
        ];
    }

    public function uniqueIds(): array        { return ['uuid']; }
    public function getRouteKeyName(): string { return 'uuid'; }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isReady(): bool
    {
        return $this->status === self::READY
            && $this->path !== null
            && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->status === self::FAILED  => 'Нещо се обърка',
            $this->status === self::PENDING => 'Подготвяме го',
            $this->hasExpired()             => 'Изтекло',
            $this->status === self::READY   => 'Готово за сваляне',
            default                         => 'Изтекло',
        };
    }

    public function formattedSize(): string
    {
        $bytes = (int) $this->bytes;

        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, ',', ' ').' MB'
            : number_format(max(1, $bytes / 1024), 0, ',', ' ').' KB';
    }
}
