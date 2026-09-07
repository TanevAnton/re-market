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
}
