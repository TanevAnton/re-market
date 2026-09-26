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
    case StolenClaim          = 'stolen_claim';           // confirmed report against this serial
    case DuplicateSerial      = 'duplicate_serial';       // same serial on another live listing
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
            self::StolenClaim           => 'Сигнал за кражба',
            self::DuplicateSerial       => 'Повторен сериен номер',
            self::Manual                => 'Ръчна проверка',
        };
    }

    /** Lower number = looked at first. Fraud beats housekeeping. */
    public function priority(): int
    {
        return match ($this) {
            /*
             * FIRST, ahead of a reused photograph. Somebody has filed a police
             * report and a listing on this site matches it — if anything here
             * deserves to be looked at before lunch, it is that.
             */
            self::StolenClaim           => 0,
            self::PhashCollision        => 1,
            self::DuplicateSerial       => 1,
            self::Reported              => 2,
            self::PriceOutlier          => 3,
            self::MissingTimestampPhoto => 4,
            self::ContactInfo           => 5,
            self::NewAccount            => 6,
            self::Manual                => 7,
        };
    }
}
