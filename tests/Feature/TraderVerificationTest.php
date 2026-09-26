<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\SellerType;
use App\Livewire\Profile\EditProfile;
use App\Livewire\Traders\TraderQueue;
use App\Models\City;
use App\Models\Listing;
use App\Models\User;
use App\Notifications\TraderDecision;
use App\Services\Traders\TraderVerification;
use Database\Seeders\CitySeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * „Проверена фирма".
 *
 * THE WHOLE FILE IS ABOUT ONE DISTINCTION, and if it ever collapses the feature
 * becomes a lie the site tells buyers:
 *
 *   A DECLARATION is `users.seller_type`. Every seller makes one, it is legally
 *   required (ЗЗП / Omnibus Art. 6a), it drives the consumer notice on every
 *   listing page, and NOBODY CHECKS IT. It has shipped since the beginning.
 *
 *   A VERIFICATION is these four columns. It is optional, a person opened the
 *   Commercial Register, and it says only that the company exists with that ЕИК
 *   at that address.
 *
 * So the tests that matter are not „does the badge appear". They are: the badge
 * does NOT appear for a seller who merely said they are a company; the seller
 * cannot give it to themselves (the columns are not fillable, and there is a
 * test that tries); a moderator cannot verify their own company; and the badge
 * leaves the site the moment the declaration it rests on does.
 *
 * And one more, inherited from the serial-number screen: a refusal has to be a
 * sentence the applicant can act on. „Не мина" is a support ticket.
 */
class TraderVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const DETAILS = [
        'company' => 'РИГО ТЕСТ ЕООД',
        'uic'     => '831915840',
        'vat'     => '',
        'address' => 'гр. София, ул. Тестова 1',
    ];

    private User $trader;
    private User $admin;
    private TraderVerification $traders;

    protected function setUp(): void
    {
        parent::setUp();

        // The listing and profile pages both render image URLs.
        Storage::fake('public');

        $this->seed(CitySeeder::class);

        $this->trader  = $this->seller(SellerType::Trader, self::DETAILS);
        $this->admin   = $this->seller(SellerType::Private, null, ['is_admin' => true]);
        $this->traders = app(TraderVerification::class);
    }

    private function seller(SellerType $type, ?array $details = null, array $attributes = []): User
    {
        /*
         * ->refresh(), and it is not decoration.
         *
         * A column default is applied by the INSERT and NOT read back into the
         * model that issued it, so a just-created user has null where the row
         * has 'none' and 'bg'. That made this file fail four ways at once: an
         * assertion on trader_status, and three renders of the settings screen
         * dying on `Cannot assign null to property EditProfile::$locale of type
         * string`. Neither was the feature — both were this helper handing out a
         * model that did not match its own row.
         *
         * User::$attributes now defaults both columns as well, so the same
         * mistake elsewhere is a wrong value rather than a TypeError. This stays
         * regardless: a test should assert against the row.
         */
        return User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
            'seller_type'       => $type,
            'trader_details'    => $details,
            ...$attributes,
        ])->refresh();
    }

    private function listingFor(User $owner): Listing
    {
        return Listing::factory()->create([
            'user_id'  => $owner->id,
            'city_id'  => City::first()->id,
            'category' => 'gpu',
            'status'   => ListingStatus::Active,
        ]);
    }

    /** Straight to verified, for the tests that are about what buyers see. */
    private function verified(): User
    {
        $this->traders->apply($this->trader);

        return $this->traders->verify($this->trader, $this->admin, 'ЕИК и наименование съвпадат');
    }

    // --- the distinction --------------------------------------------------

    /**
     * THE TEST THIS FILE EXISTS FOR.
     *
     * A seller who ticked „търговец" has made the legally meaningful statement
     * and it is on their listings. They have NOT been checked, and nothing on
     * the site may suggest otherwise.
     */
    public function test_a_declaration_is_not_a_verification(): void
    {
        $this->assertTrue($this->trader->isTrader());
        $this->assertFalse($this->trader->isVerifiedTrader());
        $this->assertNull($this->trader->verifiedCompany());
        $this->assertSame(User::TRADER_NONE, $this->trader->trader_status);

        $this->get(route('listing', $this->listingFor($this->trader)))
            ->assertOk()
            // The obligation is there …
            ->assertSee($this->trader->seller_type->consumerNotice())
            // … and the claim nobody has checked is not.
            ->assertDontSee('Проверена фирма');
    }

    /**
     * THE SECURITY TEST. The four columns are not in $fillable, so the settings
     * form — or anything else that ever mass-assigns from request data — cannot
     * reach them. A badge a seller can give themselves is worse than no badge,
     * because buyers would have been told it meant something.
     */
    public function test_a_seller_cannot_give_themselves_the_badge(): void
    {
        try {
            $this->trader->update([
                'trader_status'      => User::TRADER_VERIFIED,
                'trader_verified_at' => now(),
            ]);
        } catch (MassAssignmentException) {
            /*
             * Outside production AppServiceProvider turns on
             * preventSilentlyDiscardingAttributes, so the write shouts instead
             * of vanishing; in production the same write is discarded quietly.
             * Both are fine and neither is what is being tested — the assertion
             * below is: the column was not written either way.
             */
        }

        $this->assertSame(User::TRADER_NONE, $this->trader->fresh()->trader_status);
        $this->assertFalse($this->trader->fresh()->isVerifiedTrader());
    }

    /**
     * And the badge rests on the declaration rather than standing beside it: a
     * verified company that switches back to „частно лице" stops wearing it
     * immediately, without a moderator having to notice.
     */
    public function test_going_back_to_private_takes_the_badge_off_the_site(): void
    {
        $user = $this->verified();

        $this->assertTrue($user->isVerifiedTrader());

        $user->update(['seller_type' => SellerType::Private, 'trader_details' => null]);

        $this->assertFalse($user->fresh()->isVerifiedTrader());
        $this->assertNull($user->fresh()->verifiedCompany());

        $this->get(route('listing', $this->listingFor($user->fresh())))
            ->assertOk()
            ->assertDontSee('Проверена фирма');
    }

    // --- applying ---------------------------------------------------------

    public function test_only_a_trader_can_ask_to_be_checked(): void
    {
        $private = $this->seller(SellerType::Private);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('продава като търговец');

        $this->traders->apply($private);
    }

    /**
     * An application a moderator cannot act on is worse than none: it sits in
     * the queue looking like work. Traders who declared themselves before those
     * fields were required still have blanks in them.
     */
    public function test_an_application_needs_what_a_moderator_would_look_up(): void
    {
        $blank = $this->seller(SellerType::Trader, ['company' => 'РИГО ТЕСТ ЕООД', 'uic' => '', 'address' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ЕИК');

        $this->traders->apply($blank);
    }

    /** They clicked twice, or came back to look. Not an error. */
    public function test_applying_twice_is_not_an_error(): void
    {
        $this->traders->apply($this->trader);
        $this->traders->apply($this->trader);

        $this->assertSame(User::TRADER_PENDING, $this->trader->fresh()->trader_status);
    }

    public function test_an_already_verified_company_is_not_queued_again(): void
    {
        $this->verified();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('вече е проверена');

        $this->traders->apply($this->trader->fresh());
    }

    // --- deciding ---------------------------------------------------------

    public function test_verifying_records_who_checked_it_and_tells_the_seller(): void
    {
        Notification::fake();

        $this->traders->apply($this->trader);
        $user = $this->traders->verify($this->trader, $this->admin, 'ЕИК и наименование съвпадат');

        $this->assertTrue($user->isVerifiedTrader());
        $this->assertSame('РИГО ТЕСТ ЕООД', $user->verifiedCompany());
        $this->assertSame($this->admin->id, $user->trader_verified_by);
        $this->assertNotNull($user->trader_verified_at);

        Notification::assertSentTo($this->trader, TraderDecision::class);
    }

    /** A check that reviews itself is not a check. Same rule as moderation. */
    public function test_a_moderator_cannot_verify_their_own_company(): void
    {
        $moderator = $this->seller(SellerType::Trader, self::DETAILS, ['is_admin' => true]);

        $this->traders->apply($moderator);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('собствената си фирма');

        $this->traders->verify($moderator, $moderator);
    }

    /**
     * „Не мина" teaches the applicant nothing and produces a support ticket.
     * „ЕИК-то е на друго дружество" is something they can correct or dispute.
     */
    public function test_a_refusal_needs_a_sentence_the_applicant_can_act_on(): void
    {
        $this->traders->apply($this->trader);

        try {
            $this->traders->reject($this->trader, $this->admin, 'не');
            $this->fail('a one-word refusal was accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('продавачът го вижда', $e->getMessage());
        }

        $this->assertSame(User::TRADER_PENDING, $this->trader->fresh()->trader_status);
    }

    public function test_the_refusal_reason_reaches_the_seller(): void
    {
        Notification::fake();

        $this->traders->apply($this->trader);
        $this->traders->reject($this->trader, $this->admin, 'ЕИК 831915840 е на друго дружество.');

        $this->assertSame(User::TRADER_REJECTED, $this->trader->fresh()->trader_status);
        $this->assertFalse($this->trader->fresh()->isVerifiedTrader());

        Notification::assertSentTo(
            $this->trader,
            TraderDecision::class,
            fn (TraderDecision $n) => in_array(
                'ЕИК 831915840 е на друго дружество.',
                $n->lines($this->trader),
                true,
            ),
        );
    }

    /**
     * The queue must never show a moderator a note about a submission that has
     * since changed — they would check the old complaint against new details.
     */
    public function test_re_applying_clears_the_previous_refusal(): void
    {
        $this->traders->apply($this->trader);
        $this->traders->reject($this->trader, $this->admin, 'Адресът не е седалището по регистър.');

        $this->traders->apply($this->trader->fresh());

        $fresh = $this->trader->fresh();
        $this->assertSame(User::TRADER_PENDING, $fresh->trader_status);
        $this->assertNull($fresh->trader_note);
    }

    /**
     * A verification is a statement about a present fact, so it has to be
     * retractable: a company struck from the register, or an account that
     * changed hands, must stop wearing the badge.
     */
    public function test_a_badge_can_be_taken_back(): void
    {
        $user = $this->verified();

        $this->traders->revoke($user, $this->admin, 'Дружеството е заличено от регистъра.');

        $this->assertFalse($user->fresh()->isVerifiedTrader());
        $this->assertNull($user->fresh()->trader_verified_at);
        $this->assertStringContainsString('заличено', $user->fresh()->trader_note);
    }

    public function test_a_decision_needs_a_pending_request(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Няма чакаща заявка');

        $this->traders->verify($this->trader, $this->admin);
    }

    // --- what the buyer sees ----------------------------------------------

    /**
     * Below the consumer notice and never instead of it, with the limit of the
     * claim spelled out — „проверена фирма" left alone gets read as „safe
     * deal", and nobody checked that.
     */
    public function test_the_listing_page_names_the_company_and_the_limit(): void
    {
        $user = $this->verified();

        $this->get(route('listing', $this->listingFor($user)))
            ->assertOk()
            ->assertSee($user->seller_type->consumerNotice())
            ->assertSee('Проверена фирма')
            ->assertSee('РИГО ТЕСТ ЕООД')
            ->assertSee('Това не е гаранция за обявата или за сделката.');
    }

    /** Two badges, not one green chip: what they said, and what we checked. */
    public function test_the_profile_carries_both_claims_separately(): void
    {
        $user = $this->verified();

        $this->get(route('profile', $user->username))
            ->assertOk()
            ->assertSee($user->seller_type->label())
            ->assertSee('фирмата е проверена')
            ->assertSee('Проверено в Търговския регистър');
    }

    public function test_an_unverified_traders_profile_claims_nothing(): void
    {
        $this->get(route('profile', $this->trader->username))
            ->assertOk()
            ->assertSee($this->trader->seller_type->label())
            ->assertDontSee('фирмата е проверена');
    }

    // --- the screens ------------------------------------------------------

    public function test_a_trader_can_ask_from_their_settings(): void
    {
        Livewire::actingAs($this->trader)
            ->test(EditProfile::class)
            ->assertSee('Заяви проверка')
            ->call('requestVerification')
            ->assertHasNoErrors()
            ->assertSee('Чака проверка');

        $this->assertSame(User::TRADER_PENDING, $this->trader->fresh()->trader_status);
    }

    /** Nothing to verify, so nothing is offered — not a disabled button. */
    public function test_a_private_seller_is_not_offered_verification(): void
    {
        Livewire::actingAs($this->seller(SellerType::Private))
            ->test(EditProfile::class)
            ->assertDontSee('Заяви проверка');
    }

    /** A refusal the seller can act on is a message, not a 500. */
    public function test_the_settings_screen_shows_why_it_was_refused(): void
    {
        $blank = $this->seller(SellerType::Trader, ['company' => 'X', 'uic' => '', 'address' => '']);

        Livewire::actingAs($blank)
            ->test(EditProfile::class)
            ->call('requestVerification')
            ->assertHasErrors('verification');

        $this->assertSame(User::TRADER_NONE, $blank->fresh()->trader_status);
    }

    /** 404 rather than 403: a 403 confirms the URL is real and worth attacking. */
    public function test_the_queue_is_admin_only(): void
    {
        $this->actingAs($this->trader)->get(route('traders'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('traders'))->assertOk();
    }

    public function test_the_queue_shows_the_eik_to_compare_and_decides(): void
    {
        Notification::fake();

        $this->traders->apply($this->trader);

        Livewire::actingAs($this->admin)
            ->test(TraderQueue::class)
            ->assertSee('831915840')
            ->assertSee('РИГО ТЕСТ ЕООД')
            ->call('open', $this->trader->id)
            ->set('note', 'ЕИК и наименование съвпадат с регистъра')
            ->call('verify', $this->trader->id)
            ->assertHasNoErrors()
            ->assertSet('deciding', null);

        $this->assertTrue($this->trader->fresh()->isVerifiedTrader());
    }

    /**
     * The service refuses a bare refusal; the screen has to SAY so rather than
     * swallowing it, or the moderator clicks again and assumes it worked.
     */
    public function test_the_queue_puts_the_refusal_on_the_screen(): void
    {
        $this->traders->apply($this->trader);

        Livewire::actingAs($this->admin)
            ->test(TraderQueue::class)
            ->call('open', $this->trader->id)
            ->set('note', 'не')
            ->call('reject', $this->trader->id)
            ->assertHasErrors('note');

        $this->assertSame(User::TRADER_PENDING, $this->trader->fresh()->trader_status);
    }

    /** Oldest first: the person who has waited longest has given up on us. */
    public function test_the_pending_tab_holds_only_pending_applications(): void
    {
        $waiting = $this->seller(SellerType::Trader, [...self::DETAILS, 'company' => 'ЧАКАЩА ЕООД']);

        $this->traders->apply($waiting);
        $this->verified();

        Livewire::actingAs($this->admin)
            ->test(TraderQueue::class)
            ->assertSee('ЧАКАЩА ЕООД')
            ->assertDontSee('РИГО ТЕСТ ЕООД')
            ->set('tab', 'verified')
            ->assertSee('РИГО ТЕСТ ЕООД')
            ->assertDontSee('ЧАКАЩА ЕООД');
    }
}
