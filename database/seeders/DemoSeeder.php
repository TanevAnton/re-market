<?php

namespace Database\Seeders;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development fixtures. Never run this in production - it creates accounts
 * whose password is literally "password".
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('DemoSeeder refuses to run in production.');

            return;
        }

        $sellers = User::factory()->count(18)->create();
        User::factory()->trader()->count(4)->create();

        Listing::factory()->count(140)->create();

        $this->command->info('Seeded '.User::count().' users and '.Listing::count().' listings.');
    }
}
