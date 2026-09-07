<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data - safe everywhere.
        $this->call([
            CitySeeder::class,
            PartSeeder::class,
        ]);

        // Fixtures - local only.
        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}
