<?php

namespace App\Services\Support;

use App\Enums\TicketStatus;
use App\Enums\TicketTopic;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketReplied;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Opening tickets, answering them, and the limits on both.
 *
 * THE ONE RULE WORTH READING: a support queue is only as useful as it is
 * SHORT. Every guard here exists because a queue with forty items in it,
 * thirty of which are the same person asking again, is a queue nobody opens —
 * and the ticket that mattered is somewhere in the middle of it.
 *
 * So: a cap on how many a person can have open, a reply rather than a new
 * ticket when there is already a conversation, and nothing here that lets a
 * DSA notice in through the side door.
 */
class SupportService
{
    /** How many open tickets one person may have at once. */
    private const MAX_OPEN = 3;

    /**
     * @param  User|null  $user     null for a guest
     * @param  string     $email    where the answer goes
     */
    public function open(
        ?User $user,
        string $email,
        TicketTopic $topic,
        string $subject,
        string $body,
    ): Ticket {
        $email = mb_strtolower(trim($user?->email ?? $email));

        /*
         * The cap, counted per PERSON rather than per account — by user when
         * there is one, by email when there is not. Counting only by account
         * would let the same address open unlimited tickets while logged out,
         * which is both the spam path and the honest-mistake path: somebody who
         * cannot log in tries three times and now there are three tickets.
         */
        $open = Ticket::working()
            ->when(
                $user,
                fn ($q) => $q->where(fn ($w) => $w->where('user_id', $user->id)->orWhere('email', $email)),
                fn ($q) => $q->where('email', $email),
            )
            ->count();

        if ($open >= self::MAX_OPEN) {
            throw new RuntimeException(
                'Имаш '.$open.' отворени запитвания. Отговори в някое от тях,'
                .' вместо да отваряш ново — така не се губи контекст.'
            );
        }

        return DB::transaction(function () use ($user, $email, $topic, $subject, $body) {
            $ticket = new Ticket();

            $ticket->forceFill([
                'user_id'       => $user?->id,
                'email'         => $email,
                'topic'         => $topic,
                'subject'       => mb_substr(trim($subject), 0, 160),
                'status'        => TicketStatus::New,
                'reference'     => $this->freeReference(),
                'last_reply_at' => now(),
            ])->save();

            // Marks last_message_id, and the author's own seen pointer with it:
            // nobody gets an unread badge for something they just typed.
            $this->write($ticket, $body, fromStaff: false, author: $user);

            return $ticket;
        });
    }

    /**
     * The person who opened it writes again.
     *
     * Reopens an answered ticket rather than starting a new one, which is the
     * whole reason a ticket has messages: the follow-up question arrives with
     * everything that led to it still attached.
     */
    public function reply(Ticket $ticket, string $body, ?User $author = null): TicketMessage
    {
        if ($ticket->status === TicketStatus::Closed) {
            throw new RuntimeException('Запитването е затворено. Отвори ново, ако има нещо друго.');
        }

        return DB::transaction(function () use ($ticket, $body, $author) {
            // Back to Open, not New: this one has been seen before, and a
            // follow-up should not jump the queue ahead of a first-time
            // question nobody has looked at yet.
            $ticket->forceFill(['status' => TicketStatus::Open])->save();

            return $this->write($ticket, $body, fromStaff: false, author: $author);
        });
    }

    /**
     * Staff answers. This is the one that sends mail.
     *
     * The notification goes to the ticket's own email column rather than to the
     * user's current address — the answer belongs to the conversation that was
     * started, and a guest has no account to notify at all.
     */
    public function answer(Ticket $ticket, string $body, User $staff, bool $close = false): TicketMessage
    {
        if ($ticket->status === TicketStatus::Closed) {
            throw new RuntimeException('Запитването вече е затворено.');
        }

        $message = DB::transaction(function () use ($ticket, $body, $staff, $close) {
            $ticket->forceFill([
                'status'    => $close ? TicketStatus::Closed : TicketStatus::Answered,
                'closed_at' => $close ? now() : null,
                'closed_by' => $close ? $staff->id : null,
            ])->save();

            return $this->write($ticket, $body, fromStaff: true, author: $staff);
        });

        /*
         * OUTSIDE the transaction, deliberately. A mail failure must not roll
         * back an answer that has already been written — the reply is on the
         * screen either way, and losing it because SMTP was slow would be the
         * worse of the two outcomes.
         */
        $this->notifyUser($ticket->refresh());

        return $message;
    }

    public function close(Ticket $ticket, User $staff): Ticket
    {
        $ticket->forceFill([
            'status'    => TicketStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $staff->id,
        ])->save();

        return $ticket;
    }

    public function reopen(Ticket $ticket): Ticket
    {
        $ticket->forceFill([
            'status'    => TicketStatus::Open,
            'closed_at' => null,
            'closed_by' => null,
        ])->save();

        return $ticket;
    }

    /** Somebody looked at it. Two separate pointers — see the migration. */
    public function markSeenByUser(Ticket $ticket): void
    {
        $ticket->forceFill(['user_seen_message_id' => $ticket->last_message_id])->save();
    }

    public function markSeenByStaff(Ticket $ticket): void
    {
        $ticket->forceFill([
            'staff_seen_message_id' => $ticket->last_message_id,
            // Reading a new ticket is what „в работа" means. Left at New it
            // would look untouched to the next person to open the queue.
            'status'                => $ticket->status === TicketStatus::New
                ? TicketStatus::Open
                : $ticket->status,
        ])->save();
    }

    /**
     * Write a message and move the pointers with it.
     *
     * ONE PLACE, because the three facts have to move together: the ticket now
     * ends at this message, the side that wrote it has read it, and the other
     * side has not. Split across the callers, one of them eventually forgets
     * the third — and a forgotten pointer is a badge that never appears, which
     * is the quietest bug this feature can have.
     */
    private function write(Ticket $ticket, string $body, bool $fromStaff, ?User $author): TicketMessage
    {
        $message = new TicketMessage();

        $message->forceFill([
            'ticket_id'  => $ticket->id,
            'user_id'    => $author?->id,
            'from_staff' => $fromStaff,
            'body'       => mb_substr(trim($body), 0, 5000),
        ])->save();

        $ticket->forceFill([
            'last_message_id' => $message->id,
            'last_reply_at'   => now(),
            // Whoever wrote it has read it. The other pointer is left where it
            // was, which is precisely what makes the other side's badge appear.
            $fromStaff ? 'staff_seen_message_id' : 'user_seen_message_id' => $message->id,
        ])->save();

        return $message;
    }

    /**
     * Mail to the address on the ticket, account or not.
     *
     * `Notification::route()` rather than `$user->notify()`: a guest ticket has
     * no notifiable, and routing on the address means both cases take the same
     * path instead of two that can drift.
     */
    private function notifyUser(Ticket $ticket): void
    {
        Notification::route('mail', $ticket->email)
            ->notify(new TicketReplied($ticket));
    }

    private function freeReference(): string
    {
        for ($try = 0; $try < 8; $try++) {
            $reference = Ticket::newReference();

            if (! Ticket::where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException('Не можах да създам номер на запитването. Опитай пак.');
    }
}
