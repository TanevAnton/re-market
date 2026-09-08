<?php

namespace App\Services\Messaging;

use RuntimeException;

class MessagingException extends RuntimeException
{
    public static function notYours(): self
    {
        return new self('Нямаш достъп до този разговор.');
    }

    public static function ownListing(): self
    {
        return new self('Това е твоята обява.');
    }

    public static function locked(): self
    {
        return new self('Разговорът е заключен от модератор.');
    }

    public static function tooFast(int $seconds): self
    {
        return new self("Изчакай {$seconds} сек. преди следващото съобщение.");
    }

    public static function emailUnverified(): self
    {
        return new self('Потвърди имейла си, за да пишеш съобщения.');
    }

    public static function unavailable(): self
    {
        return new self('Обявата вече не приема съобщения.');
    }
}
