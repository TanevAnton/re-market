<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This is the test that should have existed first.
 *
 * Three bugs reached Ivan's terminal because a migration demanded something
 * the model never provided: a uuid with no HasUuids, a NOT NULL slug with no
 * generator, and factory() with no HasFactory. Every one of them dies here.
 */
class SeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_run_against_postgres(): void
    {
        // RefreshDatabase already migrated. If the Postgres-specific DDL were
        // wrong - GIN opclasses, partial indexes, check constraints - we would
        // not have reached this line.
        $this->assertTrue(true);
    }

    public function test_reference_seeders_populate_cities_and_parts(): void
    {
        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->assertGreaterThan(100, City::count(), 'city seed is short');
        $this->assertGreaterThan(100, Part::count(), 'catalogue seed is short');

        $this->assertNotNull(City::where('slug', 'gorna-oryahovitsa')->first());
        $this->assertNotNull(Part::where('slug', 'nvidia-geforce-rtx-4090')->first());
    }

    public function test_demo_seeder_creates_usable_users_and_listings(): void
    {
        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertGreaterThan(0, User::count());
        $this->assertGreaterThan(0, Listing::count());
    }

    public function test_every_user_gets_a_uuid(): void
    {
        $this->seed(CitySeeder::class);

        $user = User::factory()->create();

        // Regression: HasUuids was removed as an "unused import" while the
        // column stayed NOT NULL.
        $this->assertNotNull($user->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-/i',
            $user->uuid
        );
    }

    public function test_every_listing_gets_a_uuid_and_a_slug(): void
    {
        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);
        User::factory()->create();

        $listing = Listing::factory()->create(['title' => 'Видеокарта RTX 4070 Ti']);

        $this->assertNotNull($listing->uuid);
        $this->assertNotEmpty($listing->slug, 'slug is NOT NULL and must be generated on save');

        // Cyrillic must transliterate rather than collapse to an empty slug.
        $this->assertStringContainsString('rtx-4070', $listing->slug);
    }

    public function test_slug_survives_a_title_with_no_latin_characters(): void
    {
        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);
        User::factory()->create();

        $listing = Listing::factory()->create(['title' => '???']);

        $this->assertNotEmpty($listing->slug);
    }
}
