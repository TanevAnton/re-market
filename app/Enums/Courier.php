<?php

namespace App\Enums;

/**
 * The three ways an item gets from one person to the other.
 *
 * WHY THIS EXISTS AT ALL. `['econt' => 'Еконт', 'speedy' => 'Спиди', 'pickup' =>
 * 'Лично предаване']` was written out inline in THREE views — create-listing,
 * edit-listing and show-listing — while `terms.blade.php` separately spelled out
 * each courier's name for its inspection service. Two copies of a lookup table
 * will disagree; four is a certainty. Everything either side of the handover
 * needs to know about a courier is here now.
 *
 * WHAT IS DELIBERATELY NOT HERE: anything that creates a shipment. RIGO does not
 * hold a contract with either courier and does not want one — the waybill is made
 * by the SELLER, in their own account or at the counter, because the moment RIGO
 * is the sender of record the cash-on-delivery is remitted to RIGO and the
 * platform becomes a party to the money. Three docblocks promise it is not. This
 * enum's job is to tell the seller exactly what to type, and to link the buyer to
 * the right tracking page. See App\Support\WaybillDraft.
 */
enum Courier: string
{
    case Econt  = 'econt';
    case Speedy = 'speedy';
    case Pickup = 'pickup';

    public function label(): string
    {
        return match ($this) {
            self::Econt  => 'Еконт',
            self::Speedy => 'Спиди',
            self::Pickup => 'Лично предаване',
        };
    }

    /**
     * Is there a parcel at all?
     *
     * Pickup is two people meeting, so it has no waybill, no tracking number and
     * no cash-on-delivery — and every screen that asks „where do I enter the
     * tracking number" has to know that before it draws a field nobody can fill.
     */
    public function ships(): bool
    {
        return $this !== self::Pickup;
    }

    /**
     * What this courier calls „open it before you pay".
     *
     * The two couriers use different names for the same service and both names
     * are already quoted in the published terms. A buyer told to ask for
     * „преглед и тест" at a Speedy counter is a buyer who gets a blank look, and
     * this is the one instruction on the whole site that has to survive contact
     * with a courier employee.
     */
    public function inspectTestName(): ?string
    {
        return match ($this) {
            self::Econt  => 'преглед и тест',
            self::Speedy => 'отвори и тествай',
            self::Pickup => null,
        };
    }

    public function site(): ?string
    {
        return match ($this) {
            self::Econt  => 'https://www.econt.com/',
            self::Speedy => 'https://www.speedy.bg/',
            self::Pickup => null,
        };
    }

    /**
     * Where the buyer goes to follow the parcel.
     *
     * DELIBERATELY A CONFIG VALUE WITH A PLACEHOLDER, and defaulting to the
     * courier's plain tracking page rather than a guessed query string. Both
     * couriers have a tracking page at a URL that has been verified; neither
     * one's deep-link parameter has been. A button that lands on an error page
     * is worse than no button, because it teaches the buyer to stop trusting the
     * screen — the same reason the moderation queue links to the register's home
     * page instead of a guessed search URL.
     *
     * When somebody confirms the real parameter, put it in `.env` with `{number}`
     * where the number goes and this starts deep-linking with no code change.
     * Until then the number is rendered beside the link, select-all, to paste.
     */
    public function trackingUrl(?string $number = null): ?string
    {
        $template = config('remarket.couriers.'.$this->value.'.tracking_url');

        if (! $template) {
            return null;
        }

        if ($number === null || ! str_contains($template, '{number}')) {
            return $template;
        }

        return str_replace('{number}', rawurlencode($number), $template);
    }

    /** True when the configured URL actually carries the number to the courier. */
    public function deepLinksTracking(): bool
    {
        return str_contains(
            (string) config('remarket.couriers.'.$this->value.'.tracking_url'),
            '{number}',
        );
    }

    /**
     * value => label, for every `@foreach` that used to carry its own copy.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $c) => $carry + [$c->value => $c->label()],
            [],
        );
    }

    /** Only the ones that produce a parcel — the delivery form's own list. */
    public static function shipping(): array
    {
        return array_filter(self::cases(), fn (self $c) => $c->ships());
    }

    /** Never throws: an unknown stored value renders as itself rather than a 500. */
    public static function labelFor(?string $value): string
    {
        return self::tryFrom((string) $value)?->label() ?? (string) $value;
    }
}
