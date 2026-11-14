<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Graphics-card series, by their original name.
 *
 * THE RULES LIVE IN PartSeries NOW. This was the whole implementation until
 * processors wanted the same menu; everything in it that was not specific to
 * graphics cards moved there, and what is left is the handful of calls that
 * already existed, each answered by asking PartSeries about the `gpu`
 * category.
 *
 * Kept rather than deleted so that the code written against it reads the way
 * it did - „GpuSeries::forChipset" says more at the call site than the general
 * form does. Nothing new should be added here: a rule about series belongs in
 * PartSeries, where every category gets it.
 */
class GpuSeries
{
    private static function series(): PartSeries
    {
        // `gpu` is defined in PartSeries for as long as this class exists; the
        // tests for both would fail together if it ever were not.
        return PartSeries::for('gpu');
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return self::series()->keys();
    }

    /** @return array{key: string, brand: string, label: string, pattern: string}|null */
    public static function find(string $key): ?array
    {
        return self::series()->find($key);
    }

    /** Which series a chipset belongs to, or null if none claims it. */
    public static function forChipset(string $chipset): ?string
    {
        return self::series()->forName($chipset);
    }

    public static function contains(string $key, string $chipset): bool
    {
        return self::series()->contains($key, $chipset);
    }

    /** Narrow a query on `parts` to one series. */
    public static function constrain(Builder $parts, string $key): Builder
    {
        return self::series()->constrain($parts, $key);
    }

    /**
     * Every graphics card in the catalogue, as part id => chipset.
     *
     * @return array<int, string>
     */
    public static function chipsetsByPart(): array
    {
        return self::series()->namesByPart();
    }
}
