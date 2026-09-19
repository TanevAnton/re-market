<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Listings\CreateListing;
use App\Livewire\Listings\EditListing;
use App\Models\City;
use App\Models\Listing;
use App\Models\User;
use App\Support\RequiredSpecs;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `required` used to be decorative on a listing-scoped spec.
 *
 * The wizard validated its scalar fields and left the spec blob alone, so three
 * specs were marked required and nothing enforced them anywhere:
 * `monitor.dead_pixels`, `prebuilt.cpu_model`, `prebuilt.gpu_model`. A flag
 * that reads as a guarantee and is not one is worse than no flag — it tells the
 * next person the question is already handled.
 *
 * TWO THINGS HAD TO BE TRUE BEFORE THIS COULD BE ENFORCED AT ALL.
 *
 * Every required spec must be answerable honestly, or a required field just
 * produces guesses, which is worse data than a blank. `dead_pixels` offers
 * „Не е проверено"; a seller listing a whole machine knows what is inside it,
 * and for `prebuilt` those two fields ARE the specification, because that
 * category has no catalogue row behind it.
 *
 * And the fields had to come OUT of „Подробности по желание". Validating them
 * where they were would reject a listing over a field inside a collapsed panel
 * headed „by choice" — one the seller cannot see and was told was optional.
 */
class RequiredSpecsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(CitySeeder::class);

        $this->seller = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);
    }

    // --- the schema still says what this test thinks it says --------------

    /**
     * If somebody drops the flag, these tests stop covering anything — and
     * would keep passing while doing it.
     */
    public function test_the_three_specs_are_still_marked_required(): void
    {
        $this->assertSame(['dead_pixels'], array_keys(RequiredSpecs::forCategory('monitor')));

        $this->assertSame(
            ['cpu_model', 'gpu_model'],
            array_keys(RequiredSpecs::forCategory('prebuilt')),
        );

        // A category with none must produce no rules at all, or every listing
        // on the site would suddenly need a field nobody defined.
        $this->assertSame([], RequiredSpecs::rules('gpu'));
        $this->assertSame([], RequiredSpecs::rules(null));
    }

    /**
     * The precondition for enforcing any of this: a seller can always answer
     * without inventing something.
     */
    public function test_every_required_spec_can_be_answered_honestly(): void
    {
        foreach (array_keys(config('catalog.categories')) as $category) {
            foreach (RequiredSpecs::forCategory($category) as $key => $spec) {
                if (! isset($spec['options'])) {
                    // Free text: the seller writes what is in the machine.
                    continue;
                }

                $escape = array_filter(
                    $spec['options'],
                    fn ($o) => is_string($o) && (
                        str_contains(mb_strtolower($o), 'не знам')
                        || str_contains(mb_strtolower($o), 'не е провер')
                    ),
                );

                $this->assertNotEmpty($escape,
                    "[{$category}.{$key}] is required but offers no honest way out, "
                    .'so it will collect guesses rather than facts');
            }
        }
    }

    // --- the wizard -------------------------------------------------------

    private function wizardAtStepThree(string $category)
    {
        return Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->set('category', $category)
            ->call('next')
            ->call('next')
            ->assertSet('step', 3);
    }

    public function test_the_wizard_refuses_to_advance_without_a_required_spec(): void
    {
        $this->wizardAtStepThree('monitor')
            ->set('title', 'Dell S2721DGF, 27 инча, без забележки')
            ->set('description', 'Мониторът е от домашна машина, работи без проблем и е в отлично състояние.')
            ->set('specs', [])
            ->call('next')
            ->assertHasErrors(['specs.dead_pixels' => 'required'])
            ->assertSet('step', 3);
    }

    public function test_answering_it_lets_the_listing_through(): void
    {
        $this->wizardAtStepThree('monitor')
            ->set('title', 'Dell S2721DGF, 27 инча, без забележки')
            ->set('description', 'Мониторът е от домашна машина, работи без проблем и е в отлично състояние.')
            ->set('specs', ['dead_pixels' => 'Не е проверено'])
            ->call('next')
            ->assertHasNoErrors('specs.dead_pixels');
    }

    /** „Не е проверено" is a real answer. Made-up ones are not. */
    public function test_a_value_outside_the_options_is_rejected(): void
    {
        $this->wizardAtStepThree('monitor')
            ->set('title', 'Dell S2721DGF, 27 инча, без забележки')
            ->set('description', 'Мониторът е от домашна машина, работи без проблем и е в отлично състояние.')
            ->set('specs', ['dead_pixels' => 'няма представа'])
            ->call('next')
            ->assertHasErrors(['specs.dead_pixels' => 'in']);
    }

    /** Both of prebuilt's, because that category has no catalogue behind it. */
    public function test_a_prebuilt_must_say_what_is_inside_it(): void
    {
        $this->wizardAtStepThree('prebuilt')
            ->set('title', 'Сглобен компютър за игри, готов за ползване')
            ->set('description', 'Машината е сглобена и тествана, с чиста инсталация на Windows 11.')
            ->set('specs', ['cpu_model' => 'AMD Ryzen 5 5600'])
            ->call('next')
            ->assertHasNoErrors('specs.cpu_model')
            ->assertHasErrors(['specs.gpu_model' => 'required']);
    }

    // --- the field is where the seller can see it -------------------------

    /**
     * Rejecting a listing over a field inside a collapsed „by choice" panel is
     * the bug this feature would otherwise have introduced, so the field is
     * rendered before the panel is ever opened.
     */
    public function test_the_required_field_is_on_screen_without_opening_the_optional_panel(): void
    {
        /*
         * `prebuilt`, not `monitor`, and that choice is the test.
         *
         * Monitor has exactly one listing-scoped spec and it is the required
         * one, so „the optional spec is not visible" would pass there whatever
         * the template did — there is no optional spec to be visible. Prebuilt
         * has two required and three optional, so the control means something.
         */
        $wizard = $this->wizardAtStepThree('prebuilt');

        // The panel is shut...
        $wizard->assertSet('showOptional', false);

        // ...and both required fields are on screen anyway.
        $wizard->assertSee('Процесор')
                ->assertSee('Видеокарта');

        // ...while the optional ones are not.
        $wizard->assertDontSee('С лицензиран Windows');
    }

    /** And it is not ALSO in the optional list, which would be two of it. */
    public function test_a_required_spec_is_not_repeated_inside_the_optional_panel(): void
    {
        $wizard = Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->set('category', 'prebuilt');

        $required = $wizard->instance()->requiredItemSpecs();
        $optional = $wizard->instance()->optionalItemSpecs();

        $this->assertSame(['cpu_model', 'gpu_model'], array_keys($required));
        $this->assertSame([], array_intersect(array_keys($required), array_keys($optional)),
            'a spec is rendered in both lists, so the seller sees two of it');

        // And between them they still cover every listing-scoped spec — a
        // split that loses one would silently remove a field from the form.
        // Compared as sets: which of the two lists a spec lands in is the
        // point, the order within them is the schema's business.
        $all   = array_keys($wizard->instance()->itemSpecs());
        $split = array_merge(array_keys($required), array_keys($optional));

        sort($all);
        sort($split);

        $this->assertSame($all, $split, 'the split dropped or duplicated a spec');
    }

    /** The error names the field, not the column. */
    public function test_the_message_is_addressed_to_a_seller(): void
    {
        $messages = RequiredSpecs::messages('monitor');

        $this->assertArrayHasKey('specs.dead_pixels.required', $messages);
        $this->assertStringContainsString('Дефектни пиксели', $messages['specs.dead_pixels.required']);
        $this->assertStringNotContainsString('specs.', $messages['specs.dead_pixels.required']);
    }

    // --- and it cannot be undone afterwards -------------------------------

    /**
     * Enforcing on the way in only would be the same hole with an extra step:
     * publish with the field filled, then blank it on the edit screen.
     */
    public function test_the_edit_screen_will_not_let_a_required_spec_be_blanked(): void
    {
        $listing = Listing::factory()->create([
            'user_id'  => $this->seller->id,
            'category' => 'monitor',
            'part_id'  => null,
            'city_id'  => City::first()->id,
            'status'   => ListingStatus::Active,
            'specs'    => ['dead_pixels' => 'Няма'],
        ]);

        Livewire::actingAs($this->seller)
            ->test(EditListing::class, ['listing' => $listing])
            ->set('specs', ['dead_pixels' => ''])
            ->call('save')
            ->assertHasErrors('specs.dead_pixels');

        $this->assertSame('Няма', $listing->fresh()->specs['dead_pixels'],
            'the spec was cleared in the database despite the validation error');
    }
}
