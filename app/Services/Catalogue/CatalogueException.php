<?php

namespace App\Services\Catalogue;

use RuntimeException;

/**
 * NOTE ON THE QUOTE MARKS below: Bulgarian typography closes a quotation with
 * U+201C, which looks like an ASCII double quote in most editors and is not
 * one. Dropped into a double-quoted PHP string the real thing is fine and the
 * ASCII lookalike ends the string mid-sentence - a parse error whose message
 * points at the Cyrillic word after it. Single quotes here sidestep the whole
 * question; QUOTE_OPEN / QUOTE_CLOSE make which character is meant explicit.
 */
class CatalogueException extends RuntimeException
{
    private const QUOTE_OPEN  = "\u{201E}";
    private const QUOTE_CLOSE = "\u{201C}";

    private static function quoted(string $s): string
    {
        return self::QUOTE_OPEN.$s.self::QUOTE_CLOSE;
    }

    public static function alreadyExists(string $name): self
    {
        return new self(self::quoted($name).' вече съществува в каталога. '
            .'Закачи обявите за него, вместо да създаваш нов модел.');
    }

    public static function unknownCategory(string $category): self
    {
        return new self(self::quoted($category).' не е категория от каталога.');
    }

    public static function nothingToAttach(): self
    {
        return new self('Няма обяви за закачане — вероятно някой вече ги е обработил.');
    }

    public static function needsAModel(): self
    {
        return new self('Моделът е задължителен — това е името, под което ще се търси.');
    }
}
