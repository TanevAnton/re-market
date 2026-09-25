<?php

namespace App\Models;

use App\Enums\TicketStatus;
use App\Enums\TicketTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One support conversation.
 *
 * Written only by SupportService — every state change here has a notification
 * or a seen-timestamp attached to it, and a caller that sets `status` directly
 * is a caller that forgot one of them.
 */
class Ticket extends Model
{
    use HasUuids;

    protected $fillable = [];

    public function uniqueIds(): array        { return ['uuid']; }
    public function getRouteKeyName(): string { return 'uuid'; }

    protected function casts(): array
    {
        return [
            'topic'         => TicketTopic::class,
            'status'        => TicketStatus::class,
            'last_reply_at' => 'datetime',
            'closed_at'     => 'datetime',
        ];
    }

    public function user(): BelongsTo     { return $this->belongsTo(User::class); }
    public function closer(): BelongsTo   { return $this->belongsTo(User::class, 'closed_by'); }
    public function messages(): HasMany   { return $this->hasMany(TicketMessage::class)->orderBy('id'); }

    public function scopeWorking(Builder $q): Builder
    {
        return $q->whereIn('status', array_column(TicketStatus::working(), 'value'));
    }

    /**
     * Is there something here the reader has not seen?
     *
     * BY MESSAGE ID. An earlier version compared timestamps and was wrong:
     * these columns store seconds, so a ticket answered in the same second it
     * was opened produced „seen at == last reply at", `lt()` was false, and the
     * badge never appeared. Integers are strictly ordered and „read up to
     * message 7" is exactly the fact being stored.
     */
    public function unreadForUser(): bool
    {
        return $this->unread($this->user_seen_message_id);
    }

    public function unreadForStaff(): bool
    {
        return $this->unread($this->staff_seen_message_id);
    }

    private function unread(?int $seenId): bool
    {
        return $this->last_message_id !== null
            && ($seenId === null || $seenId < $this->last_message_id);
    }

    /**
     * A reference somebody can read down a phone line.
     *
     * Same alphabet as a payment reference and for the same reason: no 0/O, no
     * 1/I/L, no 5/S, no 8/B, no vowels. A ticket number that arrives with one
     * character wrong is a support conversation about a support conversation.
     */
    public static function newReference(): string
    {
        $alphabet = '234679CDFHJKMNPQRTVWXZ';
        $out      = '';

        for ($i = 0; $i < 6; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'T-'.substr($out, 0, 3).'-'.substr($out, 3);
    }
}
