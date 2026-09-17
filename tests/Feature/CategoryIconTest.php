<?php

namespace Tests\Feature;

use App\Livewire\AppleSection;
use App\Livewire\Home;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One drawing per category.
 *
 * This replaced the three-letter mono badges the home grid used to carry, for
 * the reasons written down in App\Livewire\Home. What the tests below defend is
 * not the artwork - nobody can assert that a power supply looks like a power
 * supply - but the three properties that make the artwork safe to keep adding
 * to, and that fail silently rather than loudly when they break:
 *
 *  1. A NEW CATEGORY STILL RENDERS. The partial falls through to the „other"
 *     box, so adding a category to config/catalog.php never leaves a hole in
 *     the grid. The day that fallback is removed, this tells you.
 *  2. NO TWO CATEGORIES SHARE A DRAWING. The icons were written by copying the
 *     nearest one and editing it - laptop from macbook, iPad from iPhone - and
 *     an unedited copy is invisible in review and obvious on the page.
 *  3. EVERYTHING IS currentColor. The entire argument for inline SVG over the
 *     generated PNG sheet is that one file works in both themes and flips to
 *     accent-ink on hover. A single literal colour quietly ends that, in one
 *     theme only, which is the kind of bug that ships.
 */
class CategoryIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The home page reads the city list for its default filter; without
        // one it is a failure about cities in a test about drawings.
        $this->seed(CitySeeder::class);
    }

    /** @return list<string> */
    private function categories(): array
    {
        return array_keys(config('catalog.categories'));
    }

    private function render(string $category): string
    {
        return view('partials.category-icon', ['category' => $category])->render();
    }

    public function test_every_category_draws_something(): void
    {
        foreach ($this->categories() as $category) {
            $svg = $this->render($category);

            // Two shapes, not one: a single rect is a box, and a box is what
            // the fallback already is.
            $this->assertGreaterThanOrEqual(
                2,
                preg_match_all('/<(path|rect|circle|line|polyline)\b/', $svg),
                "[{$category}] renders fewer than two shapes",
            );
        }
    }

    /**
     * An unknown key is not an error state. Categories arrive in config before
     * anybody draws for them, and a category that renders nothing on the home
     * page reads to a visitor as a broken site rather than a new section.
     */
    public function test_an_undrawn_category_falls_back_rather_than_rendering_a_hole(): void
    {
        $fallback = $this->render('quantum-cpu-2031');

        $this->assertStringContainsString('<svg', $fallback);
        $this->assertSame($this->render('other'), $fallback);
    }

    public function test_no_two_categories_share_a_drawing(): void
    {
        $seen = [];

        foreach ($this->categories() as $category) {
            // The shapes only. strip_tags drops the <svg> wrapper - whose class
            // attribute differs between callers and is not part of the drawing -
            // and keeps the geometry with its attributes intact.
            $shapes = trim(preg_replace('/\s+/', ' ',
                strip_tags($this->render($category), '<path><rect><circle><line>')));

            $twin = array_search($shapes, $seen, true);

            $this->assertFalse($twin,
                "[{$category}] and [{$twin}] are the same drawing; one of them was copied and not edited");

            $seen[$category] = $shapes;
        }
    }

    /**
     * The pairs most likely to collide, asserted by name so the failure says
     * what went wrong rather than making you diff two blobs of path data.
     */
    public function test_the_lookalike_pairs_are_actually_different(): void
    {
        foreach ([['laptop', 'macbook'], ['iphone', 'ipad'], ['monitor', 'laptop'],
                  ['storage', 'ram'], ['cpu', 'motherboard']] as [$a, $b]) {
            $this->assertNotSame($this->render($a), $this->render($b),
                "[{$a}] and [{$b}] are indistinguishable");
        }
    }

    public function test_the_drawings_inherit_the_text_colour(): void
    {
        foreach ($this->categories() as $category) {
            $svg = $this->render($category);

            $this->assertStringContainsString('stroke="currentColor"', $svg);

            // No literal colour anywhere: a hex, an rgb(), or a named colour on
            // a fill or stroke attribute inside the drawing.
            $this->assertSame(0, preg_match_all('/#[0-9a-f]{3,8}\b|rgb\(|hsl\(/i', $svg),
                "[{$category}] hard-codes a colour and will not invert with the theme");

            foreach (['fill' => '/fill="(?!none")([^"]*)"/', 'stroke' => '/stroke="(?!currentColor")([^"]*)"/'] as $attr => $pattern) {
                $this->assertSame(0, preg_match_all($pattern, $svg),
                    "[{$category}] sets a {$attr} that is not part of the currentColor contract");
            }
        }
    }

    /** Decoration beside a real label, so it is announced to nobody. */
    public function test_the_drawings_are_hidden_from_screen_readers(): void
    {
        foreach ($this->categories() as $category) {
            $this->assertStringContainsString('aria-hidden="true"', $this->render($category));
        }
    }

    // --- the two places they are used -------------------------------------

    /**
     * Every category is drawn somewhere on the home page.
     *
     * Deliberately NOT a count of `<svg>` elements. That is what this asserted
     * first, and it broke the moment the page grew two promo cards that draw
     * their own icons — a true failure about a page that was perfectly fine.
     * A test that has to be edited every time the page around it changes stops
     * being read, and the property worth defending was never „exactly nineteen
     * icons": it is that no category is missing its drawing.
     */
    public function test_the_home_page_draws_every_category(): void
    {
        $html = preg_replace('/\s+/', ' ', Livewire::test(Home::class)->html());

        foreach ($this->categories() as $category) {
            preg_match('/<svg[^>]*>(.*?)<\/svg>/s', $this->render($category), $shapes);

            $this->assertStringContainsString(
                trim(preg_replace('/\s+/', ' ', $shapes[1])),
                $html,
                "[{$category}] has no icon anywhere on the home page",
            );
        }
    }

    public function test_each_apple_tile_carries_its_own_silhouette(): void
    {
        $html = Livewire::test(AppleSection::class)->html();

        $this->assertSame(3, substr_count($html, 'viewBox="0 0 24 24"'));

        // And they are the right three, not three copies of one.
        foreach (['iphone', 'ipad', 'macbook'] as $category) {
            preg_match('/<svg[^>]*>(.*?)<\/svg>/s', $this->render($category), $shapes);

            $this->assertStringContainsString(
                trim(preg_replace('/\s+/', ' ', $shapes[1])),
                preg_replace('/\s+/', ' ', $html),
                "the {$category} tile is not showing the {$category} icon",
            );
        }
    }
}
