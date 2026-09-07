<?php

namespace App\Enums;

enum ListingStatus: string
{
    case Draft         = 'draft';
    case PendingReview = 'pending_review';   // new account, awaiting moderation
    case Active        = 'active';
    case Reserved      = 'reserved';         // an offer was accepted, 72h hold
    case Sold          = 'sold';
    case Expired       = 'expired';
    case Removed       = 'removed';          // moderator action

    public function label(): string
    {
        return match ($this) {
            self::Draft         => 'Чернова',
            self::PendingReview => 'За одобрение',
            self::Active        => 'Активна',
            self::Reserved      => 'Запазена',
            self::Sold          => 'Продадена',
            self::Expired       => 'Изтекла',
            self::Removed       => 'Премахната',
        };
    }

    /** Visible to the public and eligible to receive offers. */
    public function isPubliclyVisible(): bool
    {
        return in_array($this, [self::Active, self::Reserved], true);
    }

    public function acceptsOffers(): bool
    {
        return $this === self::Active;
    }
}
