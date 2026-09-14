<?php

namespace App\Support;

/**
 * Latin hardware words and how Bulgarians write them in Cyrillic.
 *
 * A Bulgarian keyboard is very often still on Cyrillic when somebody starts
 * typing a Latin model name, so „ртх 4070" and „RTX 4070" are the same search
 * and the same model - and until the catalogue knows that, one of them finds
 * nothing.
 *
 * ONE map, in one place. It was previously a private method on PartSeeder and a
 * second, different private method on PartCatalogueSeeder, and the promotion
 * queue needed a third - at which point the site would have had three
 * disagreeing opinions about whether „про" is „pro", and which one applied
 * would depend on which screen you were looking at.
 *
 * TOKEN-BASED, deliberately not strtr(): a substring map rewrites „ti" inside
 * unrelated words and silently corrupts half the catalogue.
 */
class Cyrillic
{
    /** Latin => Cyrillic. The reverse direction is derived, never typed twice. */
    private const MAP = [
        // chips and chip families
        'geforce' => 'джифорс', 'radeon' => 'радеон', 'ryzen' => 'райзен',
        'core'    => 'кор',     'arc'    => 'арк',    'rtx'   => 'ртх',
        'gtx'     => 'гтх',     'rx'     => 'рх',     'super' => 'супер',
        'ti'      => 'ти',      'xtx'    => 'хтх',    'xt'    => 'хт',
        'ultra'   => 'ултра',

        // brands
        'asus'    => 'асус',     'msi'      => 'мси',       'gigabyte' => 'гигабайт',
        'asrock'  => 'асрок',    'corsair'  => 'корсар',    'kingston' => 'кингстон',
        'samsung' => 'самсунг',  'seagate'  => 'сигейт',    'toshiba'  => 'тошиба',
        'noctua'  => 'ноктуа',   'logitech' => 'логитек',   'razer'    => 'рейзър',
        'sony'    => 'сони',     'microsoft' => 'майкрософт', 'nintendo' => 'нинтендо',

        // product words
        'playstation' => 'плейстейшън', 'xbox' => 'иксбокс', 'switch' => 'суич',
        'tomahawk'    => 'томахоук',    'pro'  => 'про',     'evo'    => 'ево',
    ];

    public static function toCyrillic(string $s): string
    {
        return self::map($s, self::MAP);
    }

    /**
     * The other direction, which is what grouping needs: fold every spelling
     * onto one side so „ртх 4070" and „rtx 4070" land in the same bucket.
     *
     * array_flip rather than a second hand-written map - a reverse table typed
     * out separately is a table that will disagree with this one eventually.
     */
    public static function toLatin(string $s): string
    {
        return self::map($s, array_flip(self::MAP));
    }

    /** @param  array<string, string>  $map */
    private static function map(string $s, array $map): string
    {
        return implode(' ', array_map(
            fn (string $t): string => $map[$t] ?? $t,
            preg_split('/\s+/', mb_strtolower(trim($s))) ?: []
        ));
    }
}
