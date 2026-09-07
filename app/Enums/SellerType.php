<?php

namespace App\Enums;

/**
 * Legally load-bearing. ЗЗП / Omnibus Art. 6a requires the platform to state,
 * before the buyer is bound, whether the offeror declared themselves a trader,
 * and that consumer-protection law does not apply when they did not.
 */
enum SellerType: string
{
    case Private = 'private';
    case Trader  = 'trader';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Частно лице',
            self::Trader  => 'Търговец',
        };
    }

    /** Shown on every listing. Not optional, not collapsible. */
    public function consumerNotice(): string
    {
        return match ($this) {
            self::Private => 'Продавачът е частно лице. Законът за защита на '
                           . 'потребителите не се прилага за тази сделка.',
            self::Trader  => 'Продавачът е търговец. Прилагат се правата ви по '
                           . 'Закона за защита на потребителите.',
        };
    }

    public function requiresCompanyDetails(): bool
    {
        return $this === self::Trader;
    }
}
