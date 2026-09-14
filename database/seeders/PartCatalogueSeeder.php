<?php

namespace Database\Seeders;

use App\Support\Cyrillic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rest of the catalogue: everything that is not a GPU or a CPU.
 *
 * PartSeeder covered two categories out of fifteen, which meant thirteen of
 * them had no `/model/{slug}` page, could never show a price band (that needs
 * three listings against one part), and gave a listing nothing to attach to -
 * so their specs stayed free text and their filters matched nothing. The
 * catalogue pages are the whole organic-search play; running it on two
 * categories runs it on a fraction of the site.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *
 * `laptop` and `prebuilt`. Look at their schema and the decision is already
 * made: every spec on `prebuilt` is listing-scoped, and a laptop's are mostly
 * the same, because no two configurations of the same model number are alike.
 * A catalogue row per SKU would be enormous, would still miss most listings,
 * and would tell a buyer nothing the listing does not. Those two stay
 * searchable rather than filterable, on purpose.
 *
 * WHAT COUNTS AS A MODEL
 *
 * Only ever the level at which the specs are actually fixed. A PSU model has a
 * wattage and an efficiency rating; a particular unit has an age and a set of
 * cables, which is why `has_all_cables` is listing-scoped and not here. Seeding
 * a part-level value for something that varies per unit is worse than leaving
 * it blank: it puts a number on the page that the item in the photograph does
 * not have.
 *
 * The figures below are manufacturer specifications for the reference model.
 * Board partners and revisions vary; the same caveat PartSeeder carries about
 * GPU lengths applies to every dimension here.
 */
class PartCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $this->seed('motherboard', $this->motherboards());
        $this->seed('ram', $this->memory());
        $this->seed('psu', $this->powerSupplies());
        $this->seed('storage', $this->storage());
        $this->seed('monitor', $this->monitors());
        $this->seed('cooler', $this->coolers());
        $this->seed('case', $this->cases());
        $this->seed('keyboard', $this->keyboards());
        $this->seed('mouse', $this->mice());
        $this->seed('headset', $this->headsets());
        $this->seed('console', $this->consoles());
    }

    // --- motherboards -----------------------------------------------------
    // The used AM4 boards are the volume here: a B450 or B550 is what somebody
    // buys to put a second-hand 5600 in, and that pairing is most of the
    // Bulgarian market.

    private function motherboards(): array
    {
        // [manufacturer, model, year, socket, chipset, form, ram, slots, m2, pcie, wifi]
        return [
            ['MSI', 'B450 TOMAHAWK MAX', 2019, 'AM4', 'B450', 'ATX', 'DDR4', 4, 1, '3.0', false],
            ['MSI', 'MAG B550 TOMAHAWK', 2020, 'AM4', 'B550', 'ATX', 'DDR4', 4, 2, '4.0', false],
            ['ASUS', 'ROG STRIX B550-F GAMING', 2020, 'AM4', 'B550', 'ATX', 'DDR4', 4, 2, '4.0', false],
            ['ASUS', 'TUF GAMING X570-PLUS', 2019, 'AM4', 'X570', 'ATX', 'DDR4', 4, 2, '4.0', false],
            ['Gigabyte', 'B450M DS3H', 2018, 'AM4', 'B450', 'Micro-ATX', 'DDR4', 4, 1, '3.0', false],
            ['ASRock', 'B450M PRO4', 2018, 'AM4', 'B450', 'Micro-ATX', 'DDR4', 4, 2, '3.0', false],

            ['MSI', 'MAG B650 TOMAHAWK WIFI', 2022, 'AM5', 'B650', 'ATX', 'DDR5', 4, 3, '4.0', true],
            ['ASUS', 'ROG STRIX B650E-F GAMING WIFI', 2022, 'AM5', 'B650E', 'ATX', 'DDR5', 4, 3, '5.0', true],
            ['ASRock', 'B650M PG Riptide', 2023, 'AM5', 'B650', 'Micro-ATX', 'DDR5', 4, 2, '4.0', false],
            ['ASUS', 'ROG STRIX X670E-E GAMING WIFI', 2022, 'AM5', 'X670E', 'ATX', 'DDR5', 4, 4, '5.0', true],
            ['Gigabyte', 'B850 AORUS ELITE WIFI7', 2025, 'AM5', 'B850', 'ATX', 'DDR5', 4, 3, '5.0', true],

            ['MSI', 'MAG B760 TOMAHAWK WIFI', 2023, 'LGA1700', 'B760', 'ATX', 'DDR5', 4, 3, '5.0', true],
            ['MSI', 'PRO B760M-A WIFI', 2023, 'LGA1700', 'B760', 'Micro-ATX', 'DDR5', 4, 2, '4.0', true],
            ['ASUS', 'PRIME Z790-P', 2022, 'LGA1700', 'Z790', 'ATX', 'DDR5', 4, 4, '5.0', false],
            ['Gigabyte', 'B660M DS3H DDR4', 2022, 'LGA1700', 'B660', 'Micro-ATX', 'DDR4', 4, 2, '4.0', false],
            ['ASUS', 'ROG STRIX Z690-A GAMING WIFI D4', 2021, 'LGA1700', 'Z690', 'ATX', 'DDR4', 4, 4, '5.0', true],

            ['MSI', 'MAG B560 TOMAHAWK WIFI', 2021, 'LGA1200', 'B560', 'ATX', 'DDR4', 4, 2, '4.0', true],
            ['ASUS', 'PRIME B460M-A', 2020, 'LGA1200', 'B460', 'Micro-ATX', 'DDR4', 4, 1, '3.0', false],
            ['Gigabyte', 'Z490 AORUS ELITE AC', 2020, 'LGA1200', 'Z490', 'ATX', 'DDR4', 4, 2, '3.0', true],

            ['ASUS', 'ROG STRIX Z890-A GAMING WIFI', 2024, 'LGA1851', 'Z890', 'ATX', 'DDR5', 4, 4, '5.0', true],
        ];
    }

    private function motherboardSpecs(array $r): array
    {
        [, , , $socket, $chipset, $form, $ram, $slots, $m2, $pcie, $wifi] = $r;

        return [
            'socket'      => $socket,
            'chipset'     => $chipset,
            'form_factor' => $form,
            'ram_type'    => $ram,
            'ram_slots'   => $slots,
            'm2_slots'    => $m2,
            'pcie_gen'    => $pcie,
            'wifi'        => $wifi,
        ];
    }

    // --- memory -----------------------------------------------------------
    // A kit is the model. `height_mm` is here because it is fixed per kit and
    // it is the spec that decides whether the buyer's air cooler fits.

    private function memory(): array
    {
        // [mfr, model, year, type, total, layout, speed, cl, rgb, height]
        return [
            ['Corsair', 'Vengeance LPX 16GB (2x8) 3200 C16', 2017, 'DDR4', 16, '2x', 3200, 16, false, 34],
            ['Corsair', 'Vengeance LPX 32GB (2x16) 3600 C18', 2019, 'DDR4', 32, '2x', 3600, 18, false, 34],
            ['G.Skill', 'Ripjaws V 16GB (2x8) 3600 C16', 2018, 'DDR4', 16, '2x', 3600, 16, false, 42],
            ['G.Skill', 'Trident Z RGB 32GB (2x16) 3600 C16', 2018, 'DDR4', 32, '2x', 3600, 16, true, 44],
            ['Kingston', 'FURY Beast 16GB (2x8) 3200 C16', 2021, 'DDR4', 16, '2x', 3200, 16, false, 35],
            ['Crucial', 'Ballistix 32GB (2x16) 3200 C16', 2020, 'DDR4', 32, '2x', 3200, 16, false, 39],

            ['Corsair', 'Vengeance 32GB (2x16) DDR5 6000 C30', 2023, 'DDR5', 32, '2x', 6000, 30, false, 35],
            ['G.Skill', 'Trident Z5 Neo RGB 32GB (2x16) 6000 C30', 2023, 'DDR5', 32, '2x', 6000, 30, true, 42],
            ['Kingston', 'FURY Beast 32GB (2x16) DDR5 6000 C36', 2022, 'DDR5', 32, '2x', 6000, 36, false, 35],
            ['G.Skill', 'Flare X5 32GB (2x16) DDR5 6000 C30', 2023, 'DDR5', 32, '2x', 6000, 30, false, 33],
            ['Corsair', 'Vengeance 64GB (2x32) DDR5 6000 C30', 2023, 'DDR5', 64, '2x', 6000, 30, false, 35],
            ['Kingston', 'FURY Renegade 64GB (4x16) DDR5 6000 C32', 2023, 'DDR5', 64, '4x', 6000, 32, false, 39],
        ];
    }

    private function memorySpecs(array $r): array
    {
        [, , , $type, $total, $layout, $speed, $cl, $rgb, $height] = $r;

        return [
            'type'        => $type,
            'total_gb'    => $total,
            'kit_layout'  => $layout,
            'speed_mts'   => $speed,
            'cas_latency' => $cl,
            'rgb'         => $rgb,
            'height_mm'   => $height,
        ];
    }

    // --- power supplies ---------------------------------------------------
    // NOT seeded: anything about the individual unit. Age is the dominant
    // variable on a used PSU and it belongs to the item, not the model.

    private function powerSupplies(): array
    {
        // [mfr, model, year, watts, efficiency, modularity, atx, 12vhpwr, form]
        return [
            ['Seasonic', 'FOCUS GX-650', 2018, 650, '80+ Gold', 'Напълно модулно', 'ATX 2.x', false, 'ATX'],
            ['Seasonic', 'FOCUS GX-750', 2018, 750, '80+ Gold', 'Напълно модулно', 'ATX 2.x', false, 'ATX'],
            ['Seasonic', 'VERTEX GX-850', 2023, 850, '80+ Gold', 'Напълно модулно', 'ATX 3.0', true, 'ATX'],
            ['Corsair', 'RM750x', 2021, 750, '80+ Gold', 'Напълно модулно', 'ATX 2.x', false, 'ATX'],
            ['Corsair', 'RM850x SHIFT', 2023, 850, '80+ Gold', 'Напълно модулно', 'ATX 3.0', true, 'ATX'],
            ['Corsair', 'CX650M', 2021, 650, '80+ Bronze', 'Полумодулно', 'ATX 2.x', false, 'ATX'],
            ['be quiet!', 'Pure Power 12 M 750W', 2022, 750, '80+ Gold', 'Напълно модулно', 'ATX 3.0', true, 'ATX'],
            ['be quiet!', 'Straight Power 11 650W', 2018, 650, '80+ Gold', 'Напълно модулно', 'ATX 2.x', false, 'ATX'],
            ['MSI', 'MAG A650BN', 2020, 650, '80+ Bronze', 'Немодулно', 'ATX 2.x', false, 'ATX'],
            ['MSI', 'MPG A850G PCIE5', 2023, 850, '80+ Gold', 'Напълно модулно', 'ATX 3.0', true, 'ATX'],
            ['Cooler Master', 'MWE Gold 750 V2', 2021, 750, '80+ Gold', 'Напълно модулно', 'ATX 2.x', false, 'ATX'],
            ['Corsair', 'SF750', 2019, 750, '80+ Platinum', 'Напълно модулно', 'ATX 2.x', false, 'SFX'],
            ['Seasonic', 'PRIME TX-1000', 2020, 1000, '80+ Titanium', 'Напълно модулно', 'ATX 2.x', false, 'ATX'],
        ];
    }

    private function psuSpecs(array $r): array
    {
        [, , , $watts, $eff, $mod, $atx, $hpwr, $form] = $r;

        return [
            'wattage'      => $watts,
            'efficiency'   => $eff,
            'modularity'   => $mod,
            'atx_version'  => $atx,
            'has_12vhpwr'  => $hpwr,
            'form_factor'  => $form,
        ];
    }

    // --- storage ----------------------------------------------------------
    // `tbw` is the model's rated endurance. What the drive has actually
    // written is listing-scoped, and so is its health - those are the two
    // numbers the buyer checklist tells people to ask for.

    private function storage(): array
    {
        // [mfr, model, year, type, capacity, interface, form, tbw]
        return [
            ['Samsung', '990 PRO 1TB', 2022, 'NVMe SSD', 1000, 'PCIe 4.0 x4', 'M.2 2280', 600],
            ['Samsung', '990 PRO 2TB', 2022, 'NVMe SSD', 2000, 'PCIe 4.0 x4', 'M.2 2280', 1200],
            ['Samsung', '980 PRO 1TB', 2020, 'NVMe SSD', 1000, 'PCIe 4.0 x4', 'M.2 2280', 600],
            ['Samsung', '970 EVO Plus 1TB', 2019, 'NVMe SSD', 1000, 'PCIe 3.0 x4', 'M.2 2280', 600],
            ['Samsung', '870 EVO 1TB', 2021, 'SATA SSD', 1000, 'SATA III', '2.5"', 600],
            ['WD', 'Black SN850X 1TB', 2022, 'NVMe SSD', 1000, 'PCIe 4.0 x4', 'M.2 2280', 600],
            ['WD', 'Black SN850X 2TB', 2022, 'NVMe SSD', 2000, 'PCIe 4.0 x4', 'M.2 2280', 1200],
            ['WD', 'Blue SN570 1TB', 2021, 'NVMe SSD', 1000, 'PCIe 3.0 x4', 'M.2 2280', 600],
            ['Crucial', 'P3 Plus 1TB', 2022, 'NVMe SSD', 1000, 'PCIe 4.0 x4', 'M.2 2280', 220],
            ['Crucial', 'MX500 1TB', 2018, 'SATA SSD', 1000, 'SATA III', '2.5"', 360],
            ['Kingston', 'NV2 1TB', 2022, 'NVMe SSD', 1000, 'PCIe 4.0 x4', 'M.2 2280', 320],
            ['Kingston', 'A400 480GB', 2017, 'SATA SSD', 480, 'SATA III', '2.5"', 160],
            ['Seagate', 'BarraCuda 2TB', 2016, 'HDD 3.5"', 2000, 'SATA III', '3.5"', null],
            ['Seagate', 'IronWolf 4TB', 2019, 'HDD 3.5"', 4000, 'SATA III', '3.5"', null],
            ['WD', 'Red Plus 4TB', 2020, 'HDD 3.5"', 4000, 'SATA III', '3.5"', null],
            ['Toshiba', 'P300 2TB', 2018, 'HDD 3.5"', 2000, 'SATA III', '3.5"', null],
        ];
    }

    private function storageSpecs(array $r): array
    {
        [, , , $type, $cap, $iface, $form, $tbw] = $r;

        return array_filter([
            'drive_type'  => $type,
            'capacity_gb' => $cap,
            'interface'   => $iface,
            'form_factor' => $form,
            // Mechanical drives are not rated in TBW; a zero here would read
            // as "worn out" rather than "not applicable".
            'tbw'         => $tbw,
        ], fn ($v) => $v !== null);
    }

    // --- monitors ---------------------------------------------------------

    private function monitors(): array
    {
        // [mfr, model, year, inches, resolution, hz, panel, ms, curved, sync]
        return [
            ['LG', '27GP850-B', 2021, 27, '2560x1440', 165, 'IPS', 1, false, 'G-Sync Compatible'],
            ['LG', '27GL850-B', 2019, 27, '2560x1440', 144, 'IPS', 1, false, 'G-Sync Compatible'],
            ['LG', '24GN650-B', 2020, 24, '1920x1080', 144, 'IPS', 1, false, 'FreeSync Premium'],
            ['Samsung', 'Odyssey G5 C27G55T', 2020, 27, '2560x1440', 144, 'VA', 1, true, 'FreeSync Premium'],
            ['Samsung', 'Odyssey G7 C32G75T', 2020, 32, '2560x1440', 240, 'VA', 1, true, 'G-Sync Compatible'],
            ['Samsung', 'Odyssey OLED G8 G85SB', 2023, 34, '3440x1440', 175, 'QD-OLED', 0.1, true, 'FreeSync Premium'],
            ['AOC', '24G2U', 2019, 24, '1920x1080', 144, 'IPS', 1, false, 'FreeSync Premium'],
            ['AOC', 'CQ27G2U', 2019, 27, '2560x1440', 144, 'VA', 1, true, 'FreeSync Premium'],
            ['Dell', 'S2721DGF', 2020, 27, '2560x1440', 165, 'IPS', 1, false, 'G-Sync Compatible'],
            ['Dell', 'U2720Q', 2019, 27, '3840x2160', 60, 'IPS', 5, false, 'Няма'],
            ['ASUS', 'TUF Gaming VG27AQ', 2019, 27, '2560x1440', 165, 'IPS', 1, false, 'G-Sync Compatible'],
            ['ASUS', 'ROG Swift PG27AQDM', 2023, 27, '2560x1440', 240, 'OLED', 0.03, false, 'G-Sync Compatible'],
            ['MSI', 'Optix MAG274QRF-QD', 2021, 27, '2560x1440', 165, 'IPS', 1, false, 'G-Sync Compatible'],
            ['BenQ', 'ZOWIE XL2411K', 2020, 24, '1920x1080', 144, 'TN', 1, false, 'Няма'],
            ['Philips', '242E1GAJ', 2020, 24, '1920x1080', 144, 'VA', 1, true, 'FreeSync'],
        ];
    }

    private function monitorSpecs(array $r): array
    {
        [, , , $inch, $res, $hz, $panel, $ms, $curved, $sync] = $r;

        return [
            'size_inch'     => $inch,
            'resolution'    => $res,
            'refresh_hz'    => $hz,
            'panel_type'    => $panel,
            'response_ms'   => $ms,
            'curved'        => $curved,
            'adaptive_sync' => $sync,
        ];
    }

    // --- cooling ----------------------------------------------------------
    // `sockets` is the list the cooler SHIPS support for. Whether the bracket
    // for the buyer's socket is actually in the box is listing-scoped, which
    // is the whole point of `has_mounting` and the first line of the cooler
    // checklist.

    private function coolers(): array
    {
        // [mfr, model, year, type, sockets, radiator, height]
        $amdIntel = ['AM4', 'AM5', 'LGA1151', 'LGA1200', 'LGA1700'];

        return [
            ['Noctua', 'NH-D15', 2014, 'Въздушно', $amdIntel, null, 165],
            ['Noctua', 'NH-U12S redux', 2019, 'Въздушно', $amdIntel, null, 158],
            ['Noctua', 'NH-L9i', 2013, 'Въздушно', ['LGA1151', 'LGA1200', 'LGA1700'], null, 37],
            ['be quiet!', 'Dark Rock Pro 4', 2018, 'Въздушно', $amdIntel, null, 163],
            ['be quiet!', 'Pure Rock 2', 2020, 'Въздушно', $amdIntel, null, 155],
            ['Thermalright', 'Peerless Assassin 120 SE', 2022, 'Въздушно', $amdIntel, null, 155],
            ['DeepCool', 'AK620', 2021, 'Въздушно', $amdIntel, null, 160],
            ['Cooler Master', 'Hyper 212 EVO', 2011, 'Въздушно', $amdIntel, null, 159],

            ['Arctic', 'Liquid Freezer II 240', 2020, 'AIO водно', $amdIntel, '240', null],
            ['Arctic', 'Liquid Freezer II 360', 2020, 'AIO водно', $amdIntel, '360', null],
            ['Corsair', 'iCUE H100i ELITE CAPELLIX', 2020, 'AIO водно', $amdIntel, '240', null],
            ['Corsair', 'iCUE H150i ELITE CAPELLIX', 2020, 'AIO водно', $amdIntel, '360', null],
            ['NZXT', 'Kraken X63', 2020, 'AIO водно', $amdIntel, '280', null],
            ['DeepCool', 'LT720', 2022, 'AIO водно', $amdIntel, '360', null],
        ];
    }

    private function coolerSpecs(array $r): array
    {
        [, , , $type, $sockets, $rad, $height] = $r;

        return array_filter([
            'cooler_type' => $type,
            'sockets'     => $sockets,
            'radiator_mm' => $rad,
            'height_mm'   => $height,
        ], fn ($v) => $v !== null);
    }

    // --- cases ------------------------------------------------------------

    private function cases(): array
    {
        // [mfr, model, year, форм-фактори, max gpu, max cooler, panel]
        return [
            ['Fractal Design', 'North', 2023, ['ATX', 'Micro-ATX', 'Mini-ITX'], 355, 170, 'Закалено стъкло'],
            ['Fractal Design', 'Meshify 2 Compact', 2021, ['ATX', 'Micro-ATX', 'Mini-ITX'], 341, 169, 'Закалено стъкло'],
            ['Fractal Design', 'Define R5', 2014, ['ATX', 'Micro-ATX', 'Mini-ITX'], 440, 180, 'Метал'],
            ['NZXT', 'H510', 2019, ['ATX', 'Micro-ATX', 'Mini-ITX'], 381, 165, 'Закалено стъкло'],
            ['NZXT', 'H7 Flow', 2022, ['E-ATX', 'ATX', 'Micro-ATX', 'Mini-ITX'], 400, 185, 'Закалено стъкло'],
            ['Lian Li', 'O11 Dynamic', 2018, ['E-ATX', 'ATX', 'Micro-ATX'], 420, 155, 'Закалено стъкло'],
            ['Lian Li', 'Lancool 216', 2023, ['E-ATX', 'ATX', 'Micro-ATX', 'Mini-ITX'], 392, 180, 'Закалено стъкло'],
            ['Corsair', '4000D Airflow', 2020, ['E-ATX', 'ATX', 'Micro-ATX', 'Mini-ITX'], 360, 170, 'Закалено стъкло'],
            ['be quiet!', 'Pure Base 500DX', 2020, ['ATX', 'Micro-ATX', 'Mini-ITX'], 369, 190, 'Закалено стъкло'],
            ['Cooler Master', 'MasterBox NR200P', 2020, ['Mini-ITX'], 330, 155, 'Закалено стъкло'],
            ['Phanteks', 'Eclipse P400A', 2019, ['ATX', 'Micro-ATX', 'Mini-ITX'], 420, 160, 'Закалено стъкло'],
        ];
    }

    private function caseSpecs(array $r): array
    {
        [, , , $forms, $gpu, $cooler, $panel] = $r;

        return [
            'form_factor'   => $forms,
            'max_gpu_mm'    => $gpu,
            'max_cooler_mm' => $cooler,
            'side_panel'    => $panel,
        ];
    }

    // --- peripherals ------------------------------------------------------
    // Catalogued because switch type, layout and connection are fixed by the
    // model. Whether THIS one has Cyrillic legends is listing-scoped, because
    // the same model is sold both ways here.

    private function keyboards(): array
    {
        // [mfr, model, year, switch, layout, connection]
        return [
            ['Logitech', 'G Pro X TKL', 2023, 'Механични', 'TKL', 'Хибридна'],
            ['Logitech', 'G413', 2017, 'Механични', 'Full-size', 'Кабел'],
            ['Keychron', 'K2 V2', 2020, 'Механични', '75%', 'Хибридна'],
            ['Keychron', 'Q1', 2021, 'Механични', '75%', 'Кабел'],
            ['Razer', 'BlackWidow V3', 2020, 'Механични', 'Full-size', 'Кабел'],
            ['Razer', 'Huntsman Mini', 2020, 'Оптични', '60%', 'Кабел'],
            ['SteelSeries', 'Apex Pro TKL', 2019, 'Hall effect', 'TKL', 'Кабел'],
            ['Corsair', 'K70 RGB MK.2', 2018, 'Механични', 'Full-size', 'Кабел'],
            ['HyperX', 'Alloy Origins Core', 2020, 'Механични', 'TKL', 'Кабел'],
            ['Ducky', 'One 2 Mini', 2018, 'Механични', '60%', 'Кабел'],
            ['Logitech', 'K120', 2009, 'Мембранни', 'Full-size', 'Кабел'],
        ];
    }

    private function keyboardSpecs(array $r): array
    {
        [, , , $switch, $layout, $conn] = $r;

        return ['switch_type' => $switch, 'layout' => $layout, 'connection' => $conn];
    }

    private function mice(): array
    {
        // [mfr, model, year, connection, dpi, weight]
        return [
            ['Logitech', 'G Pro X Superlight', 2020, 'Безжична 2.4GHz', 25600, 63],
            ['Logitech', 'G Pro X Superlight 2', 2023, 'Безжична 2.4GHz', 32000, 60],
            ['Logitech', 'G502 HERO', 2018, 'Кабел', 25600, 121],
            ['Logitech', 'G305', 2018, 'Безжична 2.4GHz', 12000, 99],
            ['Razer', 'DeathAdder V3 Pro', 2022, 'Безжична 2.4GHz', 30000, 63],
            ['Razer', 'Viper V2 Pro', 2022, 'Безжична 2.4GHz', 30000, 58],
            ['Razer', 'DeathAdder Essential', 2018, 'Кабел', 6400, 96],
            ['SteelSeries', 'Rival 3', 2019, 'Кабел', 8500, 77],
            ['Glorious', 'Model O', 2019, 'Кабел', 12000, 67],
            ['Corsair', 'M65 RGB Elite', 2019, 'Кабел', 18000, 97],
        ];
    }

    private function mouseSpecs(array $r): array
    {
        [, , , $conn, $dpi, $weight] = $r;

        return ['connection' => $conn, 'dpi' => $dpi, 'weight_g' => $weight];
    }

    private function headsets(): array
    {
        // [mfr, model, year, connection, mic, surround]
        return [
            ['HyperX', 'Cloud II', 2015, 'USB', true, true],
            ['HyperX', 'Cloud Alpha', 2017, '3.5mm', true, false],
            ['SteelSeries', 'Arctis 7', 2017, 'Безжични 2.4GHz', true, true],
            ['SteelSeries', 'Arctis Nova Pro Wireless', 2022, 'Безжични 2.4GHz', true, true],
            ['Logitech', 'G Pro X', 2019, '3.5mm', true, true],
            ['Logitech', 'G435', 2021, 'Bluetooth', true, false],
            ['Razer', 'BlackShark V2', 2020, '3.5mm', true, true],
            ['Corsair', 'HS60', 2018, '3.5mm', true, true],
            ['Sennheiser', 'HD 560S', 2020, '3.5mm', false, false],
        ];
    }

    private function headsetSpecs(array $r): array
    {
        [, , , $conn, $mic, $surround] = $r;

        return ['connection' => $conn, 'has_mic' => $mic, 'surround' => $surround];
    }

    // --- consoles ---------------------------------------------------------
    // A small, closed, stable set, and "ps5 втора ръка" is one of the highest
    // volume searches this site could answer.

    private function consoles(): array
    {
        // [mfr, model, year, platform, storage]
        return [
            ['Sony', 'PlayStation 5', 2020, 'PlayStation 5', 825],
            ['Sony', 'PlayStation 5 Digital Edition', 2020, 'PlayStation 5', 825],
            ['Sony', 'PlayStation 5 Slim', 2023, 'PlayStation 5', 1000],
            ['Sony', 'PlayStation 5 Pro', 2024, 'PlayStation 5', 2000],
            ['Sony', 'PlayStation 4 Slim', 2016, 'PlayStation 4', 500],
            ['Sony', 'PlayStation 4 Pro', 2016, 'PlayStation 4', 1000],
            ['Microsoft', 'Xbox Series X', 2020, 'Xbox Series X', 1000],
            ['Microsoft', 'Xbox Series S', 2020, 'Xbox Series S', 512],
            ['Microsoft', 'Xbox One S', 2016, 'Xbox One', 500],
            ['Nintendo', 'Switch OLED', 2021, 'Nintendo Switch', 64],
            ['Nintendo', 'Switch Lite', 2019, 'Nintendo Switch', 32],
            ['Nintendo', 'Switch 2', 2025, 'Nintendo Switch 2', 256],
            ['Valve', 'Steam Deck OLED 512GB', 2023, 'Steam Deck', 512],
            ['Valve', 'Steam Deck 256GB', 2022, 'Steam Deck', 256],
        ];
    }

    private function consoleSpecs(array $r): array
    {
        [, , , $platform, $storage] = $r;

        return ['platform' => $platform, 'storage_gb' => $storage];
    }

    /**
     * What people type instead of the name on the box.
     *
     * Generated aliases handle spelling and alphabet; they cannot invent
     * "ps5" out of "PlayStation 5". This is the short list where the common
     * search term shares no substring with the model, so neither ILIKE nor
     * trigram similarity will ever find it.
     */
    private const SHORTHAND = [
        'PlayStation 5'                 => ['ps5', 'пс5', 'плейстейшън 5'],
        'PlayStation 5 Digital Edition' => ['ps5 digital', 'пс5 диджитал'],
        'PlayStation 5 Slim'            => ['ps5 slim', 'пс5 слим'],
        'PlayStation 5 Pro'             => ['ps5 pro', 'пс5 про'],
        'PlayStation 4 Slim'            => ['ps4', 'пс4', 'ps4 slim'],
        'PlayStation 4 Pro'             => ['ps4 pro', 'пс4 про'],
        'Xbox Series X'                 => ['xsx', 'series x', 'иксбокс сериис х'],
        'Xbox Series S'                 => ['xss', 'series s', 'иксбокс сериис с'],
        'Xbox One S'                    => ['xbox one', 'иксбокс уан'],
        'Switch OLED'                   => ['nintendo switch', 'суич олед', 'нинтендо суич'],
        'Switch Lite'                   => ['суич лайт', 'нинтендо суич лайт'],
        'Switch 2'                      => ['суич 2', 'нинтендо суич 2'],
        'Steam Deck OLED 512GB'         => ['steam deck', 'стийм дек'],
        'Steam Deck 256GB'              => ['steam deck', 'стийм дек'],
    ];

    // --- the write --------------------------------------------------------

    /**
     * upsert on `slug`, exactly as PartSeeder does, so running the seeder
     * again corrects the data on rows that already exist rather than either
     * duplicating them or failing on the unique index. Listings already
     * attached to a part keep their part_id.
     */
    private function seed(string $category, array $rows): void
    {
        $specsFor = [
            'motherboard' => fn ($r) => $this->motherboardSpecs($r),
            'ram'         => fn ($r) => $this->memorySpecs($r),
            'psu'         => fn ($r) => $this->psuSpecs($r),
            'storage'     => fn ($r) => $this->storageSpecs($r),
            'monitor'     => fn ($r) => $this->monitorSpecs($r),
            'cooler'      => fn ($r) => $this->coolerSpecs($r),
            'case'        => fn ($r) => $this->caseSpecs($r),
            'keyboard'    => fn ($r) => $this->keyboardSpecs($r),
            'mouse'       => fn ($r) => $this->mouseSpecs($r),
            'headset'     => fn ($r) => $this->headsetSpecs($r),
            'console'     => fn ($r) => $this->consoleSpecs($r),
        ][$category];

        $out = [];

        foreach ($rows as $r) {
            [$mfr, $model, $year] = $r;

            $out[] = [
                'category'     => $category,
                'manufacturer' => $mfr,
                'model'        => $model,
                'variant'      => null,
                'slug'         => Str::slug($mfr.' '.$model),
                'launch_year'  => $year,
                'specs'        => json_encode($specsFor($r), JSON_UNESCAPED_UNICODE),
                'aliases'      => json_encode(
                    $this->aliasesFor($mfr, $model, self::SHORTHAND[$model] ?? []),
                    JSON_UNESCAPED_UNICODE,
                ),
                'is_published' => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        DB::table('parts')->upsert($out, ['slug'], ['specs', 'aliases', 'launch_year', 'manufacturer']);

        $this->command?->info('Seeded '.count($out).' '.$category.'.');
    }

    /**
     * Search forms for things nobody types in full.
     *
     * Simpler than PartSeeder's GPU version on purpose: there is no marketing
     * prefix to strip here, and the manufacturer IS part of how people search
     * for a motherboard ("b550 tomahawk" but also "msi b550"). What matters is
     * the run-together and Cyrillic spellings, because a Bulgarian keyboard is
     * often still on Cyrillic when somebody starts typing a Latin model name.
     */
    private function aliasesFor(string $mfr, string $model, array $extra = []): array
    {
        $model = mb_strtolower($model);
        $mfr   = mb_strtolower($mfr);

        $forms = [
            $model,
            $mfr.' '.$model,
            str_replace(['-', ' '], '', $model),
        ];

        // The model with any parenthesised kit detail dropped: "vengeance 32gb
        // (2x16) ddr5 6000 c30" is not something anyone types.
        $short = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $model));
        if ($short !== $model) {
            $forms[] = $short;
        }

        // The distinctive tail on its own - "tomahawk", "990 pro", "o11
        // dynamic" - which is how these are actually searched for. Both the
        // last two words and the last word alone: ILIKE catches the Latin
        // spelling of a bare word anyway, but its Cyrillic twin below is only
        // findable if it is an alias in its own right.
        $words = preg_split('/\s+/', $short);
        if (count($words) > 1) {
            $forms[] = implode(' ', array_slice($words, -2));
            $forms[] = end($words);
        }

        foreach (array_unique($forms) as $f) {
            $cyr = $this->toCyrillic($f);
            if ($cyr !== $f) {
                $forms[] = $cyr;
            }
        }

        foreach ($extra as $e) {
            $forms[] = mb_strtolower($e);
        }

        return array_values(array_unique(
            array_filter($forms, fn ($f) => mb_strlen($f) >= 3)
        ));
    }

    /** The shared map - see the note on PartSeeder::toCyrillic(). */
    private function toCyrillic(string $s): string
    {
        return Cyrillic::toCyrillic($s);
    }
}
