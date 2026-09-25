<?php

namespace App\Enums;

/**
 * Where a wanted ad is.
 *
 * `Fulfilled` and `Expired` are kept apart deliberately, even though both mean
 * „closed". Fulfilled is the buyer saying they found one — which is the single
 * most useful number this site can collect about whether „Търся" works at all.
 * Expired means nobody ever answered. Collapsing them into one status throws
 * away the difference between a feature that works and one that does not.
 */
enum WantedStatus: string
{
    case PendingReview = 'pending_review';
    case Active        = 'active';
    case Fulfilled     = 'fulfilled';
    case Expired       = 'expired';
    case Removed       = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Чака одобрение',
            self::Active        => 'Активно',
            self::Fulfilled     => 'Намерено',
            self::Expired       => 'Изтекло',
            self::Removed       => 'Свалено',
        };
    }

    /** Visible to everybody, and answerable. */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Active;
    }

    /** Still the buyer's problem — counts against their open limit. */
    public function isOpen(): bool
    {
        return in_array($this, [self::PendingReview, self::Active], true);
    }
}
