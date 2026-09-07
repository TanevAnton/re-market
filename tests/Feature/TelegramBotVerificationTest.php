<?php

namespace Tests\Feature;

use App\Livewire\Auth\VerifyPhone;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Verification\TelegramBot;
use App\Services\Verification\TelegramBotVerifier;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Verification by Telegram contact share.
 *
 * No code is sent, so these tests are not about codes. They are about the one
 * thing the flow rests on: that the number arriving over Telegram belongs to
 * the person who sent it.
 */
class TelegramBotVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TelegramBotVerifier $verifier;

    private const CHAT = 555001;
    private const TG_USER = 999001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        config([
            'remarket.verify.telegram_bot_token'    => 'bot-token',
            'remarket.verify.telegram_bot_username' => 'remarket_bot',
        ]);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);

        $this->user     = User::factory()->unverified()->create(['phone_hash' => null]);
        $this->verifier = new TelegramBotVerifier(new TelegramBot());
    }

    private function start(TelegramLink $link): void
    {
        $this->verifier->handle(['update_id' => 1, 'message' => [
            'chat' => ['id' => self::CHAT],
            'from' => ['id' => self::TG_USER],
            'text' => '/start '.$link->nonce,
        ]]);
    }

    private function shareContact(int $contactUserId, string $phone = '+359888123456'): void
    {
        $this->verifier->handle(['update_id' => 2, 'message' => [
            'chat'    => ['id' => self::CHAT],
            'from'    => ['id' => self::TG_USER],
            'contact' => ['phone_number' => $phone, 'user_id' => $contactUserId],
        ]]);
    }

    // --- the happy path --------------------------------------------------

    public function test_start_then_share_verifies_the_number(): void
    {
        $link = TelegramLink::issueFor($this->user);

        $this->start($link);
        $this->assertSame(self::CHAT, (int) $link->fresh()->telegram_chat_id);

        $this->shareContact(self::TG_USER);

        $user = $this->user->fresh();
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame(User::hashPhone('+359888123456'), $user->phone_hash);
        $this->assertSame('3456', $user->phone_last4);

        $this->assertNotNull($link->fresh()->consumed_at);
    }

    /**
     * THE test. Telegram lets anyone forward anyone else's contact card, and
     * such a card looks almost identical to a real one. Only contact.user_id
     * matching the sender distinguishes "my number", which Telegram vouches
     * for, from "a number in my address book". Without that check this whole
     * flow verifies nothing.
     */
    public function test_a_forwarded_contact_belonging_to_someone_else_verifies_nothing(): void
    {
        $link = TelegramLink::issueFor($this->user);
        $this->start($link);

        // Same chat, same sender - but the card is somebody else's.
        $this->shareContact(contactUserId: self::TG_USER + 1);

        $this->assertNull($this->user->fresh()->phone_verified_at);
        $this->assertNull($link->fresh()->consumed_at);
    }

    public function test_a_contact_card_with_no_user_id_is_refused(): void
    {
        $link = TelegramLink::issueFor($this->user);
        $this->start($link);

        // A manually typed contact has no user_id at all.
        $this->verifier->handle(['update_id' => 2, 'message' => [
            'chat'    => ['id' => self::CHAT],
            'from'    => ['id' => self::TG_USER],
            'contact' => ['phone_number' => '+359888123456'],
        ]]);

        $this->assertNull($this->user->fresh()->phone_verified_at);
    }

    // --- the nonce -------------------------------------------------------

    public function test_a_contact_without_a_preceding_start_verifies_nothing(): void
    {
        TelegramLink::issueFor($this->user);

        $this->shareContact(self::TG_USER);

        $this->assertNull($this->user->fresh()->phone_verified_at);
    }

    public function test_an_expired_link_is_refused(): void
    {
        $link = TelegramLink::issueFor($this->user);
        $link->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->start($link);

        // Never bound to the chat, so the contact has nothing to attach to.
        $this->assertNull($link->fresh()->telegram_chat_id);

        $this->shareContact(self::TG_USER);
        $this->assertNull($this->user->fresh()->phone_verified_at);
    }

    public function test_a_link_is_single_use(): void
    {
        $link = TelegramLink::issueFor($this->user);
        $this->start($link);
        $this->shareContact(self::TG_USER);

        $this->assertNotNull($this->user->fresh()->phone_verified_at);

        // A replayed update - Telegram redelivers anything unacknowledged -
        // must not re-verify, and must not be able to re-bind a new number.
        $this->shareContact(self::TG_USER, '+359877000111');

        $this->assertSame(User::hashPhone('+359888123456'), $this->user->fresh()->phone_hash);
    }

    public function test_issuing_a_new_link_supersedes_the_old_one(): void
    {
        $first = TelegramLink::issueFor($this->user);
        TelegramLink::issueFor($this->user);

        // Two live nonces would be two independent ways into one account.
        $this->assertDatabaseMissing('telegram_links', ['nonce' => $first->nonce]);
        $this->assertSame(1, TelegramLink::usable()->where('user_id', $this->user->id)->count());
    }

    // --- the same rules the code flow has --------------------------------

    public function test_a_number_already_on_another_account_is_refused(): void
    {
        User::factory()->create(['phone_hash' => User::hashPhone('+359888123456')]);

        $link = TelegramLink::issueFor($this->user);
        $this->start($link);
        $this->shareContact(self::TG_USER);

        // One phone, one account - the rule that makes a ban cost something.
        $this->assertNull($this->user->fresh()->phone_verified_at);
    }

    public function test_a_non_bulgarian_number_is_refused(): void
    {
        $link = TelegramLink::issueFor($this->user);
        $this->start($link);
        $this->shareContact(self::TG_USER, '+4915112345678');

        $this->assertNull($this->user->fresh()->phone_verified_at);
    }

    // --- the screen ------------------------------------------------------

    public function test_the_page_offers_telegram_and_hands_out_a_deep_link(): void
    {
        $this->actingAs($this->user);

        Livewire::test(VerifyPhone::class)
            ->assertSee('Потвърди с Telegram')
            ->call('startTelegram')
            ->assertSet('telegramUrl', fn ($url) => str_contains($url, 'https://t.me/remarket_bot?start='));
    }

    public function test_the_page_hides_telegram_when_no_bot_is_configured(): void
    {
        config(['remarket.verify.telegram_bot_token' => null]);

        $this->actingAs($this->user);

        Livewire::test(VerifyPhone::class)->assertDontSee('Потвърди с Telegram');
    }

    public function test_the_page_moves_on_once_the_listener_has_verified(): void
    {
        $this->actingAs($this->user);

        $page = Livewire::test(VerifyPhone::class)->call('startTelegram');

        // Nothing yet - the user is still over in Telegram.
        $page->call('checkTelegram')->assertNoRedirect();

        $link = TelegramLink::usable()->where('user_id', $this->user->id)->firstOrFail();
        $this->start($link);
        $this->shareContact(self::TG_USER);

        $page->call('checkTelegram')->assertRedirect(route('browse'));
    }

    public function test_the_bot_asks_for_the_contact_when_start_arrives(): void
    {
        $link = TelegramLink::issueFor($this->user);
        $this->start($link);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sendMessage')
            && str_contains($r['reply_markup'] ?? '', 'request_contact'));
    }
}
