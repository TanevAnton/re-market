<?php

namespace Tests\Feature;

use App\Livewire\MyPcHome;
use App\Models\User;
use App\Support\MyPc;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Моят компютър" on the home page - first under the search - and the shop's
 * banner moved up right beneath it.
 */
class MyPcHomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
    }

    public function test_the_home_page_offers_it_to_a_visitor_who_has_not_said(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-my-pc-home', false)
            ->assertSee('Пасва ли на твоя компютър?')
            ->assertSee(route('my-pc'), false);
    }

    public function test_three_answers_are_kept_and_the_panel_becomes_a_summary(): void
    {
        $socket = MyPc::fields()['socket']['options'][0];

        Livewire::test(MyPcHome::class)
            ->set('quick.socket', $socket)
            ->set('quick.ram_type', 'DDR4')
            ->set('quick.psu_w', 650)
            ->call('save')
            ->assertSee('Проверяваме по твоя компютър')
            ->assertSee('3 от 8 данни')
            ->assertDontSee('Запази и проверявай');

        $this->assertSame(
            ['socket' => $socket, 'ram_type' => 'DDR4', 'psu_w' => 650],
            MyPc::current(),
        );
    }

    public function test_answering_here_does_not_wipe_what_the_full_form_stored(): void
    {
        MyPc::save(['case_max_gpu_mm' => 320, 'gpu_tdp_w' => 170]);

        Livewire::test(MyPcHome::class)
            ->set('quick.psu_w', 550)
            ->call('save');

        $kept = MyPc::current();
        ksort($kept);

        $this->assertSame(['case_max_gpu_mm' => 320, 'gpu_tdp_w' => 170, 'psu_w' => 550], $kept);
    }

    public function test_a_value_nobody_offered_is_not_stored(): void
    {
        Livewire::test(MyPcHome::class)
            ->set('quick.ram_type', 'DDR99')
            ->set('quick.psu_w', 9000000)
            ->call('save');

        $this->assertSame([], MyPc::current());
    }

    public function test_a_blank_form_saves_nothing(): void
    {
        Livewire::test(MyPcHome::class)->call('save');

        $this->assertSame([], MyPc::current());
    }

    public function test_it_is_stored_on_the_account_when_there_is_one(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(MyPcHome::class)
            ->set('quick.ram_type', 'DDR5')
            ->call('save');

        $this->assertSame(['ram_type' => 'DDR5'], $user->fresh()->my_pc);
    }

    public function test_it_can_be_cleared_from_the_home_page(): void
    {
        MyPc::save(['ram_type' => 'DDR4']);

        Livewire::test(MyPcHome::class)
            ->assertSee('1 от 8 данни')
            ->call('forget')
            ->assertSee('Пасва ли на твоя компютър?');

        $this->assertSame([], MyPc::current());
    }

    public function test_the_summary_says_what_is_still_missing(): void
    {
        MyPc::save(['ram_type' => 'DDR4']);

        Livewire::test(MyPcHome::class)->assertSee('За по-точен отговор липсва');
    }

    public function test_the_fit_check_is_first_then_the_shop_banner_then_the_categories(): void
    {
        config(['remarket.retech.buyback_url' => 'https://example.test/buyback']);

        $html = $this->get(route('home'))->assertOk()->getContent();

        $fit        = strpos($html, 'data-my-pc-home');
        $banner     = strpos($html, 'data-retech-offer="home"');
        $categories = strpos($html, 'font-medium text-accent">//</span>Категории');
        $sell       = strpos($html, 'Имаш видеокарта, която събира прах?');

        $this->assertNotFalse($fit);
        $this->assertNotFalse($banner);
        $this->assertLessThan($banner, $fit);
        $this->assertLessThan($categories, $banner);
        $this->assertLessThan($sell, $categories);
    }
}
