<?php

namespace Tests\Feature;

use App\Livewire\Listings\CreateListing;
use App\Models\City;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The listing form, reworked: photos first, one search that answers category
 * and model together, category tiles, a live preview and a completeness meter.
 *
 * The four internal steps and their validation are unchanged and covered by
 * the older wizard tests; these cover what was added on top.
 */
class CreateListingRedesignTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);
    }

    private function form()
    {
        return Livewire::actingAs($this->seller)->test(CreateListing::class);
    }

    // --- step 1 ------------------------------------------------------------

    public function test_the_first_screen_offers_photos_search_and_category_tiles(): void
    {
        $this->form()
            ->assertSee('Какво продаваш?')
            ->assertSeeHtml('data-photo-block')
            ->assertSeeHtml('wire:model="photos"')
            ->assertSee('Знаеш модела? Напиши го')
            ->assertSee('или избери категория')
            ->assertSeeHtml("wire:click=\"pickCategory('gpu')\"");
    }

    public function test_the_photos_come_before_the_search_on_the_first_step(): void
    {
        $html = $this->form()->html();

        $this->assertLessThan(
            strpos($html, 'Знаеш модела? Напиши го'),
            strpos($html, 'data-photo-block'),
        );
        $this->assertStringContainsString('order-3', $html);
    }

    public function test_a_category_tile_chooses_and_moves_on_in_one_tap(): void
    {
        $this->form()
            ->call('pickCategory', 'gpu')
            ->assertSet('category', 'gpu')
            ->assertSet('step', 2)
            ->assertHasNoErrors();
    }

    public function test_an_unknown_category_is_ignored(): void
    {
        $this->form()
            ->call('pickCategory', 'no-such-category')
            ->assertSet('category', '')
            ->assertSet('step', 1);
    }

    public function test_changing_category_drops_the_model_chosen_under_the_old_one(): void
    {
        $gpu = Part::where('category', 'gpu')->firstOrFail();

        $this->form()
            ->set('category', 'gpu')
            ->call('choosePart', $gpu->id)
            ->assertSet('partId', $gpu->id)
            ->assertSet('title', $gpu->fullName())
            ->call('pickCategory', 'cpu')
            ->assertSet('category', 'cpu')
            ->assertSet('partId', null)
            ->assertSet('title', '');
    }

    // --- the one search -------------------------------------------------

    public function test_the_search_matches_across_categories_and_names_the_category(): void
    {
        $gpu = Part::where('category', 'gpu')->firstOrFail();

        $this->form()
            ->set('partSearch', $gpu->model)
            ->assertSee($gpu->fullName())
            ->assertSeeHtml('wire:click="chooseAnyPart('.$gpu->id.')"');
    }

    public function test_a_result_sets_category_and_model_and_skips_the_model_step(): void
    {
        $cpu = Part::where('category', 'cpu')->firstOrFail();

        $this->form()
            ->call('chooseAnyPart', $cpu->id)
            ->assertSet('category', 'cpu')
            ->assertSet('partId', $cpu->id)
            ->assertSet('title', $cpu->fullName())
            ->assertSet('step', 3);
    }

    public function test_a_part_that_does_not_exist_is_ignored(): void
    {
        $this->form()
            ->call('chooseAnyPart', 99999999)
            ->assertSet('category', '')
            ->assertSet('step', 1);
    }

    public function test_the_cross_category_search_is_quiet_until_there_is_something_to_search_for(): void
    {
        $form = $this->form();

        $this->assertCount(0, $form->instance()->anyResults());

        $form->set('partSearch', 'r');
        $this->assertCount(0, $form->instance()->anyResults());
    }

    public function test_once_a_category_is_chosen_the_cross_category_search_stands_down(): void
    {
        $gpu = Part::where('category', 'gpu')->firstOrFail();

        $form = $this->form()->set('category', 'gpu')->set('partSearch', $gpu->model);

        $this->assertCount(0, $form->instance()->anyResults());
    }

    // --- the three visible steps -------------------------------------------

    public function test_four_internal_steps_read_as_three_to_the_seller(): void
    {
        $form = $this->form();

        $this->assertSame(1, $form->instance()->visibleStep());

        $form->call('pickCategory', 'gpu');   // step 2
        $this->assertSame(1, $form->instance()->visibleStep());

        $form->call('chooseAnyPart', Part::where('category', 'gpu')->firstOrFail()->id);   // step 3
        $this->assertSame(2, $form->instance()->visibleStep());
    }

    public function test_the_photo_block_is_on_the_first_and_details_steps_only(): void
    {
        $gpu = Part::where('category', 'gpu')->firstOrFail();

        $this->form()->assertSeeHtml('data-photo-block')
            ->call('pickCategory', 'gpu')
            ->assertSet('step', 2)
            ->assertDontSeeHtml('data-photo-block')
            ->call('choosePart', $gpu->id)
            ->call('next')
            ->assertSet('step', 3)
            ->assertSeeHtml('data-photo-block');
    }

    // --- preview and meter ----------------------------------------------

    public function test_the_preview_follows_what_is_typed(): void
    {
        $gpu = Part::where('category', 'gpu')->firstOrFail();

        $this->form()
            ->call('chooseAnyPart', $gpu->id)
            ->set('title', 'Моята тестова видеокарта')
            ->set('price', '450,5')
            ->assertSeeHtml('data-listing-aside')
            ->assertSee('Моята тестова видеокарта')
            ->assertSee('450,50 €');
    }

    public function test_an_empty_form_scores_nothing_and_says_what_to_do_first(): void
    {
        // The city is prefilled from the seller's profile, which is a real
        // answer and is counted; clear it to look at a form nobody has touched.
        $meter = $this->form()->set('city_id', null)->instance()->completeness();

        $this->assertSame(0, $meter['percent']);
        $this->assertSame('start', $meter['level']);
        $this->assertCount(3, $meter['tips']);
        $this->assertSame('Добави поне една снимка.', $meter['tips'][0]);
    }

    public function test_filling_things_in_raises_the_score_and_changes_the_advice(): void
    {
        $gpu = Part::where('category', 'gpu')->firstOrFail();

        $form = $this->form()
            ->call('chooseAnyPart', $gpu->id)
            ->set('description', str_repeat('Работи без забележки. ', 8))
            ->set('price', '300')
            ->set('city_id', City::first()->id);

        $meter = $form->instance()->completeness();

        $this->assertGreaterThan(40, $meter['percent']);
        $this->assertNotContains('Сложи цена.', $meter['tips']);
        $this->assertNotContains('Избери град.', $meter['tips']);
    }

    public function test_the_meter_never_exceeds_a_hundred_or_goes_below_nothing(): void
    {
        $meter = $this->form()->instance()->completeness();

        $this->assertGreaterThanOrEqual(0, $meter['percent']);
        $this->assertLessThanOrEqual(100, $meter['percent']);
    }

    public function test_the_meter_says_it_is_not_a_forecast(): void
    {
        $this->form()->assertSee('а не колко бързо ще се продаде');
    }

    // --- drag and drop -----------------------------------------------------

    public function test_the_drop_zone_is_a_real_file_input_with_a_drop_handler(): void
    {
        $html = $this->form()->html();

        $this->assertStringContainsString('type="file"', $html);
        $this->assertStringContainsString('x-on:drop.prevent', $html);
        // A drop is cut to five files, for the same reason the hint says five.
        $this->assertStringContainsString('.slice(0, 5)', $html);
    }

    // --- nothing worth keeping yet ------------------------------------------

    public function test_the_first_step_still_writes_no_draft_without_photos(): void
    {
        $this->form()->call('saveDraft');

        $this->assertDatabaseCount('listing_drafts', 0);
    }
}
