<?php

namespace Tests\Feature;

use App\Enums\Courier;
use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Livewire\Deals\MyDeals;
use App\Models\City;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\User;
use App\Notifications\DeliveryDetailsSet;
use App\Notifications\ParcelSent;
use App\Services\Deals\DealException;
use App\Services\Deals\DeliveryService;
use App\Support\WaybillDraft;
use Database\Seeders\CitySeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The five minutes after „съгласен съм" that the site used to leave people alone in.
 *
 * FOUR THINGS ARE BEING PROTECTED and only the first is the feature.
 *
 *   1. The seller gets every field a courier form asks for, in one block, in
 *      order, instead of scrolling a chat for a name and an office number.
 *
 *   2. THE TWO HALVES BELONG TO TWO PEOPLE. The buyer says where it goes — it is
 *      their address and their phone, and a seller who could edit it could
 *      redirect a parcel somebody is about to pay for. The seller says it has been
 *      sent — they hold the receipt, and a buyer who could type a tracking number
 *      could make a deal look shipped to push the other side into confirming.
 *      Mutual confirmation is what every rating on this site hangs off, so
 *      manufacturing the appearance of progress is an attack on the trust signal.
 *
 *   3. RIGO STILL NEVER TOUCHES THE MONEY. Nothing here calls a courier API,
 *      because whoever creates the waybill is the sender of record and the sender
 *      of record is who the cash-on-delivery is paid to. There is a test below
 *      that greps for an HTTP client in the handover code.
 *
 *   4. THE ADDRESS DOES NOT LIVE FOREVER. A marketplace that keeps a name, a
 *      phone and a home address for every parcel it ever carried has built a
 *      database whose only remaining purpose is to be stolen.
 */
class CourierHandoverTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private User $seller;
    private DeliveryService $delivery;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(CitySeeder::class);

        $this->buyer   = $this->user();
        $this->seller  = $this->user();
        $this->delivery = app(DeliveryService::class);
    }

    private function user(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ])->refresh();
    }

    /**
     * The factory RANDOMISES `delivery_options` and `accepts_inspect_test`, so
     * every test that depends on either one pins it. „If a factory can seed the
     * column, never assert the value" — and never rely on it as a precondition.
     */
    private function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create([
            'user_id'              => $this->seller->id,
            'city_id'              => City::first()->id,
            'category'             => 'gpu',
            'status'               => ListingStatus::Active,
            'delivery_options'     => ['econt', 'speedy'],
            'accepts_inspect_test' => true,
            ...$attributes,
        ]);
    }

    private function deal(array $listingAttributes = [], array $dealAttributes = []): Deal
    {
        $listing = $this->listing($listingAttributes);

        $deal = Deal::create([
            'listing_id'         => $listing->id,
            'buyer_id'           => $this->buyer->id,
            'seller_id'          => $this->seller->id,
            'agreed_price_cents' => 45000,
            'expires_at'         => now()->addDays(3),
            ...$dealAttributes,
        ]);

        // create() does not read back the column default, and `status` has one.
        return $deal->refresh();
    }

    /** @return array<string, mixed> */
    private function details(array $overrides = []): array
    {
        return [
            'courier'      => 'econt',
            'kind'         => 'office',
            'office'       => 'Офис Център',
            'city_id'      => City::first()->id,
            'name'         => 'Иван Петров',
            'phone'        => '0888123456',
            'inspect_test' => true,
            ...$overrides,
        ];
    }

    // --- who may do what --------------------------------------------------

    /**
     * THE TEST THIS FILE EXISTS FOR, first half. The address is the buyer's.
     */
    public function test_the_seller_cannot_say_where_the_parcel_goes(): void
    {
        $deal = $this->deal();

        $this->expectException(DealException::class);

        $this->delivery->setDelivery($deal, $this->seller, $this->details());
    }

    /**
     * Second half, and the one with teeth. A buyer who could type a tracking
     * number could make a deal look shipped and lean on the seller to confirm.
     */
    public function test_the_buyer_cannot_say_the_parcel_was_sent(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details());

        $this->expectException(DealException::class);

        $this->delivery->setTracking($deal, $this->buyer, '1234567890');
    }

    /** A stranger is refused with the same sentence as the wrong party. */
    public function test_a_stranger_is_refused(): void
    {
        $deal = $this->deal();

        $this->expectException(DealException::class);

        $this->delivery->setDelivery($deal, $this->user(), $this->details());
    }

    /**
     * THE SECURITY TEST. The columns are not fillable, so nothing that ever
     * mass-assigns from request data can reach them.
     */
    public function test_the_handover_columns_are_not_mass_assignable(): void
    {
        $deal = $this->deal();

        try {
            $deal->update([
                'tracking_number' => 'FORGED123',
                'courier'         => 'econt',
                'delivery_name'   => 'Somebody Else',
            ]);
        } catch (MassAssignmentException) {
            // Outside production AppServiceProvider makes a discarded attribute
            // shout instead of vanishing; in production it is dropped quietly.
            // Either way it never reaches the column, which is the point.
        }

        $fresh = $deal->fresh();

        $this->assertNull($fresh->tracking_number);
        $this->assertNull($fresh->courier);
        $this->assertNull($fresh->delivery_name);
    }

    public function test_nothing_can_be_arranged_on_a_closed_deal(): void
    {
        $deal = $this->deal(dealAttributes: ['status' => DealStatus::Completed->value]);

        $this->expectException(DealException::class);
        $this->expectExceptionMessage('приключена');

        $this->delivery->setDelivery($deal->refresh(), $this->buyer, $this->details());
    }

    // --- what the seller actually offered ---------------------------------

    /**
     * The form only draws the couriers the seller offered, but the form is a
     * client-supplied list and this is the server. Picking one the seller does not
     * use produces a waybill they cannot create.
     */
    public function test_a_courier_the_seller_does_not_offer_is_refused(): void
    {
        $deal = $this->deal(['delivery_options' => ['econt']]);

        $this->expectException(DealException::class);
        $this->expectExceptionMessage('Спиди');

        $this->delivery->setDelivery($deal, $this->buyer, $this->details(['courier' => 'speedy']));
    }

    /**
     * „преглед и тест" means the seller's parcel gets opened before they are paid,
     * so it is theirs to allow. A buyer ticking it on a listing that said no would
     * produce a waybill the seller refuses to create — worse than no box at all.
     */
    public function test_inspect_and_test_cannot_be_forced_onto_a_seller_who_refused_it(): void
    {
        $deal = $this->deal(['accepts_inspect_test' => false]);

        $this->delivery->setDelivery($deal, $this->buyer, $this->details(['inspect_test' => true]));

        $this->assertFalse($deal->fresh()->inspect_test_selected);
    }

    /** Two people meeting in a car park: no address, no waybill, no tracking. */
    public function test_pickup_needs_no_address_and_has_nothing_to_track(): void
    {
        $deal = $this->deal(['delivery_options' => ['pickup']]);

        $this->delivery->setDelivery($deal, $this->buyer, ['courier' => 'pickup']);

        $deal = $deal->fresh();
        $this->assertSame('pickup', $deal->courier);
        $this->assertTrue($deal->deliveryArranged());
        $this->assertNull($deal->delivery_kind);
        $this->assertFalse(WaybillDraft::for($deal)->isReady());

        $this->expectException(DealException::class);
        $this->expectExceptionMessage('лично предаване');

        $this->delivery->setTracking($deal, $this->seller, '1234567890');
    }

    // --- the waybill ------------------------------------------------------

    public function test_the_draft_is_empty_until_the_buyer_has_filled_the_form(): void
    {
        $draft = WaybillDraft::for($this->deal());

        $this->assertFalse($draft->isReady());
        $this->assertSame([], $draft->fields());
        $this->assertSame('', $draft->asText());
    }

    public function test_the_draft_carries_the_cod_amount_and_the_declared_value(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details());

        $text = WaybillDraft::for($deal->fresh())->asText();

        // Both, and both equal to the agreed price: the amount handed over at the
        // counter and the figure the courier's insurance pays out on.
        $this->assertStringContainsString('Наложен платеж: 450,00 €', $text);
        $this->assertStringContainsString('Обявена стойност: 450,00 €', $text);
        $this->assertStringContainsString('Иван Петров', $text);
        $this->assertStringContainsString('Офис Център', $text);
    }

    /**
     * EACH COURIER'S OWN WORDS. A buyer told to ask for „преглед и тест" at a
     * Speedy counter gets a blank look, and this is the one instruction on the
     * site that has to survive contact with a courier employee.
     */
    public function test_each_courier_gets_its_own_name_for_inspect_and_test(): void
    {
        $econt = $this->deal();
        $this->delivery->setDelivery($econt, $this->buyer, $this->details(['courier' => 'econt']));

        $this->assertStringContainsString(
            'преглед и тест',
            WaybillDraft::for($econt->fresh())->asText(),
        );

        $speedy = $this->deal();
        $this->delivery->setDelivery($speedy, $this->buyer, $this->details(['courier' => 'speedy']));

        $text = WaybillDraft::for($speedy->fresh())->asText();

        $this->assertStringContainsString('отвори и тествай', $text);
        $this->assertStringNotContainsString('преглед и тест', $text);
    }

    /** Not ticked, not printed — a waybill must not ask for a service nobody chose. */
    public function test_an_unchosen_service_is_absent_from_the_draft(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details(['inspect_test' => false]));

        $this->assertStringNotContainsString(
            'Допълнителна услуга',
            WaybillDraft::for($deal->fresh())->asText(),
        );
    }

    /**
     * THE CEILING, ASSERTED. Nothing in the handover path talks to a courier,
     * because whoever creates the waybill is the sender of record and the sender
     * of record is who the cash-on-delivery is remitted to. „The platform never
     * touches the money" is the whole regulatory position, and a convenience
     * feature is exactly the shape of thing that would quietly undo it.
     */
    public function test_nothing_in_the_handover_calls_a_courier(): void
    {
        $files = [
            app_path('Support/WaybillDraft.php'),
            app_path('Services/Deals/DeliveryService.php'),
            app_path('Enums/Courier.php'),
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach (['Http::', 'curl_', 'file_get_contents(', 'GuzzleHttp'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    basename($file).' reaches out over the network. Creating a waybill '
                        .'makes RIGO the sender of record, which makes it the recipient '
                        .'of the cash-on-delivery. Read the class docblock.',
                );
            }
        }
    }

    // --- tracking ---------------------------------------------------------

    public function test_the_seller_records_the_number_and_the_buyer_can_follow_it(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details());

        $this->delivery->setTracking($deal, $this->seller, '  1050123456789  ');

        $deal = $deal->fresh();

        // Livewire skips TrimStrings, so the service trims.
        $this->assertSame('1050123456789', $deal->tracking_number);
        $this->assertNotNull($deal->tracking_set_at);
        $this->assertNotNull($deal->trackingUrl());
    }

    /**
     * The shipped default links to the courier's tracking PAGE and does not carry
     * the number, because neither courier's deep-link parameter has been verified
     * and a button that lands on an error page teaches people to stop trusting the
     * screen. Confirming the parameter is an env change, not a code change.
     */
    public function test_tracking_deep_links_only_when_the_configured_url_says_how(): void
    {
        $this->assertFalse(Courier::Econt->deepLinksTracking());
        $this->assertSame(
            'https://www.econt.com/services/track-shipment',
            Courier::Econt->trackingUrl('1050123456789'),
        );

        config(['remarket.couriers.econt.tracking_url' => 'https://example.test/t?n={number}']);

        $this->assertTrue(Courier::Econt->deepLinksTracking());
        $this->assertSame(
            'https://example.test/t?n=1050123456789',
            Courier::Econt->trackingUrl('1050123456789'),
        );
    }

    // --- who gets told ----------------------------------------------------

    public function test_both_sides_hear_about_their_half(): void
    {
        Notification::fake();

        $deal = $this->deal();

        $this->delivery->setDelivery($deal, $this->buyer, $this->details());
        Notification::assertSentTo($this->seller, DeliveryDetailsSet::class);

        $this->delivery->setTracking($deal, $this->seller, '1050123456789');
        Notification::assertSentTo($this->buyer, ParcelSent::class);
    }

    /** No address or phone number in a Telegram message or an email body. */
    public function test_the_notification_does_not_carry_the_address(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details());

        $body = implode(' ', (new DeliveryDetailsSet($deal->fresh()))->lines($this->seller));

        $this->assertStringNotContainsString('0888123456', $body);
        $this->assertStringNotContainsString('Офис Център', $body);
    }

    /** A seller fixing a typo should not send three „it has shipped" messages. */
    public function test_correcting_the_same_number_does_not_notify_again(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details());
        $this->delivery->setTracking($deal, $this->seller, '1050123456789');

        Notification::fake();

        $this->delivery->setTracking($deal->fresh(), $this->seller, '1050123456789');

        Notification::assertNothingSent();
    }

    // --- retention --------------------------------------------------------

    /**
     * THE ONE THAT MATTERS IN A YEAR. What survives a purge is the transaction —
     * courier, tracking number, inspect-and-test. What goes is the person.
     */
    public function test_delivery_details_are_erased_after_the_retention_window(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details());
        $this->delivery->setTracking($deal, $this->seller, '1050123456789');

        $deal->forceFill(['status' => DealStatus::Completed->value])->save();
        Deal::whereKey($deal->id)->update(['updated_at' => now()->subDays(40)]);

        $this->artisan('remarket:purge-delivery-details')->assertSuccessful();

        $deal = $deal->fresh();

        $this->assertNull($deal->delivery_name);
        $this->assertNull($deal->delivery_phone);
        $this->assertNull($deal->delivery_address);
        $this->assertNull($deal->delivery_office);
        $this->assertNull($deal->delivery_city_id);

        // The facts about the transaction stay — the tracking number is the only
        // evidence either side has that a parcel ever existed.
        $this->assertSame('1050123456789', $deal->tracking_number);
        $this->assertSame('econt', $deal->courier);
        $this->assertTrue($deal->inspect_test_selected);
    }

    /**
     * Wiping the address of a parcel somebody may still be about to send turns a
     * stalled deal into an impossible one. Open deals are never touched, however
     * old — that is `remarket:lapse-deals`'s job.
     */
    public function test_an_open_deal_is_never_purged(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details());

        Deal::whereKey($deal->id)->update(['updated_at' => now()->subYear()]);

        $this->artisan('remarket:purge-delivery-details')->assertSuccessful();

        $this->assertSame('Иван Петров', $deal->fresh()->delivery_name);
        $this->assertNull($deal->fresh()->delivery_purged_at);
    }

    public function test_a_recently_closed_deal_keeps_its_details(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details());
        $deal->forceFill(['status' => DealStatus::Completed->value])->save();

        $this->artisan('remarket:purge-delivery-details')->assertSuccessful();

        $this->assertNotNull($deal->fresh()->delivery_name);
    }

    /**
     * A purged deal SAYS it was purged. Rendering blanks would read as a buyer who
     * never filled the form in, which is a different and wrong story.
     */
    public function test_a_purged_deal_says_so_rather_than_looking_unfilled(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details());
        $this->delivery->purge($deal);

        $deal = $deal->fresh();

        $this->assertTrue($deal->deliveryArranged());
        $this->assertTrue($deal->deliveryPurged());
        $this->assertFalse(WaybillDraft::for($deal)->isReady());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details());
        $deal->forceFill(['status' => DealStatus::Completed->value])->save();
        Deal::whereKey($deal->id)->update(['updated_at' => now()->subDays(40)]);

        $this->artisan('remarket:purge-delivery-details --dry-run')->assertSuccessful();

        $this->assertNotNull($deal->fresh()->delivery_name);
    }

    // --- the screen -------------------------------------------------------

    public function test_the_buyer_fills_the_form_on_their_deals_page(): void
    {
        $deal = $this->deal();

        Livewire::actingAs($this->buyer)
            ->test(MyDeals::class)
            ->call('openDelivery', $deal->id)
            ->assertSet('editingDelivery', $deal->id)
            ->set('dCourier', 'econt')
            ->set('dKind', 'office')
            ->set('dCityId', City::first()->id)
            ->set('dOffice', 'Офис Център')
            ->set('dName', 'Иван Петров')
            ->set('dPhone', '0888123456')
            ->call('saveDelivery')
            ->assertHasNoErrors()
            ->assertSet('editingDelivery', null);

        $this->assertTrue($deal->fresh()->deliveryArranged());
    }

    /**
     * assertNotFound(), NOT expectException — Livewire turns an `abort()` inside
     * an action into a 404 RESPONSE before PHPUnit sees an exception. That has
     * caught me twice already in this codebase; `run()` here catches DealException
     * only, so the abort propagates as it should.
     */
    public function test_a_seller_cannot_open_the_buyers_delivery_form(): void
    {
        $deal = $this->deal();

        Livewire::actingAs($this->seller)
            ->test(MyDeals::class)
            ->call('openDelivery', $deal->id)
            ->assertNotFound()
            ->assertSet('editingDelivery', null);
    }

    public function test_a_buyer_cannot_open_the_tracking_form(): void
    {
        $deal = $this->deal();

        Livewire::actingAs($this->buyer)
            ->test(MyDeals::class)
            ->call('openTracking', $deal->id)
            ->assertNotFound()
            ->assertSet('editingTracking', null);
    }

    public function test_the_seller_sees_the_fields_to_copy(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details());

        Livewire::actingAs($this->seller)
            ->test(MyDeals::class)
            ->assertSee('Товарителница')
            ->assertSee('Иван Петров')
            ->assertSee('Офис Център')
            ->assertSee('Наложен платеж');
    }

    /** The buyer's own address is theirs to see; the seller's panel is not. */
    public function test_the_buyer_is_not_shown_the_sellers_copy_panel(): void
    {
        $deal = $this->deal();
        $this->delivery->setDelivery($deal, $this->buyer, $this->details());

        Livewire::actingAs($this->buyer)
            ->test(MyDeals::class)
            ->assertDontSee('Копирай всички полета');
    }

    // --- the drift guard --------------------------------------------------

    /**
     * `['econt' => 'Еконт', 'speedy' => 'Спиди', 'pickup' => 'Лично предаване']`
     * was written out inline in THREE views. Two copies of a lookup table will
     * disagree; four is a certainty — and the fourth was already there, in the
     * terms page, spelling out each courier's inspection service by hand.
     */
    public function test_the_courier_names_live_in_exactly_one_place(): void
    {
        /*
         * A recursive iterator rather than glob(): PHP's glob does not treat `**`
         * as „any depth", so `views/**\/*.blade.php` silently means „exactly one
         * directory down" and a guard that quietly stops looking at the files it
         * was written for has proved nothing. The views here are three deep.
         */
        $views = new \RegexIterator(
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
            ),
            '/\.blade\.php$/',
        );

        $offenders = [];
        $scanned   = 0;

        foreach ($views as $view) {
            $scanned++;
            $source = (string) file_get_contents($view->getPathname());

            if (str_contains($source, "'econt' =>") || str_contains($source, '"econt" =>')) {
                $offenders[] = $view->getFilename();
            }
        }

        // The iterator itself is a thing that can silently find nothing.
        $this->assertGreaterThan(40, $scanned, 'the view scan found almost no files — it is not looking where it thinks');

        $this->assertSame([], array_values(array_unique($offenders)),
            'a view carries its own copy of the courier lookup table — use App\Enums\Courier');
    }
}
