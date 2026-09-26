<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\ModerationTrigger;
use App\Livewire\Safety\CheckSerial;
use App\Livewire\Safety\ReportStolen;
use App\Livewire\Safety\StolenQueue;
use App\Models\City;
use App\Models\ItemIdentifier as IdentifierRow;
use App\Models\Listing;
use App\Models\ModerationItem;
use App\Models\StolenReport;
use App\Models\User;
use App\Services\Safety\StolenRegistry;
use App\Support\ItemIdentifier;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The serial / IMEI register.
 *
 * THREE THINGS ARE BEING PROTECTED and only the first is a feature.
 *
 *   1. A buyer holding a serial can find out whether anybody reported it.
 *
 *   2. THE SERIAL IS NEVER STORED IN CLEAR. A table of plaintext serials on a
 *      hardware marketplace is a shopping list for whoever breaks in and a
 *      forgery kit for whoever wants a scam listing to survive a careful check.
 *      There is a test below that greps the database for the value.
 *
 *   3. THE FEATURE IS NOT A WEAPON. A „stolen" claim takes a named seller's
 *      listing off the site, so it costs a police reference, it is reviewed by a
 *      person, and confirming a claim is deliberately NOT the same act as
 *      removing a listing.
 */
class SerialRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** Luhn-valid, and documented as an example IMEI rather than anyone's. */
    private const IMEI     = '490154203237518';
    private const IMEI_BAD = '490154203237519';
    private const SERIAL   = 'GPU-1234-ABCD-5678';

    private User $seller;
    private User $admin;
    private StolenRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        // phpunit.xml leaves this unset so the field stays hidden everywhere
        // else; a file about the register turns it on for itself. Same lesson
        // as Turnstile, billing and the new-account hold.
        config(['remarket.identifiers.pepper' => 'test-pepper-at-least-32-characters-long']);

        $this->seller = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
            'is_admin'          => true,
        ]);

        $this->registry = app(StolenRegistry::class);
    }

    private function listing(array $attributes = [], ?User $owner = null): Listing
    {
        return Listing::factory()->create([
            'user_id'  => ($owner ?? $this->seller)->id,
            'city_id'  => City::first()->id,
            'category' => 'gpu',
            'status'   => ListingStatus::Active,
            ...$attributes,
        ]);
    }

    // --- hashing ----------------------------------------------------------

    /**
     * THE ONE THAT MATTERS MOST. Nothing anywhere in the database contains the
     * serial, in any column, in any table.
     */
    public function test_the_serial_never_reaches_the_database_in_clear(): void
    {
        $this->registry->attach($this->listing(), ItemIdentifier::SERIAL, self::SERIAL);
        $this->registry->report(
            kind: ItemIdentifier::IMEI,
            value: self::IMEI,
            policeRef: 'ДП 123/2026',
            detail: 'Телефонът беше отнет от автомобил на 12 март, по описа на РУ.',
            reporterEmail: 'victim@example.com',
        );

        $normalised = ItemIdentifier::normalise(ItemIdentifier::SERIAL, self::SERIAL);

        foreach (['item_identifiers', 'stolen_reports'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $dump = json_encode($row, JSON_UNESCAPED_UNICODE);

                $this->assertStringNotContainsString($normalised, $dump, "{$table} leaked a serial");
                $this->assertStringNotContainsString(self::SERIAL, $dump, "{$table} leaked a serial");
                $this->assertStringNotContainsString(self::IMEI, $dump, "{$table} leaked an IMEI");
            }
        }
    }

    /**
     * The pepper is what makes a dump inert, so storing without one is refused
     * rather than done quietly — a row hashed with an empty key could never be
     * matched by anything.
     */
    public function test_nothing_is_stored_without_a_pepper(): void
    {
        config(['remarket.identifiers.pepper' => null]);

        $this->assertFalse(ItemIdentifier::enabled());

        $this->expectException(RuntimeException::class);

        ItemIdentifier::hash(ItemIdentifier::SERIAL, self::SERIAL);
    }

    /** The same serial written by two people has to hash identically. */
    public function test_punctuation_and_case_do_not_change_the_hash(): void
    {
        $a = ItemIdentifier::hash(ItemIdentifier::SERIAL, 'GPU-1234 abcd/5678');
        $b = ItemIdentifier::hash(ItemIdentifier::SERIAL, 'gpu1234ABCD5678');

        $this->assertSame($a, $b);
    }

    /** A serial that reads like an IMEI is not an IMEI. */
    public function test_the_kind_is_part_of_the_hash(): void
    {
        $this->assertNotSame(
            ItemIdentifier::hash(ItemIdentifier::SERIAL, self::IMEI),
            ItemIdentifier::hash(ItemIdentifier::IMEI, self::IMEI),
        );
    }

    /**
     * The IMEI checksum, which is the only validation available anywhere in
     * this feature: a typo caught here is a row that would otherwise never
     * match anything, forever, silently.
     */
    public function test_an_imei_has_to_pass_its_own_checksum(): void
    {
        $this->assertTrue(ItemIdentifier::valid(ItemIdentifier::IMEI, self::IMEI));
        $this->assertFalse(ItemIdentifier::valid(ItemIdentifier::IMEI, self::IMEI_BAD));
        $this->assertFalse(ItemIdentifier::valid(ItemIdentifier::IMEI, '49015420323751'));

        // And the message points at the right place rather than saying „invalid".
        $this->assertStringContainsString(
            'контролна цифра',
            (string) ItemIdentifier::problem(ItemIdentifier::IMEI, self::IMEI_BAD),
        );
    }

    public function test_a_too_short_serial_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->registry->attach($this->listing(), ItemIdentifier::SERIAL, 'AB12');
    }

    // --- recording --------------------------------------------------------

    public function test_a_serial_is_recorded_once_per_listing_and_can_be_corrected(): void
    {
        $listing = $this->listing();

        $this->registry->attach($listing, ItemIdentifier::SERIAL, self::SERIAL);
        $this->registry->attach($listing, ItemIdentifier::SERIAL, 'GPU-9999-ZZZZ-0000');

        $this->assertSame(1, IdentifierRow::where('listing_id', $listing->id)->count());
        $this->assertSame('0000', IdentifierRow::where('listing_id', $listing->id)->value('last4'));
    }

    /**
     * ONE OBJECT IS IN ONE PLACE. Two live listings carrying the same serial
     * means one of them is wrong — a relist that should have been an edit, or
     * one seller working from another's photographs — and either way a person
     * should look.
     */
    public function test_the_same_serial_on_two_live_listings_is_flagged(): void
    {
        $first = $this->listing();
        $this->registry->attach($first, ItemIdentifier::SERIAL, self::SERIAL);

        $stranger = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        $second = $this->listing([], $stranger);
        $flags  = $this->registry->attach($second, ItemIdentifier::SERIAL, self::SERIAL);

        $this->assertContains(ModerationTrigger::DuplicateSerial, $flags);
        $this->assertSame(ListingStatus::PendingReview, $second->fresh()->status);

        // And it is in the queue, where a human will see it.
        $this->assertSame(1, ModerationItem::where('subject_id', $second->id)
            ->where('trigger', ModerationTrigger::DuplicateSerial->value)
            ->count());
    }

    /** A seller's own listing relisted with the same serial is not a duplicate. */
    public function test_one_listing_with_its_own_serial_is_not_a_duplicate(): void
    {
        $listing = $this->listing();

        $flags = $this->registry->attach($listing, ItemIdentifier::SERIAL, self::SERIAL);

        $this->assertSame([], $flags);
        $this->assertSame(ListingStatus::Active, $listing->fresh()->status);
    }

    // --- claims -----------------------------------------------------------

    public function test_a_claim_alone_changes_nothing(): void
    {
        $listing = $this->listing();
        $this->registry->attach($listing, ItemIdentifier::IMEI, self::IMEI);

        $this->registry->report(
            kind: ItemIdentifier::IMEI,
            value: self::IMEI,
            policeRef: 'ДП 123/2026',
            detail: 'Телефонът беше отнет от автомобил на 12 март, по описа на РУ.',
            reporterEmail: 'victim@example.com',
        );

        // Nothing until a person decides. A pending claim that took a listing
        // down would make this feature a way to silence a competitor for free.
        $this->assertSame(ListingStatus::Active, $listing->fresh()->status);
        $this->assertSame(0, ModerationItem::where('trigger', ModerationTrigger::StolenClaim->value)->count());
    }

    /**
     * Confirming pulls the matching listings down FOR REVIEW — it does not
     * remove them. The removal is a separate decision with a statement of
     * reasons the seller can dispute.
     */
    public function test_confirming_a_claim_queues_the_matching_listings(): void
    {
        $listing = $this->listing();
        $this->registry->attach($listing, ItemIdentifier::IMEI, self::IMEI);

        $report = $this->registry->report(
            kind: ItemIdentifier::IMEI,
            value: self::IMEI,
            policeRef: 'ДП 123/2026',
            detail: 'Телефонът беше отнет от автомобил на 12 март, по описа на РУ.',
            reporterEmail: 'victim@example.com',
        );

        $queued = $this->registry->confirm($report, $this->admin, 'Видях протокола.');

        $this->assertSame(1, $queued);
        $this->assertSame(StolenReport::CONFIRMED, $report->fresh()->status);

        // Off the public site, but NOT removed: a person decides that.
        $this->assertSame(ListingStatus::PendingReview, $listing->fresh()->status);
        $this->assertSame(1, ModerationItem::where('subject_id', $listing->id)
            ->where('trigger', ModerationTrigger::StolenClaim->value)
            ->count());
    }

    /** A serial reported before it is ever listed still catches the listing. */
    public function test_a_confirmed_claim_catches_a_listing_posted_afterwards(): void
    {
        $report = $this->registry->report(
            kind: ItemIdentifier::SERIAL,
            value: self::SERIAL,
            policeRef: 'ДП 456/2026',
            detail: 'Видеокартата беше отнета при взлом в апартамент на 3 април.',
            reporterEmail: 'victim@example.com',
        );

        // Nothing is listed yet, so nothing is queued — and the screen says so.
        $this->assertSame(0, $this->registry->confirm($report, $this->admin));

        $listing = $this->listing();
        $flags   = $this->registry->attach($listing, ItemIdentifier::SERIAL, self::SERIAL);

        $this->assertContains(ModerationTrigger::StolenClaim, $flags);
        $this->assertSame(ListingStatus::PendingReview, $listing->fresh()->status);
    }

    public function test_a_rejected_claim_is_not_the_register(): void
    {
        $report = $this->registry->report(
            kind: ItemIdentifier::SERIAL,
            value: self::SERIAL,
            policeRef: 'ДП 456/2026',
            detail: 'Видеокартата беше отнета при взлом в апартамент на 3 април.',
            reporterEmail: 'victim@example.com',
        );

        $this->registry->reject($report, $this->admin, 'Номерът не съвпада с описа.');

        $listing = $this->listing();

        $this->assertSame([], $this->registry->attach($listing, ItemIdentifier::SERIAL, self::SERIAL));
        $this->assertSame(ListingStatus::Active, $listing->fresh()->status);
        $this->assertFalse($this->registry->check(ItemIdentifier::SERIAL, self::SERIAL)['reported']);
    }

    /** A rejected claim about somebody's property needs a recorded reason. */
    public function test_a_rejection_needs_a_reason(): void
    {
        $report = $this->registry->report(
            kind: ItemIdentifier::SERIAL,
            value: self::SERIAL,
            policeRef: 'ДП 456/2026',
            detail: 'Видеокартата беше отнета при взлом в апартамент на 3 април.',
            reporterEmail: 'victim@example.com',
        );

        $this->expectException(RuntimeException::class);

        $this->registry->reject($report, $this->admin, '');
    }

    /** Filing the same claim four times must not become four queue items. */
    public function test_the_same_reporter_filing_twice_gets_one_claim(): void
    {
        $args = [
            'kind'          => ItemIdentifier::SERIAL,
            'value'         => self::SERIAL,
            'policeRef'     => 'ДП 456/2026',
            'detail'        => 'Видеокартата беше отнета при взлом в апартамент на 3 април.',
            'reporterEmail' => 'victim@example.com',
        ];

        $first  = $this->registry->report(...$args);
        $second = $this->registry->report(...$args);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StolenReport::count());
    }

    public function test_a_decided_claim_cannot_be_decided_again(): void
    {
        $report = $this->registry->report(
            kind: ItemIdentifier::SERIAL,
            value: self::SERIAL,
            policeRef: 'ДП 456/2026',
            detail: 'Видеокартата беше отнета при взлом в апартамент на 3 април.',
            reporterEmail: 'victim@example.com',
        );

        $this->registry->confirm($report, $this->admin);

        $this->expectException(RuntimeException::class);

        $this->registry->reject($report->fresh(), $this->admin, 'Промених решението.');
    }

    // --- the lookup -------------------------------------------------------

    /**
     * The three answers, and the third is the one that matters: „we know
     * nothing" must never read as „not stolen".
     */
    public function test_the_check_tells_a_buyer_what_is_and_is_not_known(): void
    {
        $listing = $this->listing();
        $this->registry->attach($listing, ItemIdentifier::SERIAL, self::SERIAL);

        Livewire::actingAs($this->seller)
            ->test(CheckSerial::class)
            ->set('kind', ItemIdentifier::SERIAL)
            ->set('value', self::SERIAL)
            ->call('check')
            ->assertSee('Номерът е записан')
            ->set('value', 'GPU-0000-NONE-0000')
            ->call('check')
            ->assertSee('Не знаем нищо')
            // The paragraph that keeps a buyer from misreading silence.
            ->assertSee('Това не значи, че вещта е чиста');
    }

    public function test_the_check_reports_a_confirmed_claim_without_calling_it_proof(): void
    {
        $report = $this->registry->report(
            kind: ItemIdentifier::IMEI,
            value: self::IMEI,
            policeRef: 'ДП 123/2026',
            detail: 'Телефонът беше отнет от автомобил на 12 март, по описа на РУ.',
            reporterEmail: 'victim@example.com',
        );

        $this->registry->confirm($report, $this->admin);

        Livewire::actingAs($this->seller)
            ->test(CheckSerial::class)
            ->set('kind', ItemIdentifier::IMEI)
            ->set('value', self::IMEI)
            ->call('check')
            ->assertSee('Има подаден сигнал')
            // Never a finding of theft. The platform verifies nothing and says so.
            ->assertSee('Това не е доказателство за кражба');
    }

    /**
     * The lookup costs an account, because an open „is this serial reported"
     * endpoint is also how a thief checks whether their haul is hot.
     */
    public function test_the_check_needs_an_account_and_the_report_does_not(): void
    {
        $this->get(route('safety.check'))->assertRedirect();
        $this->get(route('safety.report'))->assertOk();
    }

    public function test_the_lookup_is_rate_limited(): void
    {
        config(['remarket.identifiers.lookups_per_hour' => 2]);

        $page = Livewire::actingAs($this->seller)->test(CheckSerial::class);

        for ($i = 0; $i < 2; $i++) {
            $page->set('value', self::SERIAL)->call('check')->assertHasNoErrors();
        }

        $page->set('value', self::SERIAL)->call('check')->assertHasErrors('value');
    }

    // --- the screens ------------------------------------------------------

    public function test_a_guest_can_file_a_claim_with_a_police_reference(): void
    {
        Livewire::test(ReportStolen::class)
            ->set('kind', ItemIdentifier::IMEI)
            ->set('value', self::IMEI)
            ->set('policeRef', 'ДП 123/2026')
            ->set('detail', 'Телефонът беше отнет от автомобил на 12 март, по описа на РУ.')
            ->set('email', 'victim@example.com')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(1, StolenReport::count());
        $this->assertSame('victim@example.com', StolenReport::sole()->reporter_email);
    }

    /**
     * THE GUARD THAT KEEPS THIS FROM BEING A SABOTAGE TOOL. Without a police
     * reference, „stolen" is a free way to take a competitor's listing down.
     */
    public function test_a_claim_without_a_police_reference_is_refused(): void
    {
        Livewire::test(ReportStolen::class)
            ->set('kind', ItemIdentifier::IMEI)
            ->set('value', self::IMEI)
            ->set('detail', 'Телефонът беше отнет от автомобил на 12 март, по описа на РУ.')
            ->set('email', 'victim@example.com')
            ->call('submit')
            ->assertHasErrors('policeRef');

        $this->assertSame(0, StolenReport::count());
    }

    /** And the form says, before it is filled in, that nothing is verified. */
    public function test_the_report_form_does_not_imply_verification(): void
    {
        $this->get(route('safety.report'))
            ->assertSee('Не проверяваме полицейски регистри')
            ->assertSee('Не установяваме кражба и не обвиняваме продавач');
    }

    public function test_the_claim_queue_is_admin_only(): void
    {
        $this->actingAs($this->seller)->get(route('stolen'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('stolen'))->assertOk();
    }

    public function test_an_admin_confirms_from_the_queue(): void
    {
        $listing = $this->listing();
        $this->registry->attach($listing, ItemIdentifier::SERIAL, self::SERIAL);

        $report = $this->registry->report(
            kind: ItemIdentifier::SERIAL,
            value: self::SERIAL,
            policeRef: 'ДП 456/2026',
            detail: 'Видеокартата беше отнета при взлом в апартамент на 3 април.',
            reporterEmail: 'victim@example.com',
        );

        Livewire::actingAs($this->admin)
            ->test(StolenQueue::class)
            ->assertSee('ДП 456/2026')
            ->call('open', $report->id)
            ->set('note', 'Видях протокола.')
            ->call('confirm', $report->id)
            ->assertHasNoErrors();

        $this->assertSame(StolenReport::CONFIRMED, $report->fresh()->status);
        $this->assertSame(ListingStatus::PendingReview, $listing->fresh()->status);
    }

    /**
     * The field disappears entirely without a pepper, and appears with one.
     *
     * Asserted on the EDIT screen rather than the wizard: the wizard opens on
     * step 1, so „assertDontSee" there would pass whether the partial were
     * guarded or not — a test that cannot fail is worse than no test.
     */
    public function test_the_field_appears_only_when_the_register_is_configured(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->seller)
            ->get(route('listing.edit', $listing))
            ->assertOk()
            ->assertSee('Сериен номер / IMEI');

        config(['remarket.identifiers.pepper' => null]);

        $this->actingAs($this->seller)
            ->get(route('listing.edit', $listing))
            ->assertOk()
            ->assertDontSee('Сериен номер / IMEI');
    }

    /** And what is already recorded is shown back as a mask, never a value. */
    public function test_the_edit_screen_shows_a_mask_and_not_the_serial(): void
    {
        $listing = $this->listing();
        $this->registry->attach($listing, ItemIdentifier::SERIAL, self::SERIAL);

        $this->actingAs($this->seller)
            ->get(route('listing.edit', $listing))
            ->assertSee('…5678')
            ->assertDontSee(ItemIdentifier::normalise(ItemIdentifier::SERIAL, self::SERIAL));
    }
}
