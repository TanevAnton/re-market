<?php

namespace App\Enums;

enum ListingCondition: string
{
    case New       = 'new';
    case LikeNew   = 'like_new';
    case Used      = 'used';
    case ForParts  = 'for_parts';

    public function label(): string
    {
        return match ($this) {
            self::New      => 'Нова, неотваряна',
            self::LikeNew  => 'Като нова',
            self::Used     => 'Употребявана',
            self::ForParts => 'За части / не работи',
        };
    }

    /** Non-working goods must never be sold as functional. */
    public function requiresFaultDescription(): bool
    {
        return $this === self::ForParts;
    }
}
