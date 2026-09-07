<?php

namespace App\Enums;

enum DealStatus: string
{
    case Open      = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';   // called off by agreement
    case Abandoned = 'abandoned';   // accepted, then ghosted - the metric that matters
    case Disputed  = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Open      => 'В процес',
            self::Completed => 'Завършена',
            self::Cancelled => 'Отказана',
            self::Abandoned => 'Изоставена',
            self::Disputed  => 'Спорна',
        };
    }

    public function allowsRating(): bool
    {
        return $this === self::Completed;
    }

    /** Counts against the seller's completion rate. */
    public function harmsReputation(): bool
    {
        return in_array($this, [self::Abandoned, self::Disputed], true);
    }
}
