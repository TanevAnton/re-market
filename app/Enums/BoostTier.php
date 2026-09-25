<?php

namespace App\Enums;

/**
 * The three things a seller can buy, and they are three because each one buys
 * a DIFFERENT KIND OF ATTENTION.
 *
 *   Bump      → recency. The listing jumps to the top of „най-нови".
 *   Highlight → salience. Same position, but the eye lands on it.
 *   Pin       → position. A reserved slot above the results.
 *
 * That is the whole reason the ladder works: they do not cannibalise each
 * other, so a seller can buy one, or stack all three, and every one of them
 * still does something the others do not. A ladder of „a bit more of the same,
 * for a bit more money" trains people to buy the cheapest and stop.
 *
 * NOT CALLED „PROMOTION" ON PURPOSE. `PartPromotion`, `PartPromotionService`
 * and `PartPromotionDismissal` already exist and mean something else entirely
 * — moving a seller-typed model string into the real catalogue. Two meanings
 * for one word in one codebase is how the wrong one gets changed.
 */
enum BoostTier: string
{
    case Bump      = 'bump';
    case Highlight = 'highlight';
    case Pin       = 'pin';
    case Front     = 'front';

    public function label(): string
    {
        return match ($this) {
            self::Bump      => 'Издигане',
            self::Highlight => 'Откроена обява',
            self::Pin       => 'Топ обява',
            self::Front     => 'Начална страница',
        };
    }

    public function blurb(): string
    {
        return match ($this) {
            self::Bump      => 'Обявата ти скача на първо място в „най-нови".',
            self::Highlight => 'Обявата се откроява в списъка, на мястото си.',
            self::Pin       => 'Обявата стои над резултатите в своята категория.',
            self::Front     => 'Обявата стои на началната страница, пред всички категории.',
        };
    }

    /**
     * How long the effect lasts, in days. Zero means instant and over.
     *
     * A bump is an EVENT, not a window: it moves `bumped_at` once and the
     * listing then sinks at the same rate as everyone else's. Modelling it as
     * a window would mean deciding what a „still running" bump does on every
     * subsequent query, which is nothing, forever.
     */
    public function days(): int
    {
        return match ($this) {
            self::Bump      => 0,
            self::Highlight => (int) config('remarket.boosts.highlight_days', 7),
            self::Pin       => (int) config('remarket.boosts.pin_days', 7),
            self::Front     => (int) config('remarket.boosts.front_days', 7),
        };
    }

    /**
     * Price in EURO cents, from config — a knob, not a constant.
     *
     * Euros because the whole site is in euros; `Listing::formattedPrice()`
     * has a test pinning that, after this codebase already shipped one screen
     * labelled „лв" by mistake. Roughly 3× between rungs so the ladder reads
     * as three different products rather than three sizes of one.
     */
    public function priceCents(): int
    {
        return (int) config('remarket.boosts.prices.'.$this->value, match ($this) {
            self::Bump      => 100,
            self::Highlight => 300,
            self::Pin       => 900,
            self::Front     => 2700,
        });
    }

    /**
     * Does this tier occupy a reserved slot IN A CATEGORY?
     *
     * Pin only, and Front deliberately does NOT count. They are two different
     * slots on two different pages and they are sold separately — folding
     * Front in here would hand every homepage buyer a category pin they did
     * not pay for, and quietly halve what the pin is worth to everyone who
     * did. `Boost::scopeFront()` is the other one.
     */
    public function isPinned(): bool
    {
        return $this === self::Pin;
    }

    /**
     * Does buying this change where the listing appears?
     *
     * The Omnibus Directive requires disclosing when ranking is influenced by
     * payment. Bump, Pin and Front all put a listing somewhere it would not
     * otherwise be; Highlight does not touch the order at all. Keeping that
     * distinction in one method means the labelling rules can read it instead
     * of restating it.
     */
    public function affectsRanking(): bool
    {
        return $this !== self::Highlight;
    }

    /** @return list<self> Cheapest first, which is the order they are sold in. */
    public static function ladder(): array
    {
        return [self::Bump, self::Highlight, self::Pin, self::Front];
    }
}
