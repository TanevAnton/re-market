<?php

namespace App\Enums;

/**
 * What a support ticket can be about.
 *
 * NOTE WHAT IS MISSING: there is no „измама" and no „проблем с продавач".
 * That is not an oversight — those two belong to the DSA notice form
 * (`/signali`, App\Livewire\Reports\ReportForm), which is legally clocked and
 * produces a statement of reasons. Offering them here would turn a regulated
 * notice into an untracked help request, and nobody would notice until
 * somebody asked for the numbers.
 *
 * The list is short on purpose. A dropdown with fifteen options is one a person
 * reads past rather than uses, and every option that does not change how the
 * ticket is handled is an option that only makes the form longer.
 */
enum TicketTopic: string
{
    case Account  = 'account';
    case Listing  = 'listing';
    case Payment  = 'payment';
    case Boost    = 'boost';
    case Delivery = 'delivery';
    case Bug      = 'bug';
    case Other    = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Account  => 'Профил и вход',
            self::Listing  => 'Публикуване на обява',
            self::Payment  => 'Плащане и фактура',
            self::Boost    => 'Платена видимост',
            self::Delivery => 'Доставка и куриер',
            self::Bug      => 'Нещо не работи',
            self::Other    => 'Друго',
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
