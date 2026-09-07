<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starter catalogue: chipset-level entries for the cards and processors that
 * actually change hands in Bulgaria.
 *
 * CAVEAT ON length_mm: these are reference / Founders Edition figures. Real
 * board-partner cards vary by 40 mm or more, so a chipset row is a starting
 * point, not the truth about a specific unit. The intended path is that the
 * admin promotion tool grows variant-level rows ("ASUS TUF RTX 4070 OC") with
 * exact dimensions as they appear in listings.
 */
class PartSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedGpus();
        $this->seedCpus();
    }

    private function seedGpus(): void
    {
        // [model, year, chipset, vram, vram_type, tdp, length, connectors, extra aliases]
        $gpus = [
            // --- NVIDIA RTX 50 ---
            ['GeForce RTX 5090', 2025, 'RTX 5090', 32, 'GDDR7', 575, 304, '1x 12V-2x6', ['5090']],
            ['GeForce RTX 5080', 2025, 'RTX 5080', 16, 'GDDR7', 360, 304, '1x 12V-2x6', ['5080']],
            ['GeForce RTX 5070 Ti', 2025, 'RTX 5070 Ti', 16, 'GDDR7', 300, 300, '1x 12V-2x6', ['5070ti', '5070 тi']],
            ['GeForce RTX 5070', 2025, 'RTX 5070', 12, 'GDDR7', 250, 242, '1x 12V-2x6', ['5070']],
            ['GeForce RTX 5060 Ti', 2025, 'RTX 5060 Ti', 16, 'GDDR7', 180, 242, '1x 8-pin', ['5060ti']],
            ['GeForce RTX 5060', 2025, 'RTX 5060', 8, 'GDDR7', 145, 242, '1x 8-pin', ['5060']],

            // --- NVIDIA RTX 40 ---
            ['GeForce RTX 4090', 2022, 'RTX 4090', 24, 'GDDR6X', 450, 304, '1x 12VHPWR', ['4090']],
            ['GeForce RTX 4080 SUPER', 2024, 'RTX 4080 SUPER', 16, 'GDDR6X', 320, 304, '1x 12VHPWR', ['4080 super', '4080с']],
            ['GeForce RTX 4080', 2022, 'RTX 4080', 16, 'GDDR6X', 320, 304, '1x 12VHPWR', ['4080']],
            ['GeForce RTX 4070 Ti SUPER', 2024, 'RTX 4070 Ti SUPER', 16, 'GDDR6X', 285, 267, '1x 12VHPWR', ['4070 ti super', '4070тис']],
            ['GeForce RTX 4070 Ti', 2023, 'RTX 4070 Ti', 12, 'GDDR6X', 285, 267, '1x 12VHPWR', ['4070ti', '4070 тi']],
            ['GeForce RTX 4070 SUPER', 2024, 'RTX 4070 SUPER', 12, 'GDDR6X', 220, 244, '1x 12VHPWR', ['4070 super', '4070с']],
            ['GeForce RTX 4070', 2023, 'RTX 4070', 12, 'GDDR6X', 200, 244, '1x 12VHPWR', ['4070', '4070-ка']],
            ['GeForce RTX 4060 Ti 16GB', 2023, 'RTX 4060 Ti', 16, 'GDDR6', 165, 240, '1x 8-pin', ['4060ti 16']],
            ['GeForce RTX 4060 Ti', 2023, 'RTX 4060 Ti', 8, 'GDDR6', 160, 240, '1x 8-pin', ['4060ti']],
            ['GeForce RTX 4060', 2023, 'RTX 4060', 8, 'GDDR6', 115, 240, '1x 8-pin', ['4060']],

            // --- NVIDIA RTX 30 (the used-market workhorses) ---
            ['GeForce RTX 3090 Ti', 2022, 'RTX 3090 Ti', 24, 'GDDR6X', 450, 313, '1x 12VHPWR', ['3090ti']],
            ['GeForce RTX 3090', 2020, 'RTX 3090', 24, 'GDDR6X', 350, 313, '2x 8-pin', ['3090']],
            ['GeForce RTX 3080 Ti', 2021, 'RTX 3080 Ti', 12, 'GDDR6X', 350, 285, '2x 8-pin', ['3080ti']],
            ['GeForce RTX 3080 12GB', 2022, 'RTX 3080', 12, 'GDDR6X', 350, 285, '2x 8-pin', ['3080 12']],
            ['GeForce RTX 3080', 2020, 'RTX 3080', 10, 'GDDR6X', 320, 285, '2x 8-pin', ['3080']],
            ['GeForce RTX 3070 Ti', 2021, 'RTX 3070 Ti', 8, 'GDDR6X', 290, 267, '2x 8-pin', ['3070ti']],
            ['GeForce RTX 3070', 2020, 'RTX 3070', 8, 'GDDR6', 220, 242, '1x 8-pin', ['3070']],
            ['GeForce RTX 3060 Ti', 2020, 'RTX 3060 Ti', 8, 'GDDR6', 200, 242, '1x 8-pin', ['3060ti']],
            ['GeForce RTX 3060 12GB', 2021, 'RTX 3060', 12, 'GDDR6', 170, 242, '1x 8-pin', ['3060 12']],
            ['GeForce RTX 3050', 2022, 'RTX 3050', 8, 'GDDR6', 130, 200, '1x 8-pin', ['3050']],

            // --- NVIDIA RTX 20 / GTX ---
            ['GeForce RTX 2080 Ti', 2018, 'RTX 2080 Ti', 11, 'GDDR6', 250, 267, '6+8-pin', ['2080ti']],
            ['GeForce RTX 2070 SUPER', 2019, 'RTX 2070 SUPER', 8, 'GDDR6', 215, 267, '6+8-pin', ['2070 super']],
            ['GeForce RTX 2060', 2019, 'RTX 2060', 6, 'GDDR6', 160, 229, '1x 8-pin', ['2060']],
            ['GeForce GTX 1660 SUPER', 2019, 'GTX 1660 SUPER', 6, 'GDDR6', 125, 229, '1x 8-pin', ['1660 super', '1660с']],
            ['GeForce GTX 1650', 2019, 'GTX 1650', 4, 'GDDR5', 75, 145, 'Няма', ['1650']],
            ['GeForce GTX 1080 Ti', 2017, 'GTX 1080 Ti', 11, 'GDDR5X', 250, 267, '6+8-pin', ['1080ti']],
            ['GeForce GTX 1080', 2016, 'GTX 1080', 8, 'GDDR5X', 180, 267, '1x 8-pin', ['1080']],
            ['GeForce GTX 1070', 2016, 'GTX 1070', 8, 'GDDR5', 150, 267, '1x 8-pin', ['1070']],
            ['GeForce GTX 1060 6GB', 2016, 'GTX 1060', 6, 'GDDR5', 120, 250, '1x 6-pin', ['1060 6']],
            ['GeForce GTX 1050 Ti', 2016, 'GTX 1050 Ti', 4, 'GDDR5', 75, 145, 'Няма', ['1050ti']],

            // --- AMD RX 9000 / 7000 ---
            ['Radeon RX 9070 XT', 2025, 'RX 9070 XT', 16, 'GDDR6', 304, 287, '2x 8-pin', ['9070xt', '9070хт']],
            ['Radeon RX 9070', 2025, 'RX 9070', 16, 'GDDR6', 220, 287, '2x 8-pin', ['9070']],
            ['Radeon RX 9060 XT 16GB', 2025, 'RX 9060 XT', 16, 'GDDR6', 160, 250, '1x 8-pin', ['9060xt']],
            ['Radeon RX 7900 XTX', 2022, 'RX 7900 XTX', 24, 'GDDR6', 355, 287, '2x 8-pin', ['7900xtx', '7900хтх']],
            ['Radeon RX 7900 XT', 2022, 'RX 7900 XT', 20, 'GDDR6', 315, 276, '2x 8-pin', ['7900xt']],
            ['Radeon RX 7900 GRE', 2024, 'RX 7900 GRE', 16, 'GDDR6', 260, 276, '2x 8-pin', ['7900gre']],
            ['Radeon RX 7800 XT', 2023, 'RX 7800 XT', 16, 'GDDR6', 263, 267, '2x 8-pin', ['7800xt', '7800хт']],
            ['Radeon RX 7700 XT', 2023, 'RX 7700 XT', 12, 'GDDR6', 245, 267, '2x 8-pin', ['7700xt']],
            ['Radeon RX 7600', 2023, 'RX 7600', 8, 'GDDR6', 165, 204, '1x 8-pin', ['7600']],

            // --- AMD RX 6000 / 5000 ---
            ['Radeon RX 6950 XT', 2022, 'RX 6950 XT', 16, 'GDDR6', 335, 267, '2x 8-pin', ['6950xt']],
            ['Radeon RX 6800 XT', 2020, 'RX 6800 XT', 16, 'GDDR6', 300, 267, '2x 8-pin', ['6800xt']],
            ['Radeon RX 6800', 2020, 'RX 6800', 16, 'GDDR6', 250, 267, '2x 8-pin', ['6800']],
            ['Radeon RX 6700 XT', 2021, 'RX 6700 XT', 12, 'GDDR6', 230, 267, '6+8-pin', ['6700xt', '6700хт']],
            ['Radeon RX 6650 XT', 2022, 'RX 6650 XT', 8, 'GDDR6', 180, 240, '1x 8-pin', ['6650xt']],
            ['Radeon RX 6600', 2021, 'RX 6600', 8, 'GDDR6', 132, 200, '1x 8-pin', ['6600']],
            ['Radeon RX 5700 XT', 2019, 'RX 5700 XT', 8, 'GDDR6', 225, 272, '6+8-pin', ['5700xt', '5700хт']],
            ['Radeon RX 580 8GB', 2017, 'RX 580', 8, 'GDDR5', 185, 241, '1x 8-pin', ['580', 'рх 580']],

            // --- Intel Arc ---
            ['Arc B580', 2024, 'Arc B580', 12, 'GDDR6', 190, 272, '1x 8-pin', ['b580']],
            ['Arc A770 16GB', 2022, 'Arc A770', 16, 'GDDR6', 225, 280, '6+8-pin', ['a770']],
            ['Arc A750', 2022, 'Arc A750', 8, 'GDDR6', 225, 280, '6+8-pin', ['a750']],
        ];

        $rows = [];
        foreach ($gpus as [$model, $year, $chipset, $vram, $vramType, $tdp, $len, $conn, $extra]) {
            $mfr = str_starts_with($model, 'Radeon') ? 'AMD'
                 : (str_starts_with($model, 'Arc') ? 'Intel' : 'NVIDIA');

            $rows[] = [
                'category'     => 'gpu',
                'manufacturer' => $mfr,
                'model'        => $model,
                'variant'      => null,
                'slug'         => Str::slug($mfr.' '.$model),
                'launch_year'  => $year,
                'specs'        => json_encode([
                    'chipset'          => $chipset,
                    'vram_gb'          => $vram,
                    'vram_type'        => $vramType,
                    'tdp_w'            => $tdp,
                    'length_mm'        => $len,
                    'power_connectors' => $conn,
                ], JSON_UNESCAPED_UNICODE),
                'aliases'      => json_encode($this->aliasesFor($model, $extra), JSON_UNESCAPED_UNICODE),
                'is_published' => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        DB::table('parts')->upsert($rows, ['slug'], ['specs', 'aliases', 'launch_year']);
        $this->command->info('Seeded '.count($rows).' GPUs.');
    }

    private function seedCpus(): void
    {
        // [model, year, socket, cores, threads, boost GHz, tdp, iGPU, aliases]
        $cpus = [
            // AMD AM5
            ['Ryzen 9 9950X3D', 2025, 'AM5', 16, 32, 5.7, 170, true,  ['9950x3d']],
            ['Ryzen 7 9800X3D', 2024, 'AM5', 8, 16, 5.2, 120, true,  ['9800x3d', '9800х3д']],
            ['Ryzen 7 9700X', 2024, 'AM5', 8, 16, 5.5, 65,  true,  ['9700x']],
            ['Ryzen 5 9600X', 2024, 'AM5', 6, 12, 5.4, 65,  true,  ['9600x']],
            ['Ryzen 9 7950X3D', 2023, 'AM5', 16, 32, 5.7, 120, true, ['7950x3d']],
            ['Ryzen 7 7800X3D', 2023, 'AM5', 8, 16, 5.0, 120, true, ['7800x3d', '7800х3д']],
            ['Ryzen 9 7950X', 2022, 'AM5', 16, 32, 5.7, 170, true,  ['7950x']],
            ['Ryzen 9 7900X', 2022, 'AM5', 12, 24, 5.6, 170, true,  ['7900x']],
            ['Ryzen 7 7700X', 2022, 'AM5', 8, 16, 5.4, 105, true,   ['7700x']],
            ['Ryzen 5 7600X', 2022, 'AM5', 6, 12, 5.3, 105, true,   ['7600x']],
            ['Ryzen 5 7600', 2023, 'AM5', 6, 12, 5.1, 65, true,     ['7600']],
            ['Ryzen 7 8700G', 2024, 'AM5', 8, 16, 5.1, 65, true,    ['8700g']],

            // AMD AM4 - still the biggest used market in Bulgaria
            ['Ryzen 7 5800X3D', 2022, 'AM4', 8, 16, 4.5, 105, false, ['5800x3d', '5800х3д']],
            ['Ryzen 9 5950X', 2020, 'AM4', 16, 32, 4.9, 105, false,  ['5950x']],
            ['Ryzen 9 5900X', 2020, 'AM4', 12, 24, 4.8, 105, false,  ['5900x']],
            ['Ryzen 7 5800X', 2020, 'AM4', 8, 16, 4.7, 105, false,   ['5800x']],
            ['Ryzen 7 5700X3D', 2024, 'AM4', 8, 16, 4.1, 105, false, ['5700x3d']],
            ['Ryzen 7 5700X', 2022, 'AM4', 8, 16, 4.6, 65, false,    ['5700x']],
            ['Ryzen 5 5600X', 2020, 'AM4', 6, 12, 4.6, 65, false,    ['5600x']],
            ['Ryzen 5 5600', 2022, 'AM4', 6, 12, 4.4, 65, false,     ['5600']],
            ['Ryzen 5 5500', 2022, 'AM4', 6, 12, 4.2, 65, false,     ['5500']],
            ['Ryzen 7 3700X', 2019, 'AM4', 8, 16, 4.4, 65, false,    ['3700x']],
            ['Ryzen 5 3600', 2019, 'AM4', 6, 12, 4.2, 65, false,     ['3600']],
            ['Ryzen 5 2600', 2018, 'AM4', 6, 12, 3.9, 65, false,     ['2600']],

            // Intel LGA1851
            ['Core Ultra 9 285K', 2024, 'LGA1851', 24, 24, 5.7, 125, true, ['285k']],
            ['Core Ultra 7 265K', 2024, 'LGA1851', 20, 20, 5.5, 125, true, ['265k']],
            ['Core Ultra 5 245K', 2024, 'LGA1851', 14, 14, 5.2, 125, true, ['245k']],

            // Intel LGA1700
            ['Core i9-14900K', 2023, 'LGA1700', 24, 32, 6.0, 125, true,  ['14900k']],
            ['Core i7-14700K', 2023, 'LGA1700', 20, 28, 5.6, 125, true,  ['14700k']],
            ['Core i5-14600K', 2023, 'LGA1700', 14, 20, 5.3, 125, true,  ['14600k']],
            ['Core i9-13900K', 2022, 'LGA1700', 24, 32, 5.8, 125, true,  ['13900k']],
            ['Core i7-13700K', 2022, 'LGA1700', 16, 24, 5.4, 125, true,  ['13700k']],
            ['Core i5-13600K', 2022, 'LGA1700', 14, 20, 5.1, 125, true,  ['13600k']],
            ['Core i5-13400F', 2023, 'LGA1700', 10, 16, 4.6, 65, false,  ['13400f']],
            ['Core i9-12900K', 2021, 'LGA1700', 16, 24, 5.2, 125, true,  ['12900k']],
            ['Core i7-12700K', 2021, 'LGA1700', 12, 20, 5.0, 125, true,  ['12700k']],
            ['Core i5-12600K', 2021, 'LGA1700', 10, 16, 4.9, 125, true,  ['12600k']],
            ['Core i5-12400F', 2022, 'LGA1700', 6, 12, 4.4, 65, false,   ['12400f']],

            // Intel LGA1200 / LGA1151
            ['Core i7-11700K', 2021, 'LGA1200', 8, 16, 5.0, 125, true, ['11700k']],
            ['Core i7-10700K', 2020, 'LGA1200', 8, 16, 5.1, 125, true, ['10700k']],
            ['Core i5-10400F', 2020, 'LGA1200', 6, 12, 4.3, 65, false, ['10400f']],
            ['Core i9-9900K', 2018, 'LGA1151', 8, 16, 5.0, 95, true,   ['9900k']],
            ['Core i7-9700K', 2018, 'LGA1151', 8, 8, 4.9, 95, true,    ['9700k']],
            ['Core i7-8700K', 2017, 'LGA1151', 6, 12, 4.7, 95, true,   ['8700k']],
            ['Core i5-8400', 2017, 'LGA1151', 6, 6, 4.0, 65, true,     ['8400']],
            ['Core i7-7700K', 2017, 'LGA1151', 4, 8, 4.5, 91, true,    ['7700k']],
        ];

        $rows = [];
        foreach ($cpus as [$model, $year, $socket, $cores, $threads, $boost, $tdp, $igpu, $extra]) {
            $mfr = str_starts_with($model, 'Ryzen') ? 'AMD' : 'Intel';

            $rows[] = [
                'category'     => 'cpu',
                'manufacturer' => $mfr,
                'model'        => $model,
                'variant'      => null,
                'slug'         => Str::slug($mfr.' '.$model),
                'launch_year'  => $year,
                'specs'        => json_encode([
                    'socket'          => $socket,
                    'cores'           => $cores,
                    'threads'         => $threads,
                    'boost_clock_ghz' => $boost,
                    'tdp_w'           => $tdp,
                    'igpu'            => $igpu,
                ], JSON_UNESCAPED_UNICODE),
                'aliases'      => json_encode($this->aliasesFor($model, $extra), JSON_UNESCAPED_UNICODE),
                'is_published' => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        DB::table('parts')->upsert($rows, ['slug'], ['specs', 'aliases', 'launch_year']);
        $this->command->info('Seeded '.count($rows).' CPUs.');
    }

    /**
     * Bulgarians search in two alphabets, rarely in the manufacturer's own
     * spelling, and almost never with the marketing prefix. "GeForce RTX 4090"
     * has to answer to rtx 4090, ртх 4090, ртх4090 and 4090 alike, or the
     * search box is useless to the people it is for.
     */
    private function aliasesFor(string $model, array $extra = []): array
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
            $cyr = $this->toCyrillic($f);
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

    /**
     * Token-based, deliberately NOT strtr(): a substring map would rewrite
     * "ti" inside unrelated words and silently corrupt half the catalogue.
     */
    private function toCyrillic(string $s): string
    {
        $map = [
            'geforce' => 'джифорс', 'radeon' => 'радеон', 'ryzen' => 'райзен',
            'core'    => 'кор',     'arc'    => 'арк',    'rtx'   => 'ртх',
            'gtx'     => 'гтх',     'rx'     => 'рх',     'super' => 'супер',
            'ti'      => 'ти',      'xtx'    => 'хтх',    'xt'    => 'хт',
            'ultra'   => 'ултра',
        ];

        return implode(' ', array_map(
            fn ($t) => $map[$t] ?? $t,
            preg_split('/\s+/', trim($s))
        ));
    }
}
