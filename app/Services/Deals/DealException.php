<?php

namespace App\Services\Deals;

use RuntimeException;

/**
 * A refused deal action, phrased for the person who tried it.
 */
class DealException extends RuntimeException
{
    public static function notYours(): self
    {
        return new self('Нямаш достъп до тази сделка.');
    }

    public static function notOpen(): self
    {
        return new self('Сделката вече е приключена.');
    }

    public static function alreadyConfirmed(): self
    {
        return new self('Вече потвърди тази сделка.');
    }

    public static function lapsed(): self
    {
        return new self('Срокът на сделката изтече.');
    }

    public static function reasonRequired(): self
    {
        return new self('Кажи защо се отказваш - другата страна ще го види.');
    }

    // --- the handover -----------------------------------------------------

    public static function unknownCourier(): self
    {
        return new self('Избери начин на доставка.');
    }

    public static function courierNotOffered(string $label): self
    {
        return new self('Продавачът не предлага '.$label.' за тази обява.');
    }

    public static function deliveryIncomplete(): self
    {
        return new self('Избери до офис или до адрес.');
    }

    public static function trackingRequired(): self
    {
        return new self('Въведи номера на товарителницата.');
    }

    public static function nothingToTrack(): self
    {
        return new self('Тази сделка е с лично предаване - няма товарителница.');
    }
}
