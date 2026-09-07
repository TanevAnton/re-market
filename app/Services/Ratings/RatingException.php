<?php

namespace App\Services\Ratings;

use RuntimeException;

class RatingException extends RuntimeException
{
    public static function notAllowed(): self
    {
        return new self('Не можеш да оцениш тази сделка.');
    }

    public static function badScore(): self
    {
        return new self('Оценката е от 1 до 5.');
    }

    public static function notYours(): self
    {
        return new self('Тази оценка не е за теб.');
    }

    public static function alreadyReplied(): self
    {
        return new self('Вече отговори на тази оценка. Един отговор е достатъчен.');
    }
}
