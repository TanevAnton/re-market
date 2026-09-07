<?php

namespace App\Enums;

enum MiningUse: string
{
    case No      = 'no';
    case Yes     = 'yes';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::No      => 'Не е използвана за копане',
            self::Yes     => 'Използвана за копане',
            self::Unknown => 'Неизвестно',
        };
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::No      => 'positive',
            self::Yes     => 'warning',
            self::Unknown => 'neutral',
        };
    }

    public function allowsDuration(): bool
    {
        return $this === self::Yes;
    }
}
