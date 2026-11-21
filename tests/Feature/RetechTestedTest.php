<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\ShowListing;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Support\RetechOffer;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Тествано от RE-Tech".
 *
 * The claim is made by somebody other than the seller, which is the whole
 * reason it is worth anything - so the tests are about who can make it, when it
 * stops being true, and that it never appears without saying that the shop and
 * the site are one company.
 */
class RetechTestedTest extends TestCase
{
    use RefreshDatabase;

    private const DISCLOSURE = 'са на една и съща фирма';

    private User $seller;
    private User $admin;
    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller = User::factory()->create();
        $this->admin  = User::factory()->create(['is_admin' => true]);

        $this->listing = Listing::factory()->create([
            'user_id' => $this->seller->id,
            'status'  => ListingStatus::Active,
            'title'   => 'Видеокарта за тест',
        ]);
    }

    private function mark(Listing $listing, ?string $note = null): Listing
    {
        // forceFill: none of the three is fillable, which is the next test.
        $listing->forceFill([
            'retech_tested_at' => now(),
            'retech_test_note' => $note,
            'retech_tested_by' => $this->admin->id,
        ])->save();

        return $listing->fresh();
    }

    // --- who can say it ---------------------------------------------------

    public function test_a_seller_cannot_set_it_through_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);

        Listing::create([
            'user_id'          => $this->seller->id,
            'title'            => 'Подправена обява',
            'retech_tested_at' => now(),
        ]);
    }

    public function test_an_administrator_can_mark_a_listing_with_a_note(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ShowListing::class, ['listing' => $this->listing])
            ->set('retechNote', '3DMark 20 мин, 68 °C')
            ->call('markRetechTested')
            ->assertHasNoErrors();

        $fresh = $this->listing->fresh();

        $this->assertTrue($fresh->isRetechTested());
        $this->assertSame('3DMark 20 мин, 68 °C', $fresh->retech_test_note);
        $this->assertSame($this->admin->id, (int) $fresh->retech_tested_by);
    }

    public function test_the_seller_cannot_mark_their_own_listing(): void
    {
        Livewire::actingAs($this->seller)
            ->test(ShowListing::class, ['listing' => $this->listing])
            ->call('markRetechTested')
            ->assertForbidden();

        $this->assertFalse($this->listing->fresh()->isRetechTested());
    }

    public function test_a_visitor_cannot_mark_anything(): void
    {
        Livewire::test(ShowListing::class, ['listing' => $this->listing])
            ->call('markRetechTested')
            ->assertForbidden();

        $this->assertFalse($this->listing->fresh()->isRetechTested());
    }

    public function test_the_controls_are_drawn_for_administrators_only(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ShowListing::class, ['listing' => $this->listing])
            ->assertSee('Отбележи като тествано');

        Livewire::actingAs($this->seller)
            ->test(ShowListing::class, ['listing' => $this->listing])
            ->assertDontSee('Отбележи като тествано');
    }

    public function test_a_note_over_the_limit_is_refused(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ShowListing::class, ['listing' => $this->listing])
            ->set('retechNote', str_repeat('а', 161))
            ->call('markRetechTested')
            ->assertHasErrors('retechNote');

        $this->assertFalse($this->listing->fresh()->isRetechTested());
    }

    public function test_an_administrator_can_take_it_off(): void
    {
        $this->mark($this->listing, 'тест');

        Livewire::actingAs($this->admin)
            ->test(ShowListing::class, ['listing' => $this->listing->fresh()])
            ->call('clearRetechTested');

        $fresh = $this->listing->fresh();

        $this->assertFalse($fresh->isRetechTested());
        $this->assertNull($fresh->retech_test_note);
        $this->assertNull($fresh->retech_tested_by);
    }

    public function test_a_seller_cannot_take_it_off_either(): void
    {
        $this->mark($this->listing);

        Livewire::actingAs($this->seller)
            ->test(ShowListing::class, ['listing' => $this->listing->fresh()])
            ->call('clearRetechTested')
            ->assertForbidden();

        $this->assertTrue($this->listing->fresh()->isRetechTested());
    }

    // --- when it stops being true -----------------------------------------

    public function test_changing_what_was_tested_removes_the_mark(): void
    {
        foreach ([
            'title' => 'Съвсем друго заглавие',
            // Unique, so it can never equal whatever the factory wrote.
            'specs' => ['probe' => uniqid('', true)],
        ] as $column => $value) {
            $listing = $this->mark(Listing::factory()->create([
                'user_id' => $this->seller->id,
                'status'  => ListingStatus::Active,
            ]), 'тест');

            $listing->forceFill([$column => $value])->save();

            $this->assertFalse($listing->fresh()->isRetechTested(), "changing {$column} should remove the mark");
            $this->assertNull($listing->fresh()->retech_test_note);
        }
    }

    public function test_changing_the_model_removes_the_mark(): void
    {
        $listing = $this->mark($this->listing);

        $other = Part::query()->where('id', '!=', (int) $listing->part_id)->firstOrFail();

        $listing->forceFill(['part_id' => $other->id])->save();

        $this->assertFalse($listing->fresh()->isRetechTested());
    }

    public function test_changing_the_price_does_not(): void
    {
        $listing = $this->mark($this->listing);

        $listing->forceFill(['price_cents' => $listing->price_cents + 1000])->save();

        $this->assertTrue($listing->fresh()->isRetechTested());
    }

    public function test_marking_itself_is_not_mistaken_for_an_edit(): void
    {
        $listing = $this->listing;

        $listing->forceFill([
            'title'            => 'Ново заглавие и тест в един запис',
            'retech_tested_at' => now(),
        ])->save();

        $this->assertTrue($listing->fresh()->isRetechTested());
    }

    // --- what the public sees ---------------------------------------------

    public function test_the_listing_page_shows_the_mark_the_note_and_the_disclosure(): void
    {
        config(['remarket.retech.name' => 'Магазин']);

        $this->mark($this->listing, 'FurMark 30 мин');

        $this->get(route('listing', $this->listing))
            ->assertOk()
            ->assertSee('Тествано от Магазин')
            ->assertSee('FurMark 30 мин')
            ->assertSee(self::DISCLOSURE);
    }

    public function test_the_note_is_shown_as_text_not_as_markup(): void
    {
        $this->mark($this->listing, '<script>alert(1)</script>');

        $this->get(route('listing', $this->listing))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_a_grid_card_carries_the_chip_and_the_disclosure_as_its_tooltip(): void
    {
        $this->mark($this->listing);

        $html = $this->get(route('browse'))->assertOk()->getContent();

        $this->assertStringContainsString('Тествано от', $html);
        $this->assertStringContainsString('title="'.e(RetechOffer::disclosure()).'"', $html);
    }

    public function test_an_untested_listing_shows_nothing_of_it(): void
    {
        $this->get(route('listing', $this->listing))
            ->assertOk()
            ->assertDontSee('Тествано от');

        $this->get(route('browse'))
            ->assertOk()
            ->assertDontSee('Тествано от');
    }

    public function test_the_disclosure_names_both_sides_and_the_company(): void
    {
        config([
            'remarket.retech.name' => 'Магазин',
            'app.name'             => 'Сайтът',
            'legal.entity.name'    => 'Фирма ООД',
        ]);

        $this->assertSame('Магазин и Сайтът са на една и съща фирма — Фирма ООД.', RetechOffer::disclosure());
    }

    public function test_the_mark_does_not_depend_on_the_buyback_offer_being_switched_on(): void
    {
        config(['remarket.retech.buyback_url' => '']);

        $this->mark($this->listing);

        $this->get(route('listing', $this->listing))
            ->assertOk()
            ->assertSee('Тествано от');
    }
}
