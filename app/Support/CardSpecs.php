<?php

namespace App\Support;

use App\Models\Listing;

/**
 * The few specs a listing card prints under its title: „12 GB · 285 мм · 200 W".
 *
 * WHY THIS IS NOT „THE FIRST THREE SPECS". The card used to slice the first
 * three values off the part and print them raw, which for a graphics card was
 * the chipset (already the title), the board partner, and a bare „12" with no
 * unit. Which three you got depended on where a spec happened to sit in the
 * schema, and a number with no unit tells a buyer nothing.
 *
 * What a buyer scanning a grid actually needs is the handful of numbers that
 * decide „does this fit my machine and is it the one I mean": for a card the
 * memory, the length and the power draw; for a processor the socket and the
 * cores; for a disk the type and the size. They are listed per category below,
 * in the order they are worth reading, and the card takes the first three that
 * the listing actually has - so a laptop with no discrete GPU just moves on to
 * its processor instead of showing a hole.
 *
 * UNITS COME FROM THE CATALOGUE SCHEMA, not from here. config/catalog.php
 * already says that `length_mm` is „мм" and `tdp_w` is „W"; repeating that
 * here would be a second copy to drift. What this class adds is the few
 * places where „value + unit" is not how a person would write it (cores,
 * battery health, a terabyte), kept in FORMATS and FLAGS so they are visible
 * in one place rather than scattered through a view.
 *
 * A key that does not exist in the schema is a silent blank on every card in
 * that category, so CardSpecsTest checks every key named here against the
 * schema - the same guard the compatibility config has.
 */
class CardSpecs
{
    /** How many chips a card shows. More than three wraps to a second line. */
    public const LIMIT = 3;

    /**
     * Per category, in reading order. Longer than LIMIT on purpose: optional
     * specs are skipped when empty and the next one takes their place.
     *
     * @var array<string, list<string>>
     */
    private const KEYS = [
        'gpu'         => ['vram_gb', 'length_mm', 'tdp_w'],
        'cpu'         => ['socket', 'cores', 'boost_clock_ghz'],
        'motherboard' => ['socket', 'chipset', 'form_factor'],
        'ram'         => ['type', 'total_gb', 'speed_mts'],
        'psu'         => ['wattage', 'efficiency', 'modularity'],
        'storage'     => ['drive_type', 'capacity_gb', 'interface'],
        'monitor'     => ['size_inch', 'resolution', 'refresh_hz'],
        'cooler'      => ['cooler_type', 'radiator_mm', 'height_mm'],
        'case'        => ['max_gpu_mm', 'side_panel'],
        'laptop'      => ['gpu_model', 'cpu_model', 'ram_gb', 'storage_gb'],
        'keyboard'    => ['switch_type', 'layout', 'connection'],
        'mouse'       => ['connection', 'dpi', 'weight_g'],
        'headset'     => ['connection', 'has_mic', 'surround'],
        'console'     => ['platform', 'storage_gb', 'controllers'],
        'prebuilt'    => ['gpu_model', 'cpu_model', 'ram_gb', 'storage_gb'],
        'iphone'      => ['storage_gb', 'chip', 'battery_health'],
        'ipad'        => ['storage_gb', 'chip', 'battery_health'],
        'macbook'     => ['chip', 'ram_gb', 'storage_gb', 'battery_health'],
    ];

    /**
     * „category.key" => how to write the value, where {v} is the value.
     * Used instead of the schema unit when a unit would read wrongly.
     *
     * @var array<string, string>
     */
    private const FORMATS = [
        'cpu.cores'              => '{v} ядра',
        'case.max_gpu_mm'        => 'видеокарта до {v} мм',
        'mouse.dpi'              => '{v} DPI',
        'console.controllers'    => '{v} контролера',
        'iphone.battery_health'  => 'батерия {v}%',
        'ipad.battery_health'    => 'батерия {v}%',
        'macbook.battery_health' => 'батерия {v}%',
    ];

    /**
     * „category.key" => text to show when a yes/no spec is yes. A „не" is never
     * shown on a card: it is the absence of a feature, and an absence is not
     * something to spend a chip on.
     *
     * @var array<string, string>
     */
    private const FLAGS = [
        'headset.has_mic'  => 'с микрофон',
        'headset.surround' => 'съраунд',
    ];

    /**
     * The chips for one listing, ready to print.
     *
     * @return list<string>
     */
    public static function for(Listing $listing, int $limit = self::LIMIT): array
    {
        $category = $listing->part?->category ?? $listing->category;

        if (! is_string($category) || $category === '') {
            return [];
        }

        $schema = config("catalog.categories.{$category}.specs", []);

        // The part's values win over the listing's. They never overlap in a
        // healthy row (the schema gives every key one scope), but a listing
        // with no catalogue part keeps its part-scope values on itself.
        $values = array_merge((array) $listing->specs, (array) $listing->part?->specs);

        $keys  = self::KEYS[$category] ?? self::fallbackKeys($schema);
        $chips = [];

        foreach ($keys as $key) {
            if (! isset($schema[$key])) {
                continue;
            }

            $chip = self::chip($category, $key, $schema[$key], $values[$key] ?? null);

            if ($chip !== null) {
                $chips[] = $chip;
            }

            if (count($chips) === $limit) {
                break;
            }
        }

        return $chips;
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return array_keys(self::KEYS);
    }

    /** @return list<string> every key the map names, as „category.key". */
    public static function mappedKeys(): array
    {
        $out = [];

        foreach (self::KEYS as $category => $keys) {
            foreach ($keys as $key) {
                $out[] = "{$category}.{$key}";
            }
        }

        return $out;
    }

    /**
     * A category with no entry above - „other", or one added to the schema
     * tomorrow - gets its most important part-scope specs by the schema's own
     * `priority`, so a new category shows something sensible with no change here.
     * Lists and yes/no specs are left out: neither fits a chip.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @return list<string>
     */
    private static function fallbackKeys(array $schema): array
    {
        $eligible = array_filter(
            $schema,
            fn (array $spec) => ($spec['scope'] ?? 'part') === 'part'
                && ! in_array($spec['type'] ?? '', ['multiselect', 'bool'], true),
        );

        uasort($eligible, fn (array $a, array $b) => ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99));

        return array_keys($eligible);
    }

    /** @param  array<string, mixed>  $spec */
    private static function chip(string $category, string $key, array $spec, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_bool($value) || ($spec['type'] ?? '') === 'bool') {
            return $value ? (self::FLAGS["{$category}.{$key}"] ?? null) : null;
        }

        if (is_array($value)) {
            // Lists (supported sockets, ports) are too long for a chip.
            return null;
        }

        $text = self::number($value);

        if ($custom = self::FORMATS["{$category}.{$key}"] ?? null) {
            return str_replace('{v}', $text, $custom);
        }

        // A terabyte is how a disk is sold, and „1000 GB" reads as a typo.
        if (($spec['unit'] ?? null) === 'GB' && in_array($key, ['capacity_gb', 'storage_gb'], true)
            && is_numeric($value) && (float) $value >= 1000) {
            return self::number((float) $value / 1000).' TB';
        }

        $unit = $spec['unit'] ?? null;

        if ($unit === null || $unit === '') {
            return $text;
        }

        // 27" and 144 Hz: the inch mark hugs its number, the rest do not.
        return $unit === '"' ? $text.'"' : "{$text} {$unit}";
    }

    /** 5.40 -> „5.4", 12 -> „12", and anything that is not a number untouched. */
    private static function number(mixed $value): string
    {
        if (! is_numeric($value)) {
            return trim((string) $value);
        }

        $text = (string) (float) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
