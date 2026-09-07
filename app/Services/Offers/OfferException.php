<?php

namespace App\Services\Offers;

use RuntimeException;

/**
 * A refused offer action, with a message written for the person who tried it.
 *
 * The message is Bulgarian and user-facing on purpose: every one of these
 * corresponds to a rule the user just bumped into, and "нещо се обърка" would
 * teach them nothing about what to do next.
 */
class OfferException extends RuntimeException
{
    public static function notAllowed(): self
    {
        return new self('Тази обява не приема оферти в момента.');
    }

    public static function notYours(): self
    {
        return new self('Нямаш достъп до тази оферта.');
    }

    public static function notPending(): self
    {
        return new self('Офертата вече има отговор.');
    }

    public static function alreadyOpen(): self
    {
        return new self('Вече имаш активна оферта за тази обява. Оттегли я, за да пратиш нова.');
    }

    public static function tooMany(int $max): self
    {
        return new self("Достигна лимита от {$max} оферти за тази обява.");
    }

    public static function cooling(int $hours): self
    {
        return new self("Продавачът отказа офертата ти. Можеш да пробваш пак след {$hours} ч.");
    }

    public static function aboveAsking(): self
    {
        return new self('Офертата не може да е над исканата цена.');
    }

    public static function counterAlreadySent(): self
    {
        return new self('Вече прати насрещна оферта. Повече от една е пазарлък.');
    }

    public static function expired(): self
    {
        return new self('Офертата е изтекла.');
    }
}
