<?php

namespace App\Enums;

/**
 * Where a ticket is.
 *
 * FOUR STATES, AND ONE OF THEM IS FOR THE PERSON WAITING rather than for the
 * queue. „Нова" and „В работа" say the same thing to a user — somebody will
 * answer — but they say different things to whoever works the queue, and a
 * ticket that has been read and is being looked into is not one that has been
 * ignored. Telling the two apart on the user's own screen is the cheapest way
 * to stop them writing in again about the same thing.
 *
 * `Answered` is deliberately not called „Closed". A support conversation is
 * over when the person says it is, not when the queue would like it to be, so
 * an answered ticket stays open to a reply and only `Closed` ends it.
 */
enum TicketStatus: string
{
    case New      = 'new';
    case Open     = 'open';
    case Answered = 'answered';
    case Closed   = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New      => 'Нова',
            self::Open     => 'В работа',
            self::Answered => 'Отговорено',
            self::Closed   => 'Затворена',
        };
    }

    /** Still in the queue, whichever way it is phrased. */
    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }

    /** @return list<self> the states the queue still owes something to */
    public static function working(): array
    {
        return [self::New, self::Open, self::Answered];
    }
}
