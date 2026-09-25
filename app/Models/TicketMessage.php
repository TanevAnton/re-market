<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a support conversation.
 *
 * `from_staff` is stored, not derived from whether `user_id` is an admin. An
 * admin who opens a ticket about their own account is writing as a user, and a
 * side that is inferred from a role would silently flip the day somebody's
 * permissions changed — rewriting who said what in a conversation that has
 * already happened.
 */
class TicketMessage extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return ['from_staff' => 'boolean'];
    }

    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }

    /** What to show above the message. Guests have no name to show. */
    public function authorLabel(): string
    {
        if ($this->from_staff) {
            return 'Поддръжка';
        }

        return $this->author?->username ?? 'Ти';
    }
}
