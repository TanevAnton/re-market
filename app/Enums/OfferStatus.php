<?php

namespace App\Enums;

enum OfferStatus: string
{
    case Pending      = 'pending';
    case Accepted     = 'accepted';
    case Declined     = 'declined';
    case AutoDeclined = 'auto_declined';  // fell below the seller's private floor
    case Countered    = 'countered';
    case Expired      = 'expired';
    case Withdrawn    = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending      => 'Чака отговор',
            self::Accepted     => 'Приета',
            self::Declined     => 'Отказана',
            self::AutoDeclined => 'Под минимума на продавача',
            self::Countered    => 'Насрещна оферта',
            self::Expired      => 'Изтекла',
            self::Withdrawn    => 'Оттеглена',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /** Whether a decline of this kind starts the buyer's cooldown. */
    public function triggersCooldown(): bool
    {
        return in_array($this, [self::Declined, self::AutoDeclined], true);
    }
}
