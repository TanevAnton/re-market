<?php

namespace Tests\Feature;

use App\Services\Verification\Channels\TelegramChannel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Telegram Gateway channel, faked at the HTTP boundary.
 *
 * These do not prove the live API agrees with us - only a real token does that.
 * What they do prove is the contract this class promises the cascade: false
 * means "try the next channel", never an exception, and never a swallowed
 * reason.
 */
class TelegramChannelTest extends TestCase
{
    private TelegramChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['remarket.verify.telegram_token' => 'test-token']);
        config(['remarket.verify.code_ttl' => 10]);

        $this->channel = new TelegramChannel();
    }

    /**
     * Availability is read from config on every call rather than captured when
     * the channel is built. That is what lets adding the token to .env and
     * re-caching turn the channel on without a deploy - and it is why both of
     * these assertions use the same instance.
     */
    public function test_availability_follows_the_configured_token(): void
    {
        config(['remarket.verify.telegram_token' => null]);
        $this->assertFalse($this->channel->isAvailable());

        config(['remarket.verify.telegram_token' => 'test-token']);
        $this->assertTrue($this->channel->isAvailable());
    }

    public function test_a_successful_send_checks_ability_first_and_carries_the_request_id(): void
    {
        Http::fake([
            '*checkSendAbility' => Http::response([
                'ok' => true, 'result' => ['request_id' => 'req-123'],
            ]),
            '*sendVerificationMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'request_id' => 'req-123', 'request_cost' => 0.0,
                    'remaining_balance' => 1.23,
                    'delivery_status' => ['status' => 'sent'],
                ],
            ]),
        ]);

        $this->assertTrue($this->channel->send('+359888123456', '123456'));

        // Carrying the id forward is what keeps the pair to one billable
        // request instead of two.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendVerificationMessage')
            && $r['request_id'] === 'req-123'
            && $r['code'] === '123456'
            && $r['ttl'] === 600);
    }

    /**
     * The common case, not an error case: most Bulgarians on OLX do not use
     * Telegram. The cascade needs a plain false so it can try Viber.
     */
    public function test_a_number_without_telegram_returns_false_without_sending(): void
    {
        Http::fake([
            '*checkSendAbility' => Http::response(['ok' => false, 'error' => 'PHONE_NUMBER_INVALID']),
        ]);

        $this->assertFalse($this->channel->send('+359888123456', '123456'));

        // Crucially it must not have spent a send on a number it was told
        // could not receive one.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'sendVerificationMessage'));
    }

    public function test_a_failed_send_returns_false_rather_than_throwing(): void
    {
        Http::fake([
            '*checkSendAbility' => Http::response(['ok' => true, 'result' => ['request_id' => 'r']]),
            '*sendVerificationMessage' => Http::response(['ok' => false, 'error' => 'BALANCE_NOT_ENOUGH']),
        ]);

        $this->assertFalse($this->channel->send('+359888123456', '123456'));
    }

    public function test_a_network_failure_returns_false_rather_than_throwing(): void
    {
        // An exception here would take the whole registration down instead of
        // falling through to the next channel.
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->assertFalse($this->channel->send('+359888123456', '123456'));
    }

    public function test_the_ttl_is_clamped_to_what_the_api_accepts(): void
    {
        // The API rejects anything outside 30-3600 seconds, and code_ttl is a
        // knob someone will eventually set to two hours.
        config(['remarket.verify.code_ttl' => 120]);

        Http::fake([
            '*checkSendAbility' => Http::response(['ok' => true, 'result' => ['request_id' => 'r']]),
            '*sendVerificationMessage' => Http::response(['ok' => true, 'result' => []]),
        ]);

        (new TelegramChannel())->send('+359888123456', '123456');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendVerificationMessage')
            && $r['ttl'] === 3600);
    }
}
