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
        $this->call([
            CitySeeder::class,
            PartSeeder::class,
            PartCatalogueSeeder::class,
        ]);

        // Fixtures - local only.
        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}
