<?php

namespace App\Enums;

enum ModerationTrigger: string
{
    case NewAccount           = 'new_account';
    case PhashCollision       = 'phash_collision';       // photo reused from another listing
    case PriceOutlier         = 'price_outlier';
    case Reported             = 'reported';
    case MissingTimestampPhoto = 'missing_timestamp_photo';
    case ContactInfo          = 'contact_info';
    case Manual               = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::NewAccount            => 'Нов профил',
            self::PhashCollision        => 'Повторена снимка',
            self::PriceOutlier          => 'Съмнителна цена',
            self::Reported              => 'Докладвана',
            self::MissingTimestampPhoto => 'Липсва снимка с дата',
            self::ContactInfo           => 'Контакти в обявата',
            self::Manual                => 'Ръчна проверка',
        };
    }

    /** Lower number = looked at first. Fraud beats housekeeping. */
    public function priority(): int
    {
        return match ($this) {
            self::PhashCollision        => 1,
            self::Reported              => 2,
            self::PriceOutlier          => 3,
            self::MissingTimestampPhoto => 4,
            self::ContactInfo           => 5,
            self::NewAccount            => 6,
            self::Manual                => 7,
        };
    }
}
