<?php

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Jobs\BuildDataExport;
use App\Livewire\Privacy\MyData;
use App\Models\City;
use App\Models\DataExport;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\Rating;
use App\Models\User;
use App\Services\Privacy\AccountDeletion;
use App\Services\Privacy\PersonalDataExport;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * GDPR Art. 15 (a copy) and Art. 17 (erase me), self-service.
 *
 * THE SHAPE OF ERASURE HERE WAS DECIDED BY THE FOREIGN KEYS. Every user FK in
 * this schema is `cascadeOnDelete`, so `forceDelete()` on a user is not „remove
 * this person" — it takes the counterparty's deals, both directions of every
 * rating, and the invoices чл. 38 ДОПК requires kept for five years. So the row
 * stays and the person is scrubbed out of it, and the tests below are mostly
 * about what must SURVIVE rather than what must go:
 *
 *   - an honest seller's rating average must not move because a buyer left;
 *   - invoices must still exist, because deleting them is not lawful;
 *   - and nothing anywhere may call forceDelete() on a user, ever.
 *
 * Plus the two that are about the person leaving: their identifying columns
 * really do go, and an export really does contain their data and NOT the
 * counterparty's.
 */
class PrivacyRightsTest extends TestCase
{
    use RefreshDatabase;

    private User $leaver;
    private User $other;
    private AccountDeletion $deletion;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $this->seed(CitySeeder::class);

        $this->leaver = $this->user();
        $this->other  = $this->user();
        $this->deletion = app(AccountDeletion::class);
    }

    private function user(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ])->refresh();
    }

    private function completedDeal(): Deal
    {
        $listing = Listing::factory()->create([
            'user_id' => $this->other->id,
            'city_id' => City::first()->id,
            'status'  => ListingStatus::Sold,
        ]);

        $deal = Deal::create([
            'listing_id'         => $listing->id,
            'buyer_id'           => $this->leaver->id,
            'seller_id'          => $this->other->id,
            'agreed_price_cents' => 45000,
            'expires_at'         => now()->addDays(3),
        ]);

        $deal->forceFill([
            'status'       => DealStatus::Completed->value,
            'completed_at' => now(),
        ])->save();

        return $deal->refresh();
    }

    // --- what must survive an erasure -------------------------------------

    /**
     * THE TEST THIS FILE EXISTS FOR.
     *
     * The seller did nothing wrong. A buyer closing their account must not take
     * the seller's rating, their completed-deal count or the deal record with
     * them — that is somebody else's reputation, and it is why erasure here is
     * anonymisation.
     */
    public function test_leaving_does_not_erase_the_other_persons_reputation(): void
    {
        $deal = $this->completedDeal();

        Rating::create([
            'deal_id'  => $deal->id,
            'rater_id' => $this->leaver->id,
            'ratee_id' => $this->other->id,
            'score'    => 5,
            'role'     => 'seller',
            'comment'  => 'Всичко беше точно.',
        ]);

        $this->deletion->request($this->leaver);
        $this->deletion->anonymise($this->leaver->fresh());

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'status' => 'completed']);
        $this->assertSame(1, Rating::where('ratee_id', $this->other->id)->count(),
            'the seller lost a rating because the buyer closed their account');
    }

    /**
     * Invoices carry a frozen name and address and a five-year retention
     * obligation under чл. 38 ДОПК. GDPR Art. 17(3)(b) says erasure does not
     * reach them, and the deletion screen says so before anybody confirms.
     */
    public function test_invoices_survive_an_erasure(): void
    {
        /*
         * bill_to_* live on PAYMENTS, not on invoices — checked against the
         * migration rather than assumed, after the first draft of this test put
         * them on the wrong table. The invoice freezes its issuer; the payment
         * freezes the payer.
         */
        $payment = DB::table('payments')->insertGetId([
            'uuid'            => (string) \Illuminate\Support\Str::uuid(),
            'user_id'         => $this->leaver->id,
            'amount_cents'    => 5000,
            'provider'        => 'bank',
            'reference'       => 'TEST2346',
            'status'          => 'confirmed',
            'bill_to_name'    => 'Иван Петров',
            'bill_to_address' => 'ул. Тестова 1',
            // NOT NULL, like bill_to_name and bill_to_address. Only eik, vat and
            // person are nullable — a private buyer has no company number.
            'bill_to_city'    => 'София',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('invoices')->insert([
            'uuid'        => (string) \Illuminate\Support\Str::uuid(),
            'payment_id'  => $payment,
            'user_id'     => $this->leaver->id,
            'number'      => '0000000001',
            'issued_on'   => now()->toDateString(),
            'net_cents'   => 5000,
            'vat_cents'   => 0,
            'total_cents' => 5000,
            'issuer'      => json_encode(['name' => 'RIGO']),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->deletion->request($this->leaver);
        $this->deletion->anonymise($this->leaver->fresh());

        $this->assertSame(1, DB::table('invoices')->where('user_id', $this->leaver->id)->count(),
            'an invoice was destroyed by an erasure — that is an accounting-law breach, not a feature');
    }

    /**
     * THE STRUCTURAL GUARD. Nothing may hard-delete a user, because the cascades
     * make it a data-destruction event rather than a deletion.
     */
    public function test_nothing_hard_deletes_a_user(): void
    {
        /*
         * Matches the CALL, not the word — and this is the second time the same
         * mistake has been made in this codebase in a week. The mobile pass had
         * a guard greppping for `group-hover` that failed on the comment
         * explaining its own fix; this one grepped for `forceDelete` and matched
         * the docblock sentence „NOTHING HERE EVER CALLS forceDelete()".
         *
         * A guard that cannot tell code from the prose describing it reports the
         * wrong thing, and both times it was caught only by running it.
         */
        $source = (string) file_get_contents(app_path('Services/Privacy/AccountDeletion.php'));
        $code   = preg_replace(['#/\*.*?\*/#s', '#//.*#'], '', $source);

        $this->assertDoesNotMatchRegularExpression('/->\s*forceDelete\s*\(/', (string) $code,
            'AccountDeletion calls forceDelete — every user FK here is cascadeOnDelete, so that '
            .'destroys the counterparty\'s deals, both directions of every rating, and the invoices');

        // And the rule is still written down where the next person will read it.
        $this->assertStringContainsString('NOTHING HERE EVER CALLS forceDelete()', $source);
    }

    // --- what must actually go --------------------------------------------

    public function test_the_person_is_scrubbed_out_of_the_row(): void
    {
        $email    = $this->leaver->email;
        $username = $this->leaver->username;

        $this->deletion->request($this->leaver);
        $this->deletion->anonymise($this->leaver->fresh());

        $fresh = User::withTrashed()->find($this->leaver->id);

        $this->assertNotSame($email, $fresh->email);
        $this->assertNotSame($username, $fresh->username);
        $this->assertStringEndsWith('@deleted.invalid', $fresh->email);
        $this->assertNull($fresh->trader_details);
        $this->assertNull($fresh->city_id);
        $this->assertNull($fresh->phone_last4);
        $this->assertNotNull($fresh->anonymised_at);
        $this->assertNotNull($fresh->deleted_at);
    }

    /**
     * The phone hash is the one judgement call. It was kept deliberately so a
     * banned phone cannot re-register — a real interest, and not one that covers
     * somebody who simply left.
     */
    public function test_the_phone_hash_goes_for_a_leaver_and_stays_for_a_banned_account(): void
    {
        $leaver = $this->user();
        $leaver->forceFill(['phone_hash' => str_repeat('a', 64)])->save();

        $this->deletion->request($leaver);
        $this->deletion->anonymise($leaver->fresh());
        $this->assertNull(User::withTrashed()->find($leaver->id)->phone_hash);

        $banned = $this->user();
        $banned->forceFill(['phone_hash' => str_repeat('b', 64), 'banned_at' => now()])->save();

        $this->deletion->request($banned);
        $this->deletion->anonymise($banned->fresh());
        $this->assertNotNull(User::withTrashed()->find($banned->id)->phone_hash,
            'a banned account gave up its phone hash, so the ban can be walked around by re-registering');
    }

    public function test_listings_come_down_but_are_not_hard_deleted(): void
    {
        $listing = Listing::factory()->create([
            'user_id' => $this->leaver->id,
            'city_id' => City::first()->id,
            'status'  => ListingStatus::Active,
        ]);

        $this->deletion->request($this->leaver);

        $this->assertSoftDeleted('listings', ['id' => $listing->id]);
        // Hard-deleting would cascade into `deals.listing_id` and take the
        // deals — and their ratings — with it.
        $this->assertSame(1, Listing::withTrashed()->whereKey($listing->id)->count());
    }

    public function test_favourites_and_saved_searches_go_outright(): void
    {
        DB::table('favorites')->insert([
            'user_id' => $this->leaver->id,
            'listing_id' => Listing::factory()->create([
                'user_id' => $this->other->id, 'city_id' => City::first()->id,
            ])->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deletion->request($this->leaver);
        $this->deletion->anonymise($this->leaver->fresh());

        $this->assertSame(0, DB::table('favorites')->where('user_id', $this->leaver->id)->count());
    }

    // --- the grace window --------------------------------------------------

    public function test_the_data_is_still_there_during_the_grace_window(): void
    {
        $email = $this->leaver->email;

        $this->deletion->request($this->leaver);

        $this->assertFalse($this->deletion->due($this->leaver->fresh()));
        $this->assertSame($email, $this->leaver->fresh()->email);
    }

    public function test_coming_back_stops_the_deletion(): void
    {
        $this->deletion->request($this->leaver);
        $this->deletion->cancel($this->leaver->fresh());

        $this->assertNull($this->leaver->fresh()->deletion_requested_at);
        $this->assertNull($this->leaver->fresh()->anonymised_at);
    }

    public function test_the_sweep_erases_only_what_is_past_the_window(): void
    {
        $soon = $this->user();
        $this->deletion->request($soon);

        $this->deletion->request($this->leaver);
        User::whereKey($this->leaver->id)->update(['deletion_requested_at' => now()->subDays(40)]);

        $this->artisan('remarket:purge-accounts')->assertSuccessful();

        $this->assertNotNull(User::withTrashed()->find($this->leaver->id)->anonymised_at);
        $this->assertNull(User::withTrashed()->find($soon->id)->anonymised_at,
            'an account still inside its grace window was erased');
    }

    /** An erased account cannot be brought back by asking nicely. */
    public function test_an_erased_account_cannot_be_restored(): void
    {
        $this->deletion->request($this->leaver);
        $this->deletion->anonymise($this->leaver->fresh());

        $this->expectException(RuntimeException::class);

        $this->deletion->cancel($this->leaver->fresh());
    }

    /**
     * You cannot vanish mid-handover. Somebody is waiting for a parcel or for
     * money at a counter, and an account that can evaporate during a deal is a
     * way to take the money and disappear from your own record.
     */
    public function test_an_open_deal_blocks_deletion(): void
    {
        $deal = $this->completedDeal();
        $deal->forceFill(['status' => DealStatus::Open->value, 'completed_at' => null])->save();

        $this->assertNotEmpty($this->deletion->blockers($this->leaver));

        $this->expectException(RuntimeException::class);

        $this->deletion->request($this->leaver);
    }

    // --- the export --------------------------------------------------------

    public function test_an_export_contains_their_data(): void
    {
        Listing::factory()->create([
            'user_id' => $this->leaver->id,
            'city_id' => City::first()->id,
            'title'   => 'MSI RTX 4070 Gaming X',
            'status'  => ListingStatus::Active,
        ]);

        $export = app(PersonalDataExport::class)->request($this->leaver);
        app(PersonalDataExport::class)->build($export);

        $export = $export->fresh();
        $this->assertTrue($export->isReady());

        $json = $this->readFromZip($export, 'data.json');

        $this->assertStringContainsString($this->leaver->username, $json);
        $this->assertStringContainsString('MSI RTX 4070 Gaming X', $json);
    }

    /**
     * AND NOT SOMEBODY ELSE'S. A subject access request is a copy of YOUR data;
     * it must not become a way to pull another person's address and phone
     * number out of the platform.
     */
    public function test_an_export_does_not_leak_the_counterparty(): void
    {
        $deal = $this->completedDeal();

        $deal->forceFill([
            'delivery_name'  => 'Другият Човек',
            'delivery_phone' => '0888999777',
            'delivery_set_at' => now(),
        ])->save();

        $export = app(PersonalDataExport::class)->request($this->other);
        app(PersonalDataExport::class)->build($export);

        $json = $this->readFromZip($export->fresh(), 'data.json');

        // The counterparty appears by username, because the deal is theirs too.
        $this->assertStringContainsString($this->leaver->username, $json);

        // Their email never does.
        $this->assertStringNotContainsString($this->leaver->email, $json);
    }

    /**
     * An export is somebody's entire account in one zip. A publicly served disk
     * for it is the single worst mistake this application could make, so the
     * service refuses rather than trusting configuration.
     */
    public function test_the_export_disk_may_not_be_public(): void
    {
        config(['remarket.privacy.export_disk' => 'public']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('publicly served');

        app(PersonalDataExport::class)->disk();
    }

    public function test_asking_twice_does_not_queue_twice(): void
    {
        Queue::fake();

        Livewire::actingAs($this->leaver)
            ->test(MyData::class)
            ->call('requestExport')
            ->call('requestExport');

        Queue::assertPushed(BuildDataExport::class, 1);
        $this->assertSame(1, DataExport::where('user_id', $this->leaver->id)->count());
    }

    public function test_an_expired_export_is_deleted_from_disk(): void
    {
        $export = app(PersonalDataExport::class)->request($this->leaver);
        app(PersonalDataExport::class)->build($export);

        $path = $export->fresh()->path;
        Storage::disk('local')->assertExists($path);

        DataExport::whereKey($export->id)->update(['expires_at' => now()->subDay()]);

        $this->artisan('remarket:purge-accounts')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(DataExport::EXPIRED, $export->fresh()->status);
        $this->assertNull($export->fresh()->path);
    }

    /** Somebody else's archive is a 404, uuid or no uuid. */
    public function test_you_cannot_download_another_persons_export(): void
    {
        $export = app(PersonalDataExport::class)->request($this->other);
        app(PersonalDataExport::class)->build($export);

        Livewire::actingAs($this->leaver)
            ->test(MyData::class)
            ->call('download', $export->uuid)
            ->assertNotFound();
    }

    // --- the screen --------------------------------------------------------

    public function test_the_screen_says_what_survives_before_anybody_confirms(): void
    {
        Livewire::actingAs($this->leaver)
            ->test(MyData::class)
            ->assertSee('Оценките и сделките остават')
            ->assertSee('Фактурите остават');
    }

    public function test_deleting_needs_the_username_typed(): void
    {
        Livewire::actingAs($this->leaver)
            ->test(MyData::class)
            ->call('startDelete')
            ->set('confirmUsername', 'not-my-username')
            ->call('confirmDelete')
            ->assertHasErrors('confirmUsername');

        $this->assertNull($this->leaver->fresh()->deletion_requested_at);
    }

    public function test_the_full_journey_through_the_screen(): void
    {
        Livewire::actingAs($this->leaver)
            ->test(MyData::class)
            ->call('startDelete')
            ->set('confirmUsername', $this->leaver->username)
            ->call('confirmDelete')
            ->assertHasNoErrors();

        $this->assertNotNull($this->leaver->fresh()->deletion_requested_at);

        Livewire::actingAs($this->leaver->fresh())
            ->test(MyData::class)
            ->call('undoDelete')
            ->assertHasNoErrors();

        $this->assertNull($this->leaver->fresh()->deletion_requested_at);
    }

    private function readFromZip(DataExport $export, string $entry): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rigo-test-').'.zip';
        file_put_contents($tmp, Storage::disk('local')->get($export->path));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'the export is not a readable zip');

        $contents = (string) $zip->getFromName($entry);
        $zip->close();
        @unlink($tmp);

        return $contents;
    }
}
