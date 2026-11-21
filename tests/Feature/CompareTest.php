<?php

namespace Tests\Feature;

use App\Livewire\CompareParts;
use App\Models\Part;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Сравни модели".
 *
 * What is worth guarding is what the page refuses to claim: a highlight on a
 * spec where better is a matter of taste, a table that mixes a graphics card
 * with a processor, and a thin page offered to a crawler as if it were content.
 */
class CompareTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Part> */
    private array $gpus = [];

    private Part $cpu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        // Indexable, because these tests are about what a crawler is told.
        // Unindexable is the test default and puts noindex on every page, which
        // would make the "noindex" assertions below pass for the wrong reason.
        config(['remarket.seo.indexable' => true]);

        $gpus =Part::query()->where('category', 'gpu')->where('is_published', true)->orderBy('id')->take(4)->get();

        $this->assertGreaterThanOrEqual(3, $gpus->count(), 'the seed needs three published graphics cards');

        $this->gpus = $gpus->all();

        $this->cpu = Part::query()->where('category', 'cpu')->where('is_published', true)->firstOrFail();

        // Known numbers, so every assertion below is arithmetic.
        $this->gpus[0]->forceFill(['specs' => ['vram_gb' => 12, 'tdp_w' => 200, 'length_mm' => 300]])->save();
        $this->gpus[1]->forceFill(['specs' => ['vram_gb' => 16, 'tdp_w' => 200, 'length_mm' => 250]])->save();
        $this->gpus[2]->forceFill(['specs' => ['vram_gb' => 16, 'tdp_w' => 285, 'length_mm' => 320]])->save();
    }

    private function url(array $parts, array $extra = []): string
    {
        return route('compare', array_merge(['m' => collect($parts)->pluck('slug')->implode(',')], $extra));
    }

    private function label(string $category, string $key): string
    {
        return config("catalog.categories.{$category}.specs.{$key}.label.bg");
    }

    // --- the page ---------------------------------------------------------

    public function test_the_empty_page_renders_and_is_kept_out_of_the_index(): void
    {
        $this->get(route('compare'))
            ->assertOk()
            ->assertSee('Избери модел, за да започнеш.')
            ->assertSee('noindex', false);
    }

    public function test_two_models_are_compared_and_indexable(): void
    {
        $response = $this->get($this->url([$this->gpus[0], $this->gpus[1]]))->assertOk();

        $response->assertSee($this->gpus[0]->fullName())
            ->assertSee($this->gpus[1]->fullName())
            ->assertSee($this->label('gpu', 'vram_gb'))
            ->assertDontSee('noindex', false);
    }

    public function test_one_model_alone_is_not_indexable(): void
    {
        $this->get($this->url([$this->gpus[0]]))
            ->assertOk()
            ->assertSee('Добави още един модел')
            ->assertSee('noindex', false);
    }

    /** The same set in any order is the same page, so there is one address for it. */
    public function test_the_canonical_address_does_not_depend_on_the_order(): void
    {
        $a = $this->get($this->url([$this->gpus[0], $this->gpus[1]]))->getContent();
        $b = $this->get($this->url([$this->gpus[1], $this->gpus[0]]))->getContent();

        preg_match('/rel="canonical" href="([^"]+)"/', $a, $ca);
        preg_match('/rel="canonical" href="([^"]+)"/', $b, $cb);

        $this->assertNotEmpty($ca[1]);
        $this->assertSame($ca[1], $cb[1]);
    }

    // --- highlighting ---------------------------------------------------------

    private function highlighted(string $html): string
    {
        preg_match_all('/<td[^>]*font-semibold text-accent[^>]*>\s*([^<]*?)\s*<\/td>/s', $html, $m);

        return implode('|', $m[1]);
    }

    public function test_the_larger_memory_is_highlighted(): void
    {
        $html = $this->get($this->url([$this->gpus[0], $this->gpus[1]]))->getContent();

        $this->assertStringContainsString('16', $this->highlighted($html));
        $this->assertStringNotContainsString('12', $this->highlighted($html));
    }

    public function test_a_tie_highlights_nothing(): void
    {
        // Both 16 GB: neither is better.
        $html = $this->get($this->url([$this->gpus[1], $this->gpus[2]]))->getContent();

        $this->assertSame('', $this->highlighted($html));
    }

    /**
     * Length and power draw are the specs where „better" is an argument, not a
     * fact. A highlight there would be quoted at a seller, and be wrong half
     * the time.
     */
    public function test_length_and_power_are_never_highlighted(): void
    {
        // Memory ties (16 vs 16); length and power differ. Nothing may be marked.
        $html = $this->get($this->url([$this->gpus[1], $this->gpus[2]]))->getContent();

        $this->assertSame('', $this->highlighted($html));
    }

    // --- differences only ------------------------------------------------------

    public function test_only_differences_hides_the_rows_that_agree(): void
    {
        // TDP is 200 on both; memory differs.
        $both = [$this->gpus[0], $this->gpus[1]];
        $tdp  = $this->label('gpu', 'tdp_w');
        $vram = $this->label('gpu', 'vram_gb');

        $this->get($this->url($both))->assertSee($tdp)->assertSee($vram);

        $this->get($this->url($both, ['razliki' => 1]))
            ->assertSee($vram)
            ->assertDontSee($tdp);
    }

    // --- one category ----------------------------------------------------------

    public function test_a_model_from_another_category_is_left_out_of_the_table(): void
    {
        $html = $this->get($this->url([$this->gpus[0], $this->cpu, $this->gpus[1]]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('head-'.$this->cpu->id, $html);
        $this->assertStringContainsString($this->gpus[1]->fullName(), $html);
    }

    public function test_an_unknown_or_unpublished_model_is_ignored(): void
    {
        $this->gpus[1]->forceFill(['is_published' => false])->save();

        $this->get(route('compare', ['m' => $this->gpus[0]->slug.',no-such-model,'.$this->gpus[1]->slug]))
            ->assertOk()
            ->assertSee($this->gpus[0]->fullName())
            ->assertDontSee($this->gpus[1]->fullName());
    }

    // --- adding and removing -------------------------------------------------

    public function test_a_model_can_be_added_and_removed(): void
    {
        Livewire::test(CompareParts::class)
            ->call('add', $this->gpus[0]->slug)
            ->assertSet('models', $this->gpus[0]->slug)
            ->call('add', $this->gpus[1]->slug)
            ->assertSet('models', $this->gpus[0]->slug.','.$this->gpus[1]->slug)
            ->call('remove', $this->gpus[0]->slug)
            ->assertSet('models', $this->gpus[1]->slug);
    }

    public function test_the_same_model_is_not_added_twice(): void
    {
        Livewire::test(CompareParts::class)
            ->call('add', $this->gpus[0]->slug)
            ->call('add', $this->gpus[0]->slug)
            ->assertSet('models', $this->gpus[0]->slug);
    }

    public function test_a_model_from_another_category_cannot_be_added(): void
    {
        Livewire::test(CompareParts::class)
            ->call('add', $this->gpus[0]->slug)
            ->call('add', $this->cpu->slug)
            ->assertSet('models', $this->gpus[0]->slug);
    }

    public function test_no_more_than_three_models_are_compared(): void
    {
        $component = Livewire::test(CompareParts::class);

        foreach (array_slice($this->gpus, 0, 4) as $gpu) {
            $component->call('add', $gpu->slug);
        }

        $this->assertCount(3, explode(',', $component->get('models')));
    }

    public function test_the_search_offers_models_from_the_same_category_only(): void
    {
        $component = Livewire::test(CompareParts::class)->call('add', $this->gpus[0]->slug);

        // The CPU's own name as the search term: it must not be offered next to a graphics card.
        $component->set('q', mb_substr($this->cpu->model, 0, 4))
            ->assertDontSee('wire:click="add(\''.$this->cpu->slug.'\')"', false);
    }

    // --- the way in ------------------------------------------------------------

    public function test_a_model_page_links_to_the_comparison(): void
    {
        $this->get(route('part', $this->gpus[0]))
            ->assertOk()
            ->assertSee(route('compare', ['m' => $this->gpus[0]->slug]), false);
    }
}
