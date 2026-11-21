<?php

namespace App\Support;

/**
 * The spellings a Bulgarian actually types for a catalogue model.
 *
 * Bulgarians search in two alphabets, rarely in the manufacturer's own
 * spelling, and almost never with the marketing prefix. „GeForce RTX 4090" has
 * to answer to rtx 4090, ртх 4090, ртх4090 and 4090 alike, or the search box is
 * useless to the people it is for.
 *
 * It lived as a private method on PartSeeder. The importer needs exactly the
 * same answer, and a second copy would be the second place to forget a new
 * prefix - so it is one function here, and the seeder calls it.
 */
class PartAliases
{
    /**
     * @param  list<string>  $extra  hand-picked spellings to add verbatim
     * @return list<string>
     */
    public static function for(string $model, array $extra = []): array
    {
        $base  = mb_strtolower($model);
        $forms = [$base];

        // Drop the prefix nobody types.
        foreach ([
            '/^(geforce|radeon|arc)\s+/',   // "GeForce RTX 4090"    -> "rtx 4090"
            '/^ryzen\s+\d+\s+/',            // "Ryzen 7 7800X3D"     -> "7800x3d"
            '/^core\s+ultra\s+\d+\s+/',    // "Core Ultra 7 265K"   -> "265k"
            '/^core\s+/',                   // "Core i5-13600K"      -> "i5-13600k"
        ] as $pattern) {
            $stripped = preg_replace($pattern, '', $base);
            if ($stripped !== $base && $stripped !== '') {
                $forms[] = $stripped;
            }
        }

        // Dash, space and run-together spellings of every form so far.
        foreach ($forms as $f) {
            $forms[] = str_replace('-', ' ', $f);
            $forms[] = str_replace(['-', ' '], '', $f);
        }

        // A Cyrillic twin for each Latin form.
        foreach (array_unique($forms) as $f) {
            $cyr = Cyrillic::toCyrillic($f);
            if ($cyr !== $f) {
                $forms[] = $cyr;
                $forms[] = str_replace(' ', '', $cyr);
            }
        }

        foreach ($extra as $e) {
            $forms[] = mb_strtolower($e);
        }

        return array_values(array_unique(
            array_filter($forms, fn ($f) => mb_strlen($f) >= 2)
        ));
    }
}
