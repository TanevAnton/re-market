<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data - safe everywhere.
        //
        // PartSeeder is GPUs and CPUs; PartCatalogueSeeder is the other
        // eleven categories. Split rather than merged because the two have
        // different shapes - the GPU rows carry a chipset-level caveat about
        // board partners that does not apply to a monitor model - and because
        // one file per fifteen categories is a file nobody edits.
        //
        // AppleCatalogueSeeder is a third file for the same reason: its rows
        // are configurations rather than models - one per storage, per RAM, per
        // Wi-Fi/Cellular - because on Apple hardware the configuration is most
        // of the price, and a shared median across them would be useless to
        // everybody. That shape does not belong mixed in with the others.
        $this->call([
            CitySeeder::class,
            PartSeeder::class,
            PartCatalogueSeeder::class,
            AppleCatalogueSeeder::class,
        ]);

        // Fixtures - local only.
        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}
