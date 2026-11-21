<?php

namespace Tests\Feature;

use App\Livewire\SavedSearches\ConnectTelegram;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Verification\TelegramBot;
use App\Services\Verification\TelegramBotVerifier;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Пиши ми тук" - connecting Telegram for alerts without a phone number.
 *
 * What these guard is the one thing that makes this safe to offer: pressing
 * Start may connect a chat, and that must never become a way to read another
 * account's messages or to pass for a verified phone.
 */
class TelegramAlertsConnectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TelegramBotVerifier $verifier;

    private const CHAT = 777001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        config([
            'remarket.verify.telegram_bot_token'    => 'bot-token',
            'remarket.verify.telegram_bot_username' => 'remarket_bot',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/getMe')) {
                return Http::response(['ok' => true, 'result' => ['id' => 42, 'username' => 'remarket23bot']]);
            }

            return Http::response(['ok' => true, 'result' => []]);
        });

        Cache::flush();

        $this->user     = User::factory()->unverified()->create([
            'phone_hash' => null, 'telegram_chat_id' => null, 'notify_telegram' => false,
        ]);
        $this->verifier = new TelegramBotVerifier(new TelegramBot());
    }

    private function pressStart(TelegramLink $link, int $chat = self::CHAT): void
    {
        $this->verifier->handle(['update_id' => 1, 'message' => [
            'chat' => ['id' => $chat],
            'from' => ['id' => 999001],
            'text' => '/start '.$link->nonce,
        ]]);
    }

    // --- the bot side ------------------------------------------------------

    public function test_start_on_an_alerts_link_connects_the_chat_and_turns_notifications_on(): void
    {
        $link = TelegramLink::issueFor($this->user, 15, TelegramLink::PURPOSE_ALERTS);

        $this->pressStart($link);

        $user = $this->user->fresh();
        $this->assertSame(self::CHAT, (int) $user->telegram_chat_id);
        $this->assertTrue((bool) $user->notify_telegram);
        $this->assertNotNull($link->fresh()->consumed_at);
    }

    /** The chat proves nothing about a phone, so the phone stays unverified. */
    public function test_connecting_for_alerts_does_not_verify_a_phone(): void
    {
        $link = TelegramLink::issueFor($this->user, 15, TelegramLink::PURPOSE_ALERTS);

        $this->pressStart($link);

        $user = $this->user->fresh();
        $this->assertNull($user->phone_verified_at);
        $this->assertNull($user->phone_hash);
    }

    public function test_a_link_is_single_use(): void
    {
        $link = TelegramLink::issueFor($this->user, 15, TelegramLink::PURPOSE_ALERTS);

        $this->pressStart($link);

        $other = User::factory()->create(['telegram_chat_id' => null]);
        // The same nonce pressed again from another chat must connect nothing.
        $this->pressStart($link, 888002);

        $this->assertSame(self::CHAT, (int) $this->user->fresh()->telegram_chat_id);
        $this->assertNull($other->fresh()->telegram_chat_id);
    }

    /**
     * One chat, one account. Otherwise a second account could be pointed at a
     * chat that already receives somebody else's offers and deal details.
     */
    public function test_a_chat_already_used_by_another_account_is_refused(): void
    {
        User::factory()->create(['telegram_chat_id' => self::CHAT]);

        $link = TelegramLink::issueFor($this->user, 15, TelegramLink::PURPOSE_ALERTS);

        $this->pressStart($link);

        $this->assertNull($this->user->fresh()->telegram_chat_id);
        $this->assertNull($link->fresh()->consumed_at);
    }

    /** The phone flow is untouched: Start there still only asks for a contact. */
    public function test_start_on_a_verify_link_still_connects_nothing_yet(): void
    {
        $link = TelegramLink::issueFor($this->user);

        $this->assertSame(TelegramLink::PURPOSE_VERIFY, $link->purpose);

        $this->pressStart($link);

        $this->assertNull($this->user->fresh()->telegram_chat_id);
        $this->assertNull($link->fresh()->consumed_at);
        $this->assertSame(self::CHAT, (int) $link->fresh()->telegram_chat_id);
    }

    public function test_issuing_one_purpose_does_not_cancel_the_other(): void
    {
        $verify = TelegramLink::issueFor($this->user);
        TelegramLink::issueFor($this->user, 15, TelegramLink::PURPOSE_ALERTS);

        $this->assertTrue($verify->fresh()->isUsable());
    }

    public function test_asking_twice_for_alerts_leaves_one_live_link(): void
    {
        TelegramLink::issueFor($this->user, 15, TelegramLink::PURPOSE_ALERTS);
        TelegramLink::issueFor($this->user, 15, TelegramLink::PURPOSE_ALERTS);

        $this->assertSame(1, TelegramLink::where('user_id', $this->user->id)->usable()->count());
    }

    // --- the button --------------------------------------------------------

    public function test_the_button_hands_out_an_alerts_link(): void
    {
        $this->actingAs($this->user);

        $component = Livewire::test(ConnectTelegram::class)->call('start');

        $link = TelegramLink::where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame(TelegramLink::PURPOSE_ALERTS, $link->purpose);
        $component->assertSet('url', fn ($url) => str_contains((string) $url, $link->nonce));
    }

    public function test_it_renders_nothing_when_the_bot_is_not_configured(): void
    {
        config(['remarket.verify.telegram_bot_token' => null]);

        $this->actingAs($this->user);

        Livewire::test(ConnectTelegram::class)
            ->assertDontSee('Свържи Telegram')
            ->call('start')
            ->assertSet('url', null);

        $this->assertSame(0, TelegramLink::count());
    }

    public function test_it_offers_the_button_until_connected_then_says_so(): void
    {
        $this->actingAs($this->user);

        Livewire::test(ConnectTelegram::class)->assertSee('Свържи Telegram');

        $this->user->forceFill(['telegram_chat_id' => self::CHAT, 'notify_telegram' => true])->save();

        Livewire::test(ConnectTelegram::class)
            ->assertDontSee('Свържи Telegram')
            ->assertSee('Telegram е свързан — известията идват там.');
    }

    public function test_the_saved_searches_page_carries_it(): void
    {
        // A fully verified account: the page sits behind the same gates as the
        // rest of the member area, and this test is about the card, not them.
        $member = User::factory()->create(['telegram_chat_id' => null, 'notify_telegram' => false]);

        $this->actingAs($member)->get(route('searches'))->assertOk()->assertSee('Свържи Telegram');
    }
}
