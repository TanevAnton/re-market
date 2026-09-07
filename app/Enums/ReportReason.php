<?php

namespace App\Enums;

/**
 * Why someone is reporting a listing or a user.
 *
 * A fixed list because DSA Art. 16 requires the notice to be precise enough to
 * act on, and because these are what get counted later. The free-text detail
 * carries the specifics; this carries the routing.
 */
enum ReportReason: string
{
    case Scam             = 'scam';
    case Stolen           = 'stolen';
    case Counterfeit      = 'counterfeit';
    case WrongCategory    = 'wrong_category';
    case Prohibited       = 'prohibited';
    case Offensive        = 'offensive';
    case Spam             = 'spam';
    case ContactInListing = 'contact_in_listing';
    case Other            = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Scam             => 'Измама',
            self::Stolen           => 'Краден хардуер',
            self::Counterfeit      => 'Фалшив или преправен продукт',
            self::WrongCategory    => 'Грешна категория',
            self::Prohibited       => 'Забранен артикул',
            self::Offensive        => 'Обидно съдържание',
            self::Spam             => 'Спам или дублирана обява',
            self::ContactInListing => 'Контакти в обявата',
            self::Other            => 'Друго',
        };
    }

    /**
     * How fast this needs looking at, relative to everything else in the queue.
     * A scam report costs somebody money today; a wrong category costs nobody
     * anything, and treating them the same is how the urgent ones wait.
     */
    public function priority(): int
    {
        return match ($this) {
            self::Scam, self::Stolen                 => 1,
            self::Counterfeit, self::Prohibited      => 2,
            self::Offensive                          => 3,
            self::Spam, self::ContactInListing       => 4,
            self::WrongCategory, self::Other         => 5,
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $r) => [$r->value => $r->label()])
            ->all();
    }
}
