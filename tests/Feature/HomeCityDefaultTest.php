<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\BrowseListings;
use App\Models\City;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Starting a signed-in visitor in their own city.
 *
 * Half the exchanges here are hand-to-hand, so „мога ли да я взема лично" is
 * one of the first questions a buyer has, and answering it by default is most
 * of the difference between a national wall of listings and a local market.
 *
 * The danger is the same thing from the other side: a filter nobody chose,
 * which makes the site look like it has a third of the listings it really has.
 * So the default announces itself, is one click away from being undone, is
 * offered once per session rather than reapplied, and never fires when it
 * would empty the page. Most of what is tested below is those four brakes.
 *
 * It is done as a redirect, so the URL says what is being shown - the page
 * stays shareable, bookmarkable and back-buttonable, and the filter is never
 * hidden state.
 */
class HomeCityDefaultTest extends TestCase
{
    use RefreshDatabase;

    private City $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->home = City::orderByDesc('population')->firstOrFail();
    }

    private function local(?City $city = null): User
    {
        return User::factory()->create(['city_id' => ($city ?? $this->home)->id]);
    }

    private function stock(City $city, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Listing::factory()->create([
                'user_id' => User::factory()->create()->id,
                'city_id' => $city->id,
                'status'  => ListingStatus::Active,
            ]);
        }
    }

    // --- when it fires ----------------------------------------------------

    public function test_a_local_visitor_starts_in_their_own_city(): void
    {
        $this->stock($this->home, 3);

        $this->actingAs($this->local())
            ->get(route('browse'))
            ->assertRedirect(route('browse', ['grad' => $this->home->slug]));
    }

    /** The filters they arrived with survive the redirect. */
    public function test_the_rest_of_the_query_string_is_kept(): void
    {
        $this->stock($this->home, 3);

        $response = $this->actingAs($this->local())
            ->get(route('browse', ['kat' => 'gpu']));

        $response->assertRedirectContains('kat=gpu');
        $response->assertRedirectContains('grad='.$this->home->slug);
    }

    /** And the page it lands on says why it is showing less than everything. */
    public function test_the_page_says_the_filter_was_not_the_visitor_s_idea(): void
    {
        $this->stock($this->home, 3);

        $this->actingAs($this->local())
            ->followingRedirects()
            ->get(route('browse'))
            ->assertOk()
            ->assertSee('защото това е твоят град')
            ->assertSee($this->home->name())
            ->assertSee('Виж цялата страна');
    }

    // --- when it does not --------------------------------------------------

    /**
     * Guests are never redirected, which also keeps every crawled URL exactly
     * what was requested - the browse canonicals depend on it.
     */
    public function test_a_guest_sees_the_whole_country(): void
    {
        $this->stock($this->home, 3);

        $this->get(route('browse'))->assertOk();
    }

    public function test_a_url_that_already_names_a_city_is_left_alone(): void
    {
        $this->stock($this->home, 3);

        $this->actingAs($this->local())
            ->get(route('browse', ['grad' => 'plovdiv']))
            ->assertOk();
    }

    /** Including an explicitly emptied one: `?grad=` is a decision. */
    public function test_an_explicitly_emptied_city_is_a_decision(): void
    {
        $this->stock($this->home, 3);

        $this->actingAs($this->local())
            ->get(route('browse').'?grad=')
            ->assertOk();
    }

    /**
     * Once per session. Otherwise clearing the filter is undone by the next
     * page load, and the visitor is arguing with the site.
     */
    public function test_it_is_offered_once_and_not_reapplied(): void
    {
        $this->stock($this->home, 3);

        $user = $this->local();

        $this->actingAs($user)->get(route('browse'))->assertRedirect();
        $this->actingAs($user)->get(route('browse'))->assertOk();
    }

    /**
     * A city with two listings in it is a page that says the site is dead, and
     * the visitor has no way of knowing a filter they never set is the reason.
     */
    public function test_a_quiet_town_is_not_defaulted_to(): void
    {
        $quiet = City::orderBy('population')->firstOrFail();

        $this->stock($quiet, 2);
        $this->stock($this->home, 10);

        $this->actingAs($this->local($quiet))
            ->get(route('browse'))
            ->assertOk();
    }

    public function test_a_user_who_never_said_where_they_are_is_not_guessed_at(): void
    {
        $this->stock($this->home, 3);

        $this->actingAs(User::factory()->create(['city_id' => null]))
            ->get(route('browse'))
            ->assertOk();
    }

    /**
     * Mounting the grid outside its own route must not redirect the page it is
     * embedded in - and must not surprise every other test that mounts it.
     */
    public function test_mounting_the_component_directly_never_redirects(): void
    {
        $this->stock($this->home, 3);

        Livewire::actingAs($this->local())
            ->test(BrowseListings::class)
            ->assertNoRedirect()
            ->assertSet('city', '');
    }

    // --- undoing it -------------------------------------------------------

    public function test_one_click_returns_the_whole_country(): void
    {
        Livewire::actingAs($this->local())
            ->test(BrowseListings::class)
            ->set('city', $this->home->slug)
            ->set('cityDefaulted', true)
            ->call('showWholeCountry')
            ->assertSet('city', '')
            ->assertSet('cityDefaulted', false);
    }

    /** Touching the dropdown is the visitor's own choice, so the notice goes. */
    public function test_choosing_a_city_by_hand_drops_the_notice(): void
    {
        Livewire::actingAs($this->local())
            ->test(BrowseListings::class)
            ->set('cityDefaulted', true)
            ->set('city', $this->home->slug)
            ->assertSet('cityDefaulted', false);
    }

    /**
     * The dropdown lists the forty biggest towns. The visitor this feature is
     * aimed at is disproportionately NOT from one of them - and finding your
     * own city missing from the control while the results are filtered by it
     * is the exact moment the page stops making sense.
     */
    public function test_a_small_town_being_filtered_on_is_in_the_dropdown(): void
    {
        $small = City::orderBy('population')->firstOrFail();

        $cities = Livewire::test(BrowseListings::class)
            ->set('city', $small->slug)
            ->viewData('cities');

        $this->assertTrue(
            $cities->contains('slug', $small->slug),
            'the filtered city is missing from the control that sets it',
        );
    }
}
