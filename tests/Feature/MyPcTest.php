<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\MyComputer;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Support\Compatibility;
use App\Support\MyPc;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Моят компютър" - does this part fit what I already have.
 *
 * The check claims only what two typed facts settle, so the tests are mostly
 * arithmetic at the edges: 500 W is enough for a card that asks for 500 W, 499
 * is not; a platform that is one form factor smaller fits and one larger does
 * not. And the two things that make it safe to leave on a public page: values
 * nobody offered are never stored, and where the catalogue or the buyer has not
 * said, the panel says nothing rather than guessing.
 */
class MyPcTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
    }

    /**
     * Made here rather than read from the seeder, which only carries graphics
     * cards and processors: the point of these tests is the spec values, and
     * they should not depend on which models happen to be seeded.
     */
    private function part(string $category, array $specs): Part
    {
        static $n = 0;
        $n++;

        return Part::create([
            'category'     => $category,
            'manufacturer' => 'Test',
            'model'        => "Fit {$category} {$n}",
            'slug'         => "fit-{$category}-{$n}",
            'specs'        => $specs,
            'is_published' => true,
        ]);
    }

    /** @return list<string> */
    private function statuses(Part $part, array $pc): array
    {
        return array_column(MyPc::check($part, $pc)['rows'], 'status');
    }

    // --- graphics cards ---------------------------------------------------

    public function test_a_card_is_checked_against_the_power_supply_at_the_exact_threshold(): void
    {
        $gpu = $this->part('gpu', ['tdp_w' => 285, 'length_mm' => 300]);

        $need = Compatibility::psuNeededFor(285);

        $this->assertSame(500, $need);
        $this->assertSame(['ok'], $this->statuses($gpu, ['psu_w' => $need]));
        $this->assertSame(['no'], $this->statuses($gpu, ['psu_w' => $need - 1]));
    }

    public function test_the_wattage_the_panel_states_is_the_one_the_model_page_links_to(): void
    {
        $gpu = $this->part('gpu', ['tdp_w' => 285]);

        $filters = Compatibility::filtersBetween($gpu, 'psu');

        $this->assertSame(Compatibility::psuNeededFor(285), $filters[0]['filter']['min']);
    }

    public function test_a_card_longer_than_the_case_does_not_fit(): void
    {
        $gpu = $this->part('gpu', ['length_mm' => 320]);

        $this->assertSame(['ok'], $this->statuses($gpu, ['case_max_gpu_mm' => 320]));
        $this->assertSame(['no'], $this->statuses($gpu, ['case_max_gpu_mm' => 319]));
    }

    public function test_it_asks_for_what_it_needs_and_says_nothing_about_the_rest(): void
    {
        $gpu = $this->part('gpu', ['tdp_w' => 200, 'length_mm' => 300]);

        $check = MyPc::check($gpu, ['psu_w' => 650]);

        $this->assertCount(1, $check['rows']);
        $this->assertSame(['Най-дълга видеокарта, която влиза в кутията'], $check['missing']);
    }

    public function test_a_model_with_no_specs_is_not_nagged_about_the_buyers_computer(): void
    {
        $gpu = $this->part('gpu', []);

        $check = MyPc::check($gpu, []);

        $this->assertTrue($check['applies']);
        $this->assertSame([], $check['rows']);
        $this->assertSame([], $check['missing']);
    }

    // --- platform ---------------------------------------------------------

    public function test_a_processor_for_another_socket_is_refused(): void
    {
        $cpu = $this->part('cpu', ['socket' => 'AM5']);

        $this->assertSame(['no'], $this->statuses($cpu, ['socket' => 'AM4']));
        $this->assertSame(['ok'], $this->statuses($cpu, ['socket' => 'AM5']));
    }

    public function test_a_board_is_checked_for_socket_memory_and_size_separately(): void
    {
        $board = $this->part('motherboard', ['socket' => 'AM4', 'ram_type' => 'DDR4', 'form_factor' => 'ATX']);

        $this->assertSame(
            ['ok', 'no', 'no'],
            $this->statuses($board, ['socket' => 'AM4', 'ram_type' => 'DDR5', 'case_form_factor' => 'Micro-ATX']),
        );

        $this->assertSame(
            ['ok', 'ok', 'ok'],
            $this->statuses($board, ['socket' => 'AM4', 'ram_type' => 'DDR4', 'case_form_factor' => 'E-ATX']),
        );
    }

    public function test_memory_of_another_generation_is_refused(): void
    {
        $ram = $this->part('ram', ['type' => 'DDR5']);

        $this->assertSame(['no'], $this->statuses($ram, ['ram_type' => 'DDR4']));
    }

    public function test_a_cooler_must_list_the_buyers_socket(): void
    {
        $cooler = $this->part('cooler', ['sockets' => ['AM4', 'AM5']]);

        $this->assertSame(['ok'], $this->statuses($cooler, ['socket' => 'AM5']));
        $this->assertSame(['no'], $this->statuses($cooler, ['socket' => 'LGA1700']));
    }

    // --- power supplies and cases ----------------------------------------

    public function test_a_power_supply_is_checked_against_the_buyers_card(): void
    {
        $psu = $this->part('psu', ['wattage' => 450]);

        // A 285 W card asks for 500 W.
        $this->assertSame(['no'], $this->statuses($psu, ['gpu_tdp_w' => 285]));
        // A 200 W card asks for the 450 W floor.
        $this->assertSame(['ok'], $this->statuses($psu, ['gpu_tdp_w' => 200]));
    }

    public function test_a_case_is_checked_for_the_board_and_for_the_card(): void
    {
        $case = $this->part('case', ['form_factor' => ['ATX', 'Micro-ATX'], 'max_gpu_mm' => 330]);

        $this->assertSame(['ok', 'ok'], $this->statuses($case, ['board_form_factor' => 'ATX', 'gpu_length_mm' => 330]));
        $this->assertSame(['no', 'no'], $this->statuses($case, ['board_form_factor' => 'Mini-ITX', 'gpu_length_mm' => 331]));
    }

    public function test_a_category_with_nothing_to_check_draws_no_panel(): void
    {
        $monitor = $this->part('monitor', ['refresh_hz' => 144]);

        $this->assertFalse(MyPc::check($monitor, ['psu_w' => 650])['applies']);
        $this->assertFalse(MyPc::check(null, [])['applies']);
    }

    // --- storage ----------------------------------------------------------

    public function test_only_what_the_catalogue_offers_is_kept(): void
    {
        $kept = MyPc::clean([
            'socket'          => 'AM4',
            'ram_type'        => 'DDR9',          // not an option
            'psu_w'           => '650',           // a string from a form
            'gpu_tdp_w'       => 9_999_999,       // not a graphics card
            'case_max_gpu_mm' => '',              // left blank
            'board_form_factor' => ['ATX'],       // an array where a string belongs
            'something_else'  => 'x',
        ]);

        $this->assertEquals(['socket' => 'AM4', 'psu_w' => 650], $kept);
    }

    public function test_a_visitor_without_an_account_keeps_it_for_the_session(): void
    {
        MyPc::save(['socket' => 'AM4']);

        $this->assertSame(['socket' => 'AM4'], MyPc::current());
    }

    public function test_an_account_keeps_it_on_the_account(): void
    {
        $user = User::factory()->create();

        MyPc::save(['socket' => 'LGA1700', 'psu_w' => 750], $user);

        $this->assertEquals(['socket' => 'LGA1700', 'psu_w' => 750], $user->fresh()->my_pc);
        $this->assertEquals(['socket' => 'LGA1700', 'psu_w' => 750], MyPc::current($user->fresh()));
    }

    public function test_forgetting_empties_both(): void
    {
        $user = User::factory()->create();

        MyPc::save(['socket' => 'AM4'], $user);
        MyPc::forget($user);

        $this->assertNull($user->fresh()->my_pc);
        $this->assertSame([], MyPc::current($user->fresh()));
    }

    public function test_it_is_in_the_data_export_and_gone_after_deletion(): void
    {
        $source = file_get_contents(app_path('Services/Privacy/PersonalDataExport.php'));
        $this->assertStringContainsString('$user->my_pc', $source);

        $source = file_get_contents(app_path('Services/Privacy/AccountDeletion.php'));
        $this->assertStringContainsString("'my_pc'", $source);
    }

    // --- the form ---------------------------------------------------------

    public function test_the_page_renders_for_a_visitor_and_stays_out_of_the_index(): void
    {
        $this->get(route('my-pc'))
            ->assertOk()
            ->assertSee('Моят компютър')
            ->assertSee('noindex', false);
    }

    public function test_saving_the_form_keeps_valid_values_and_drops_forged_ones(): void
    {
        Livewire::test(MyComputer::class)
            ->set('pc.socket', 'AM4')
            ->set('pc.psu_w', '650')
            ->set('pc.ram_type', 'DDR9')
            ->set('pc.gpu_tdp_w', '99999999')
            ->call('save')
            ->assertSet('saved', true);

        $this->assertEquals(['socket' => 'AM4', 'psu_w' => 650], MyPc::current());
    }

    public function test_clearing_the_form_forgets_everything(): void
    {
        MyPc::save(['socket' => 'AM4']);

        Livewire::test(MyComputer::class)
            ->assertSet('pc.socket', 'AM4')
            ->call('clear')
            ->assertSet('pc.socket', '');

        $this->assertSame([], MyPc::current());
    }

    public function test_a_signed_in_user_sees_their_saved_computer(): void
    {
        $user = User::factory()->create();
        MyPc::save(['socket' => 'LGA1700'], $user);

        Livewire::actingAs($user->fresh())
            ->test(MyComputer::class)
            ->assertSet('pc.socket', 'LGA1700');
    }

    // --- on the pages -----------------------------------------------------

    public function test_a_model_page_says_what_does_not_fit(): void
    {
        $gpu = $this->part('gpu', ['tdp_w' => 285, 'length_mm' => 320]);

        $this->withSession(['my_pc' => ['psu_w' => 450, 'case_max_gpu_mm' => 300]])
            ->get(route('part', $gpu))
            ->assertOk()
            ->assertSee('Пасва ли на твоя компютър?')
            ->assertSee('Захранването ти (450 W) е малко')
            ->assertSee('Не влиза в кутията ти')
            ->assertSee('Промени компютъра');
    }

    public function test_a_model_page_invites_a_visitor_who_has_not_said_anything(): void
    {
        $gpu = $this->part('gpu', ['tdp_w' => 285, 'length_mm' => 320]);

        $this->get(route('part', $gpu))
            ->assertOk()
            ->assertSee('Пасва ли на твоя компютър?')
            ->assertSee('Кажи ни какво имаш');
    }

    public function test_a_monitor_page_has_no_panel(): void
    {
        $monitor = $this->part('monitor', ['refresh_hz' => 144]);

        $this->get(route('part', $monitor))
            ->assertOk()
            ->assertDontSee('Пасва ли на твоя компютър?');
    }

    public function test_a_listing_page_uses_its_models_specs(): void
    {
        $gpu = $this->part('gpu', ['tdp_w' => 285, 'length_mm' => 320]);

        $listing = Listing::factory()->create([
            'user_id'  => User::factory()->create()->id,
            'status'   => ListingStatus::Active,
            'category' => 'gpu',
            'part_id'  => $gpu->id,
        ]);

        $this->withSession(['my_pc' => ['case_max_gpu_mm' => 330]])
            ->get(route('listing', $listing))
            ->assertOk()
            ->assertSee('Влиза в кутията ти');
    }

    public function test_a_sold_listing_does_not_ask_whether_it_fits(): void
    {
        $gpu = $this->part('gpu', ['tdp_w' => 285]);

        $listing = Listing::factory()->create([
            'user_id'  => User::factory()->create()->id,
            'status'   => ListingStatus::Sold,
            'category' => 'gpu',
            'part_id'  => $gpu->id,
        ]);

        $this->get(route('listing', $listing))
            ->assertOk()
            ->assertDontSee('Пасва ли на твоя компютър?');
    }
}
