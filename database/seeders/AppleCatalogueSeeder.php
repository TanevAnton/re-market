<?php

namespace Database\Seeders;

use App\Support\Cyrillic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * iPhone, iPad and MacBook — one row per CONFIGURATION.
 *
 * After graphics cards, Apple is the most catalogueable hardware there is: a
 * small, fixed, published SKU list where the configuration is most of the
 * price. That is exactly what a price band needs and exactly what a free-text
 * classifieds listing throws away.
 *
 * WHY STORAGE AND RAM SIT IN `variant`. A 128GB iPhone 13 Pro and a 1TB one are
 * three hundred euros apart; an 8/256 MacBook Air and a 16/512 are four hundred.
 * One shared median would be useless to both sides of every one of those
 * trades. Same reasoning as „GeForce RTX 3080 12GB" being its own row rather
 * than a footnote on the 10GB — except here it applies to every single model,
 * which is why this seeder is mostly a list of configurations.
 *
 * The unique index is (manufacturer, model, variant), so the model stays
 * „iPhone 13 Pro" and the configuration goes in the variant. `fullName()` then
 * reads „Apple iPhone 13 Pro 256GB", searching „13 pro" finds all four storage
 * rows, and the buyer picks the one they want.
 *
 * WHERE THIS STOPS, and why that is fine. The newest models here are the 2024
 * generation. Anything later should be added — but the site no longer depends
 * on somebody remembering to: a seller listing a model that is not here types
 * its name, `custom_part` records it, and it surfaces in the promotion queue at
 * /katalog with the spellings people actually used. This seeder is the floor,
 * not the ceiling.
 *
 * FIGURES ARE MANUFACTURER SPECIFICATIONS for the model. Battery health, cycle
 * counts and replaced parts belong to the individual device and are listing-
 * scoped — see config/catalog.php for why that line is drawn where it is.
 */
class AppleCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedIphones();
        $this->seedIpads();
        $this->seedMacbooks();
    }

    // --- iPhone -----------------------------------------------------------

    private function seedIphones(): void
    {
        // [model, year, chip, screen, connector, biometrics, 5G, [storage…]]
        $models = [
            ['iPhone 11', 2019, 'A13 Bionic', 6.1, 'Lightning', 'Face ID', false, [64, 128, 256]],
            ['iPhone 11 Pro', 2019, 'A13 Bionic', 5.8, 'Lightning', 'Face ID', false, [64, 256, 512]],
            ['iPhone 11 Pro Max', 2019, 'A13 Bionic', 6.5, 'Lightning', 'Face ID', false, [64, 256, 512]],
            ['iPhone SE (2020)', 2020, 'A13 Bionic', 4.7, 'Lightning', 'Touch ID', false, [64, 128, 256]],

            ['iPhone 12 mini', 2020, 'A14 Bionic', 5.4, 'Lightning', 'Face ID', true, [64, 128, 256]],
            ['iPhone 12', 2020, 'A14 Bionic', 6.1, 'Lightning', 'Face ID', true, [64, 128, 256]],
            ['iPhone 12 Pro', 2020, 'A14 Bionic', 6.1, 'Lightning', 'Face ID', true, [128, 256, 512]],
            ['iPhone 12 Pro Max', 2020, 'A14 Bionic', 6.7, 'Lightning', 'Face ID', true, [128, 256, 512]],

            ['iPhone 13 mini', 2021, 'A15 Bionic', 5.4, 'Lightning', 'Face ID', true, [128, 256, 512]],
            ['iPhone 13', 2021, 'A15 Bionic', 6.1, 'Lightning', 'Face ID', true, [128, 256, 512]],
            ['iPhone 13 Pro', 2021, 'A15 Bionic', 6.1, 'Lightning', 'Face ID', true, [128, 256, 512, 1024]],
            ['iPhone 13 Pro Max', 2021, 'A15 Bionic', 6.7, 'Lightning', 'Face ID', true, [128, 256, 512, 1024]],

            ['iPhone SE (2022)', 2022, 'A15 Bionic', 4.7, 'Lightning', 'Touch ID', true, [64, 128, 256]],
            ['iPhone 14', 2022, 'A15 Bionic', 6.1, 'Lightning', 'Face ID', true, [128, 256, 512]],
            ['iPhone 14 Plus', 2022, 'A15 Bionic', 6.7, 'Lightning', 'Face ID', true, [128, 256, 512]],
            ['iPhone 14 Pro', 2022, 'A16 Bionic', 6.1, 'Lightning', 'Face ID', true, [128, 256, 512, 1024]],
            ['iPhone 14 Pro Max', 2022, 'A16 Bionic', 6.7, 'Lightning', 'Face ID', true, [128, 256, 512, 1024]],

            ['iPhone 15', 2023, 'A16 Bionic', 6.1, 'USB-C', 'Face ID', true, [128, 256, 512]],
            ['iPhone 15 Plus', 2023, 'A16 Bionic', 6.7, 'USB-C', 'Face ID', true, [128, 256, 512]],
            ['iPhone 15 Pro', 2023, 'A17 Pro', 6.1, 'USB-C', 'Face ID', true, [128, 256, 512, 1024]],
            ['iPhone 15 Pro Max', 2023, 'A17 Pro', 6.7, 'USB-C', 'Face ID', true, [256, 512, 1024]],

            ['iPhone 16', 2024, 'A18', 6.1, 'USB-C', 'Face ID', true, [128, 256, 512]],
            ['iPhone 16 Plus', 2024, 'A18', 6.7, 'USB-C', 'Face ID', true, [128, 256, 512]],
            ['iPhone 16 Pro', 2024, 'A18 Pro', 6.3, 'USB-C', 'Face ID', true, [128, 256, 512, 1024]],
            ['iPhone 16 Pro Max', 2024, 'A18 Pro', 6.9, 'USB-C', 'Face ID', true, [256, 512, 1024]],
        ];

        $rows = [];

        foreach ($models as [$model, $year, $chip, $screen, $connector, $biometrics, $fiveG, $storages]) {
            foreach ($storages as $storage) {
                $rows[] = $this->row('iphone', $model, $this->capacity($storage), $year, [
                    'storage_gb'  => $storage,
                    'chip'        => $chip,
                    'screen_inch' => $screen,
                    'connector'   => $connector,
                    'biometrics'  => $biometrics,
                    'five_g'      => $fiveG,
                ]);
            }
        }

        $this->insert('iphone', $rows);
    }

    // --- iPad -------------------------------------------------------------

    private function seedIpads(): void
    {
        // [model, year, chip, screen, connector, pencil, [storage…]]
        //
        // Wi-Fi and Wi-Fi + Cellular are separate SKUs at separate prices, so
        // each configuration below becomes two rows. That doubling is the point:
        // a cellular iPad carries a hundred-euro premium that a shared median
        // would quietly average away.
        $models = [
            ['iPad (9-то поколение)', 2021, 'A13 Bionic', 10.2, 'Lightning', 'Pencil 1', [64, 256]],
            ['iPad (10-то поколение)', 2022, 'A14 Bionic', 10.9, 'USB-C', 'Pencil USB-C', [64, 256]],

            ['iPad Air 4', 2020, 'A14 Bionic', 10.9, 'USB-C', 'Pencil 2', [64, 256]],
            ['iPad Air 5', 2022, 'M1', 10.9, 'USB-C', 'Pencil 2', [64, 256]],
            ['iPad Air 11" (M2)', 2024, 'M2', 11.0, 'USB-C', 'Pencil Pro', [128, 256, 512]],
            ['iPad Air 13" (M2)', 2024, 'M2', 13.0, 'USB-C', 'Pencil Pro', [128, 256, 512]],

            ['iPad mini 6', 2021, 'A15 Bionic', 8.3, 'USB-C', 'Pencil 2', [64, 256]],

            ['iPad Pro 11" (M1)', 2021, 'M1', 11.0, 'USB-C', 'Pencil 2', [128, 256, 512, 1024]],
            ['iPad Pro 12.9" (M1)', 2021, 'M1', 12.9, 'USB-C', 'Pencil 2', [128, 256, 512, 1024]],
            ['iPad Pro 11" (M2)', 2022, 'M2', 11.0, 'USB-C', 'Pencil 2', [128, 256, 512, 1024]],
            ['iPad Pro 12.9" (M2)', 2022, 'M2', 12.9, 'USB-C', 'Pencil 2', [128, 256, 512, 1024]],
            ['iPad Pro 11" (M4)', 2024, 'M4', 11.0, 'USB-C', 'Pencil Pro', [256, 512, 1024]],
            ['iPad Pro 13" (M4)', 2024, 'M4', 13.0, 'USB-C', 'Pencil Pro', [256, 512, 1024]],
        ];

        $rows = [];

        foreach ($models as [$model, $year, $chip, $screen, $connector, $pencil, $storages]) {
            foreach ($storages as $storage) {
                foreach ([false, true] as $cellular) {
                    $variant = $this->capacity($storage).($cellular ? ' Cellular' : ' Wi-Fi');

                    $rows[] = $this->row('ipad', $model, $variant, $year, [
                        'storage_gb'     => $storage,
                        'chip'           => $chip,
                        'cellular'       => $cellular,
                        'screen_inch'    => $screen,
                        'pencil_support' => $pencil,
                        'connector'      => $connector,
                    ]);
                }
            }
        }

        $this->insert('ipad', $rows);
    }

    // --- MacBook ----------------------------------------------------------

    private function seedMacbooks(): void
    {
        // [model, year, chip, screen, keyboard, ports, [[ram, storage]…]]
        //
        // The Intel models are here on purpose. They are the cheap end of the
        // Bulgarian used market and the ones the butterfly-keyboard warning in
        // the checklist is aimed at — leaving them out would mean the warning
        // never appears on the machines that need it.
        $tb3   = ['Thunderbolt 3', '3.5mm'];
        $tb3ms = ['Thunderbolt 3', 'MagSafe 3', '3.5mm'];
        $proPorts = ['Thunderbolt 4', 'HDMI', 'SD карта', 'MagSafe 3', '3.5mm'];

        $models = [
            ['MacBook Air 13" (Intel)', 2019, 'Intel i5', 13.3, 'Butterfly', $tb3, [[8, 128], [8, 256], [16, 256]]],
            ['MacBook Pro 13" (Intel)', 2019, 'Intel i5', 13.3, 'Butterfly', $tb3, [[8, 256], [8, 512], [16, 512]]],
            ['MacBook Pro 16" (Intel)', 2019, 'Intel i7', 16.0, 'Magic Keyboard', $tb3, [[16, 512], [16, 1024]]],
            ['MacBook Air 13" (Intel)', 2020, 'Intel i3', 13.3, 'Magic Keyboard', $tb3, [[8, 256], [8, 512]]],

            ['MacBook Air 13" (M1)', 2020, 'M1', 13.3, 'Magic Keyboard', $tb3, [[8, 256], [8, 512], [16, 256], [16, 512]]],
            ['MacBook Pro 13" (M1)', 2020, 'M1', 13.3, 'Magic Keyboard', $tb3, [[8, 256], [8, 512], [16, 512]]],

            ['MacBook Pro 14" (M1 Pro)', 2021, 'M1 Pro', 14.2, 'Magic Keyboard', $proPorts, [[16, 512], [16, 1024], [32, 1024]]],
            ['MacBook Pro 16" (M1 Pro)', 2021, 'M1 Pro', 16.2, 'Magic Keyboard', $proPorts, [[16, 512], [16, 1024]]],
            ['MacBook Pro 14" (M1 Max)', 2021, 'M1 Max', 14.2, 'Magic Keyboard', $proPorts, [[32, 1024], [64, 2048]]],
            ['MacBook Pro 16" (M1 Max)', 2021, 'M1 Max', 16.2, 'Magic Keyboard', $proPorts, [[32, 1024], [64, 2048]]],

            ['MacBook Air 13" (M2)', 2022, 'M2', 13.6, 'Magic Keyboard', $tb3ms, [[8, 256], [8, 512], [16, 512], [24, 512]]],
            ['MacBook Pro 13" (M2)', 2022, 'M2', 13.3, 'Magic Keyboard', $tb3, [[8, 256], [8, 512], [16, 512]]],
            ['MacBook Air 15" (M2)', 2023, 'M2', 15.3, 'Magic Keyboard', $tb3ms, [[8, 256], [8, 512], [16, 512]]],

            ['MacBook Pro 14" (M2 Pro)', 2023, 'M2 Pro', 14.2, 'Magic Keyboard', $proPorts, [[16, 512], [16, 1024], [32, 1024]]],
            ['MacBook Pro 16" (M2 Pro)', 2023, 'M2 Pro', 16.2, 'Magic Keyboard', $proPorts, [[16, 512], [16, 1024]]],

            ['MacBook Pro 14" (M3)', 2023, 'M3', 14.2, 'Magic Keyboard', $proPorts, [[8, 512], [16, 512], [16, 1024]]],
            ['MacBook Pro 14" (M3 Pro)', 2023, 'M3 Pro', 14.2, 'Magic Keyboard', $proPorts, [[18, 512], [18, 1024], [36, 1024]]],
            ['MacBook Pro 16" (M3 Pro)', 2023, 'M3 Pro', 16.2, 'Magic Keyboard', $proPorts, [[18, 512], [36, 1024]]],
            ['MacBook Air 13" (M3)', 2024, 'M3', 13.6, 'Magic Keyboard', $tb3ms, [[8, 256], [8, 512], [16, 512]]],
            ['MacBook Air 15" (M3)', 2024, 'M3', 15.3, 'Magic Keyboard', $tb3ms, [[8, 256], [16, 512]]],

            ['MacBook Pro 14" (M4)', 2024, 'M4', 14.2, 'Magic Keyboard', $proPorts, [[16, 512], [16, 1024]]],
            ['MacBook Pro 14" (M4 Pro)', 2024, 'M4 Pro', 14.2, 'Magic Keyboard', $proPorts, [[24, 512], [24, 1024]]],
            ['MacBook Pro 16" (M4 Pro)', 2024, 'M4 Pro', 16.2, 'Magic Keyboard', $proPorts, [[24, 512], [48, 1024]]],
        ];

        $rows = [];

        foreach ($models as [$model, $year, $chip, $screen, $keyboard, $ports, $configs]) {
            foreach ($configs as [$ram, $storage]) {
                // The year is part of the variant as well as the model name for
                // the Intel machines, because „MacBook Air 13 (Intel)" 2019 and
                // 2020 are different keyboards at different prices.
                $variant = "{$year} · {$ram}GB / ".$this->capacity($storage);

                $rows[] = $this->row('macbook', $model, $variant, $year, [
                    'chip'          => $chip,
                    'ram_gb'        => $ram,
                    'storage_gb'    => $storage,
                    'screen_inch'   => $screen,
                    'model_year'    => $year,
                    'keyboard_type' => $keyboard,
                    'ports'         => $ports,
                ]);
            }
        }

        $this->insert('macbook', $rows);
    }

    // --- shared -----------------------------------------------------------

    /** 1024 reads as 1TB to everybody except a spec sheet. */
    private function capacity(int $gb): string
    {
        return $gb >= 1024 && $gb % 1024 === 0 ? ($gb / 1024).'TB' : $gb.'GB';
    }

    /** @param  array<string, mixed>  $specs */
    private function row(string $category, string $model, string $variant, int $year, array $specs): array
    {
        return [
            'category'     => $category,
            'manufacturer' => 'Apple',
            'model'        => $model,
            'variant'      => $variant,
            'slug'         => Str::slug("apple {$model} {$variant}"),
            'launch_year'  => $year,
            'specs'        => json_encode($specs, JSON_UNESCAPED_UNICODE),
            'aliases'      => json_encode($this->aliasesFor($model, $variant), JSON_UNESCAPED_UNICODE),
            'is_published' => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function insert(string $category, array $rows): void
    {
        DB::table('parts')->upsert($rows, ['slug'], ['specs', 'aliases', 'launch_year', 'manufacturer']);

        $this->command?->info('Seeded '.count($rows).' '.$category.' configurations.');
    }

    /**
     * How a Bulgarian actually types this.
     *
     * „айфон" has been a loanword here for fifteen years and is written in
     * Cyrillic more often than not, so the Cyrillic twin is not a nicety -
     * without it the whole catalogue is invisible to anyone whose keyboard is
     * where it usually is. The run-together forms („айфон13", „iphone13pro")
     * matter for the same reason they do on the GPU side: people type model
     * names without spaces.
     *
     * @return list<string>
     */
    private function aliasesFor(string $model, string $variant): array
    {
        $clean = mb_strtolower(trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $model)));
        $bare  = trim(str_replace(['"', '  '], ['', ' '], $clean));

        $forms = [
            $bare,
            str_replace(' ', '', $bare),
            $bare.' '.mb_strtolower($variant),
        ];

        // The distinctive tail: „13 pro", „air 13", „pro 14". Nobody searching
        // for a 13 Pro types the word iPhone first.
        $words = preg_split('/\s+/', $bare) ?: [];

        if (count($words) > 1) {
            $tail    = implode(' ', array_slice($words, -2));
            $forms[] = $tail;
            $forms[] = str_replace(' ', '', $tail);
        }

        foreach (array_unique($forms) as $form) {
            $cyrillic = Cyrillic::toCyrillic($form);

            if ($cyrillic !== $form) {
                $forms[] = $cyrillic;
                $forms[] = str_replace(' ', '', $cyrillic);
            }
        }

        return array_values(array_unique(array_filter(
            $forms,
            fn (string $f) => mb_strlen($f) >= 3,
        )));
    }
}
