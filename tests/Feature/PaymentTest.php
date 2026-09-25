<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Livewire\Billing\MyCredit;
use App\Livewire\Billing\PaymentQueue;
use App\Models\City;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\CreditService;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentService;
use App\Support\BillingIdentity;
use Database\Seeders\CitySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Money coming in, and the document that says it did.
 *
 * Most of this file is about the two things that cannot be repaired after the
 * fact:
 *
 *   THE NUMBER. Bulgarian invoice numbers are sequential and gapless. A hole
 *   in the series is something somebody has to explain to an accountant years
 *   later, and a duplicate is a correction procedure rather than a bug fix.
 *
 *   THE HALF-CONFIRMATION. Marking a payment paid, crediting the balance and
 *   issuing the document are three writes, and any two of them without the
 *   third is a mess unpicked by hand: money taken with no document, a receipt
 *   with no balance, or a real number issued for nothing.
 *
 * Everything else here is the refusals that keep those two safe.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $admin;
    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->seller = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
            'is_admin'          => true,
        ]);

        $this->payments = app(PaymentService::class);
    }

    /**
     * Billing switched on for one test.
     *
     * phpunit.xml pins these blank so no other test depends on them; anything
     * that needs a working till says so out loud, here.
     */
    private function billingReady(bool $vatRegistered = false): void
    {
        config([
            'remarket.billing.iban'            => 'BG80BNBG96611020345678',
            'remarket.billing.bic'             => 'BNBGBGSF',
            'remarket.billing.bank'            => 'Тестова банка',
            'remarket.billing.vat_registered'  => $vatRegistered,
            'remarket.billing.vat_number'      => $vatRegistered ? 'BG208386399' : null,
            'remarket.billing.vat_rate'        => 20,
            'remarket.billing.vat_exempt_note' => $vatRegistered
                ? null
                : 'Основание за неначисляване на ДДС: чл. ... (тестов текст).',
        ]);
    }

    private function billTo(): array
    {
        return [
            'name'    => 'КОМПЮТЪРС ЕООД',
            'eik'     => '123456789',
            'vat'     => null,
            'address' => 'ул. Тестова 1',
            'city'    => 'Велико Търново',
            'person'  => 'Иван Иванов',
        ];
    }

    private function request(int $cents = 2000): Payment
    {
        return $this->payments->request($this->seller, $cents, $this->billTo());
    }

    // --- the till is closed until it is configured ------------------------

    /**
     * THE ONE THAT PROTECTS THE NUMBER SERIES.
     *
     * A fresh server has an empty `.env`. Without this refusal the first
     * confirmed payment issues invoice 0000000001 with no VAT treatment on it
     * — a wrong document with a real number, which cannot be deleted, only
     * corrected with a second document.
     */
    public function test_a_half_configured_deployment_cannot_take_money(): void
    {
        $this->assertFalse(BillingIdentity::ready());

        try {
            $this->request();
            $this->fail('took a payment with no billing details configured');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('не са активни', $e->getMessage());
        }

        $this->assertSame(0, Payment::count());
    }

    /**
     * An invoice that charges no VAT must say on what GROUND.
     *
     * This is the field that has no default anywhere in the codebase, because
     * the answer is the accountant's and inventing one would be a false
     * statement about a real company's tax status.
     */
    public function test_an_iban_alone_is_not_enough_without_the_vat_ground(): void
    {
        config(['remarket.billing.iban' => 'BG80BNBG96611020345678']);

        $this->assertArrayHasKey('BILLING_VAT_EXEMPT_NOTE', BillingIdentity::missing());
        $this->assertFalse(BillingIdentity::ready());
    }

    // --- asking to pay ----------------------------------------------------

    public function test_a_request_is_pending_and_carries_a_reference(): void
    {
        $this->billingReady();

        $payment = $this->request(5000);

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(5000, $payment->amount_cents);
        $this->assertNotEmpty($payment->reference);

        // Nothing has moved yet — that is the entire point of a pending row.
        $this->assertSame(0, app(CreditService::class)->balance($this->seller));
        $this->assertSame(0, Invoice::count());
    }

    /**
     * The reference is typed by a person off a phone screen into a banking
     * app. Anything that can be misread as something else is a transfer that
     * cannot be matched to anybody.
     */
    public function test_the_reference_avoids_characters_that_look_alike(): void
    {
        $this->billingReady();

        for ($i = 0; $i < 20; $i++) {
            $reference = Payment::newReference();

            $this->assertStringStartsWith('RIGO-', $reference);
            // The prefix is exempt — it is not typed from memory, it is read.
            $this->assertDoesNotMatchRegularExpression('/[0158OILSB]/', substr($reference, 5));
        }
    }

    public function test_the_amount_has_to_be_within_the_limits(): void
    {
        $this->billingReady();

        foreach ([100, 999_999] as $silly) {
            try {
                $this->request($silly);
                $this->fail("accepted a top-up of {$silly}");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Payment::count());
    }

    // --- confirming -------------------------------------------------------

    public function test_confirming_issues_the_invoice_and_credits_the_balance(): void
    {
        $this->billingReady();

        $payment = $this->request(5000);

        $this->payments->confirm($payment, $this->admin);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame($this->admin->id, $payment->confirmed_by);
        $this->assertSame(5000, app(CreditService::class)->balance($this->seller));

        $invoice = $payment->invoice;
        $this->assertNotNull($invoice);
        $this->assertSame(5000, $invoice->total_cents);

        // The ledger row names the document, so a balance can be traced to a
        // piece of paper without a join.
        $this->assertStringContainsString(
            $invoice->number,
            CreditTransaction::where('user_id', $this->seller->id)->latest('id')->first()->note,
        );
    }

    /**
     * THE HALF-CONFIRMATION, PREVENTED.
     *
     * The invoice is issued first inside the transaction precisely so that a
     * deployment which cannot issue one leaves nothing behind.
     */
    public function test_a_confirmation_that_cannot_be_invoiced_moves_nothing(): void
    {
        $this->billingReady();
        $payment = $this->request(5000);

        // The accountant's sentence goes missing between request and
        // confirmation — someone edited .env and re-cached.
        config(['remarket.billing.vat_exempt_note' => null]);

        try {
            $this->payments->confirm($payment, $this->admin);
            $this->fail('confirmed a payment that could not be invoiced');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Липсват данни', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, app(CreditService::class)->balance($this->seller));
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_payment_cannot_be_confirmed_twice(): void
    {
        $this->billingReady();
        $payment = $this->request();

        $this->payments->confirm($payment, $this->admin);

        $this->expectException(RuntimeException::class);

        $this->payments->confirm($payment->fresh(), $this->admin);
    }

    /** One invoice per payment, and the database says so, not just the code. */
    public function test_a_second_invoice_for_one_payment_is_refused(): void
    {
        $this->billingReady();
        $payment = $this->request();

        $this->payments->confirm($payment, $this->admin);

        $this->expectException(RuntimeException::class);

        app(InvoiceService::class)->issue($payment->fresh());
    }

    public function test_cancelling_leaves_no_document_and_no_credit(): void
    {
        $this->billingReady();
        $payment = $this->request();

        $this->payments->cancel($payment, 'Преводът не постъпи.');

        $this->assertSame(PaymentStatus::Cancelled, $payment->fresh()->status);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, app(CreditService::class)->balance($this->seller));
    }

    // --- the number -------------------------------------------------------

    /**
     * Sequential, zero-padded to ten, and no gaps.
     *
     * A database SEQUENCE would pass the „increasing" half of this and fail
     * the gapless half the first time a transaction rolled back, which is why
     * the counter is a locked row instead.
     */
    public function test_invoice_numbers_are_sequential_and_gapless(): void
    {
        $this->billingReady();

        $numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $payment = $this->request();
            $this->payments->confirm($payment, $this->admin);
            $numbers[] = $payment->fresh()->invoice->number;
        }

        $this->assertSame(
            ['0000000001', '0000000002', '0000000003', '0000000004', '0000000005'],
            $numbers,
        );
    }

    /**
     * A rollback gives the number back rather than burning it.
     *
     * This is the whole reason the counter is a locked row and not a Postgres
     * sequence — a sequence deliberately survives rollback, and the hole it
     * leaves is permanent.
     */
    public function test_a_failed_issue_does_not_burn_a_number(): void
    {
        $this->billingReady();

        $first = $this->request();
        $this->payments->confirm($first, $this->admin);
        $this->assertSame('0000000001', $first->fresh()->invoice->number);

        // A confirmation that dies inside the transaction, after the number
        // was taken: the ledger refuses a zero-amount row.
        $broken = $this->request();
        $broken->forceFill(['amount_cents' => 0])->save();

        try {
            $this->payments->confirm($broken, $this->admin);
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $next = $this->request();
        $this->payments->confirm($next, $this->admin);

        $this->assertSame('0000000002', $next->fresh()->invoice->number);
    }

    /** An issued invoice is not editable, and the refusal is loud. */
    public function test_an_issued_invoice_cannot_be_edited(): void
    {
        $this->billingReady();
        $payment = $this->request();
        $this->payments->confirm($payment, $this->admin);

        $this->expectException(QueryException::class);

        \DB::table('invoices')
            ->where('id', $payment->fresh()->invoice->id)
            ->update(['total_cents' => 1]);
    }

    // --- VAT --------------------------------------------------------------

    /**
     * Not registered: no VAT charged, and the document carries the ground.
     *
     * This is the live configuration — BG208386399 is a чл. 97а registration,
     * not ordinary VAT registration, and config/legal.php says so.
     */
    public function test_with_no_vat_registration_the_document_states_the_ground(): void
    {
        $this->billingReady(vatRegistered: false);

        $payment = $this->request(5000);
        $this->payments->confirm($payment, $this->admin);

        $invoice = $payment->fresh()->invoice;

        $this->assertSame(5000, $invoice->net_cents);
        $this->assertSame(0, $invoice->vat_cents);
        $this->assertSame(0.0, (float) $invoice->vat_rate);
        $this->assertNotEmpty($invoice->vat_note);
    }

    /**
     * Registered: the price the seller saw IS the total, with the tax inside
     * it — never added on at the till.
     */
    public function test_vat_is_inside_the_price_and_the_lines_add_up(): void
    {
        $this->billingReady(vatRegistered: true);

        $payment = $this->request(5000);
        $this->payments->confirm($payment, $this->admin);

        $invoice = $payment->fresh()->invoice;

        $this->assertSame(5000, $invoice->total_cents);
        $this->assertSame($invoice->total_cents, $invoice->net_cents + $invoice->vat_cents);
        $this->assertSame(4166, $invoice->net_cents);     // floor(5000 / 1.2)
        $this->assertNull($invoice->vat_note);
    }

    /** No rounding can make the two lines disagree with the total. */
    public function test_the_split_always_adds_back_up(): void
    {
        $this->billingReady(vatRegistered: true);

        foreach ([1, 7, 99, 100, 333, 999, 1234, 50000] as $gross) {
            $split = BillingIdentity::split($gross);

            $this->assertSame($gross, $split['net'] + $split['vat'], "broke at {$gross}");
        }
    }

    // --- the screens ------------------------------------------------------

    public function test_a_seller_can_ask_for_a_top_up(): void
    {
        $this->billingReady();

        Livewire::actingAs($this->seller)
            ->test(MyCredit::class)
            ->call('startTopUp')
            ->set('amount', 2000)
            ->set('billName', 'КОМПЮТЪРС ЕООД')
            ->set('billAddress', 'ул. Тестова 1')
            ->set('billCity', 'Велико Търново')
            ->call('requestTopUp')
            ->assertHasNoErrors();

        $payment = Payment::sole();

        $this->assertSame($this->seller->id, $payment->user_id);
        $this->assertSame('КОМПЮТЪРС ЕООД', $payment->bill_to_name);
    }

    public function test_the_top_up_button_is_absent_when_the_till_is_closed(): void
    {
        Livewire::actingAs($this->seller)
            ->test(MyCredit::class)
            ->assertDontSee('Зареди кредит')
            ->assertSee('не е активно');
    }

    /** The instructions carry the reference, which is the only thing that matters. */
    public function test_a_pending_payment_shows_what_to_transfer(): void
    {
        $this->billingReady();
        $payment = $this->request(2500);

        Livewire::actingAs($this->seller)
            ->test(MyCredit::class)
            ->assertSee($payment->reference)
            ->assertSee('BG80BNBG96611020345678');
    }

    public function test_an_admin_confirms_from_the_queue(): void
    {
        $this->billingReady();
        $payment = $this->request(3000);

        Livewire::actingAs($this->admin)
            ->test(PaymentQueue::class)
            ->assertSee($payment->reference)
            ->call('confirm', $payment->id)
            ->assertHasNoErrors();

        $this->assertSame(3000, app(CreditService::class)->balance($this->seller));
    }

    public function test_the_payment_queue_is_admin_only(): void
    {
        $this->actingAs($this->seller)->get(route('payments'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('payments'))->assertOk();
    }

    // --- the document -----------------------------------------------------

    public function test_the_invoice_is_visible_to_its_owner_and_to_an_admin(): void
    {
        $this->billingReady();
        $payment = $this->request(5000);
        $this->payments->confirm($payment, $this->admin);

        $invoice = $payment->fresh()->invoice;

        $this->actingAs($this->seller)->get(route('invoice', $invoice))
            ->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('КОМПЮТЪРС ЕООД')
            // The issuer block is frozen onto the row, so the real company
            // name appears without a join to config.
            ->assertSee(config('legal.entity.name'));

        $this->actingAs($this->admin)->get(route('invoice', $invoice))->assertOk();
    }

    public function test_a_stranger_cannot_read_somebody_elses_invoice(): void
    {
        $this->billingReady();
        $payment = $this->request();
        $this->payments->confirm($payment, $this->admin);

        $stranger = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        $this->actingAs($stranger)
            ->get(route('invoice', $payment->fresh()->invoice))
            ->assertNotFound();
    }

    /**
     * The document is a statement about a past fact, so nothing on it may be a
     * join to a row that can still change.
     */
    public function test_the_document_keeps_saying_what_it_said(): void
    {
        $this->billingReady();
        $payment = $this->request(5000);
        $this->payments->confirm($payment, $this->admin);

        $invoice = $payment->fresh()->invoice;
        $issued  = $invoice->issuer;

        // The company registers for VAT and moves, a year later.
        config([
            'remarket.billing.vat_registered' => true,
            'legal.entity.name'               => 'СЪВСЕМ ДРУГО ИМЕ ООД',
        ]);

        $this->assertSame($issued, $invoice->fresh()->issuer);
        $this->assertSame(0, $invoice->fresh()->vat_cents);
        $this->assertStringNotContainsString('СЪВСЕМ ДРУГО ИМЕ', $invoice->fresh()->issuer);
    }
}
