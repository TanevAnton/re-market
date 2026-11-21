<?php

namespace Tests\Feature;

use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Placeholders while the results load.
 *
 * Two promises worth keeping. They must not appear for the things that do not
 * change the results - saving a search must not blank the grid - and they must
 * not be read out by a screen reader as if they were listings.
 */
class SkeletonTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_partial_draws_the_number_asked_for_and_hides_them_from_screen_readers(): void
    {
        $html = view('partials.skeleton-cards', ['count' => 4])->render();

        $this->assertSame(4, substr_count($html, 'aria-hidden="true"'));
        $this->assertSame(4, substr_count($html, 'aspect-[4/3]'));
    }

    public function test_it_defaults_to_six(): void
    {
        $this->assertSame(6, substr_count(view('partials.skeleton-cards')->render(), 'aria-hidden="true"'));
    }

    public function test_the_browse_page_carries_an_overlay_for_the_slow_updates_only(): void
    {
        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $html = $this->get(route('browse'))->assertOk()->getContent();

        $this->assertStringContainsString('wire:loading.delay.short', $html);
        $this->assertStringContainsString('role="status"', $html);

        // Scoped to the things that change the results. Without a target the
        // overlay would answer to every action on the component.
        $this->assertMatchesRegularExpression('/wire:target="[^"]*\bq\b[^"]*\bsort\b[^"]*"/', $html);
        $this->assertDoesNotMatchRegularExpression('/wire:target="[^"]*saveSearch[^"]*"/', $html);
    }

    public function test_the_sweep_stops_for_people_who_ask_for_less_motion(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.skeleton', $css);
        $this->assertMatchesRegularExpression(
            '/prefers-reduced-motion:\s*reduce\)\s*\{\s*\.skeleton::after\s*\{\s*animation:\s*none/',
            $css,
        );
    }
}
