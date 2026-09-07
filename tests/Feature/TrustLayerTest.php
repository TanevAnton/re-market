<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\ModerationTrigger;
use App\Enums\RejectionReason;
use App\Enums\ReportReason;
use App\Livewire\Reports\ReportForm;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\ModerationItem;
use App\Models\Part;
use App\Models\Report;
use App\Models\User;
use App\Services\Moderation\ListingScreener;
use App\Services\Moderation\ModerationService;
use App\Services\Moderation\ReportService;
use App\Support\Turnstile;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The four layers that stand between a spammer and the public site: notice and
 * action, duplicate photographs, absurd prices, and a bot challenge.
 *
 * None of the automated ones decide anything. That is the property being
 * tested as much as the detection itself - a false positive must cost an honest
 * seller a wait, never their listing.
 */
class TrustLayerTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller = User::factory()->create();
        $this->admin  = User::factory()->create(['is_admin' => true]);
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create($attributes + [
            'user_id' => $this->seller->id,
            'status'  => ListingStatus::Active,
        ]);
    }

    // --- notice and action (Art. 16) --------------------------------------

    public function test_a_notice_is_acknowledged_and_reaches_the_queue(): void
    {
        $listing  = $this->listing();
        $reporter = User::factory()->create();

        $report = app(ReportService::class)->file(
            $listing, ReportReason::Scam, 'Иска плащане предварително по банков път.', $reporter,
        );

        // Art. 16(4): receipt confirmed without undue delay.
        $this->assertNotNull($report->acknowledged_at);

        $item = ModerationItem::queue()->firstOrFail();
        $this->assertSame(ModerationTrigger::Reported, $item->trigger);
        $this->assertStringContainsString('банков път', $item->context['reports'][0]['detail']);
    }

    /**
     * A notice must not require an account. The person most likely to recognise
     * a stolen photograph is the seller it was taken from, who has never heard
     * of this site.
     */
    public function test_a_guest_can_report_with_an_email(): void
    {
        $listing = $this->listing();

        Livewire::test(ReportForm::class, ['subject' => $listing])
            ->call('toggle')
            ->set('reason', ReportReason::Stolen->value)
            ->set('detail', 'Това е моята карта, открадната от офиса миналата седмица.')
            ->set('email', 'someone@example.com')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('sent', true);

        $this->assertDatabaseHas('reports', ['reporter_email' => 'someone@example.com']);
    }

    public function test_a_guest_without_an_email_is_refused(): void
    {
        $listing = $this->listing();

        // Art. 16(5) requires telling the notifier the outcome. With no address
        // there is nowhere to send it.
        Livewire::test(ReportForm::class, ['subject' => $listing])
            ->call('toggle')
            ->set('reason', ReportReason::Spam->value)
            ->set('detail', 'Публикувана е двадесет пъти подред.')
            ->call('submit')
            ->assertHasErrors('email');
    }

    public function test_a_one_word_notice_is_refused(): void
    {
        Livewire::test(ReportForm::class, ['subject' => $this->listing()])
            ->call('toggle')
            ->set('reason', ReportReason::Scam->value)
            ->set('detail', 'измама')
            ->set('email', 'a@example.com')
            ->call('submit')
            ->assertHasErrors('detail');
    }

    /**
     * Ten notices from one angry buyer must not become ten items in a queue
     * that a real problem is waiting in.
     */
    public function test_one_person_cannot_file_the_same_notice_twice(): void
    {
        $listing  = $this->listing();
        $reporter = User::factory()->create();
        $reports  = app(ReportService::class);

        $first  = $reports->file($listing, ReportReason::Spam, 'Дублирана обява, вече я видях.', $reporter);
        $second = $reports->file($listing, ReportReason::Scam, 'И освен това е измама.', $reporter);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Report::count());
    }

    public function test_several_people_reporting_one_listing_make_one_item_with_every_notice(): void
    {
        $listing = $this->listing();
        $reports = app(ReportService::class);

        foreach (range(1, 3) as $n) {
            $reports->file(
                $listing, ReportReason::Counterfeit,
                "Картата е преправена, номер {$n} от нас го е забелязал.",
                User::factory()->create(),
            );
        }

        $this->assertSame(1, ModerationItem::count());
        $this->assertCount(3, ModerationItem::first()->context['reports']);
    }

    public function test_the_reporter_is_told_the_outcome(): void
    {
        $listing  = $this->listing();
        $reporter = User::factory()->create();

        app(ReportService::class)->file(
            $listing, ReportReason::ContactInListing, 'В описанието има телефонен номер.', $reporter,
        );

        app(ModerationService::class)->reject(
            ModerationItem::queue()->firstOrFail(),
            $this->admin,
            RejectionReason::ContactInfo,
            'В описанието има телефонен номер и линк към друга платформа.',
        );

        $report = Report::firstOrFail();

        $this->assertSame('actioned', $report->status);
        $this->assertNotNull($report->resolved_at);
        $this->assertStringContainsString('телефонен номер', $report->statement_of_reasons);
    }

    public function test_a_notice_that_was_not_acted_on_still_gets_an_answer(): void
    {
        $listing = $this->listing(['status' => ListingStatus::PendingReview]);

        app(ReportService::class)->file(
            $listing, ReportReason::WrongCategory, 'Според мен е в грешна категория.',
            User::factory()->create(),
        );

        app(ModerationService::class)->approve(ModerationItem::queue()->firstOrFail(), $this->admin);

        $report = Report::firstOrFail();

        $this->assertSame('rejected', $report->status);
        $this->assertNotNull($report->statement_of_reasons);
    }

    public function test_rejecting_a_reported_user_restricts_the_account(): void
    {
        $target = User::factory()->create();

        app(ReportService::class)->file(
            $target, ReportReason::Scam, 'Взе парите и спря да отговаря.', User::factory()->create(),
        );

        app(ModerationService::class)->reject(
            ModerationItem::queue()->firstOrFail(),
            $this->admin,
            RejectionReason::Prohibited,
            'Три независими сигнала за взети пари без изпратена стока.',
        );

        $target = $target->fresh();

        $this->assertNotNull($target->banned_at);
        $this->assertStringContainsString('сигнала', $target->ban_reason);
    }

    /** Moderators banning moderators is a fight this code should not start. */
    public function test_a_moderator_account_cannot_be_banned_from_the_queue(): void
    {
        $other = User::factory()->create(['is_admin' => true]);

        app(ReportService::class)->file(
            $other, ReportReason::Offensive, 'Държи се обидно в съобщенията.', $this->seller,
        );

        $this->expectException(\RuntimeException::class);

        app(ModerationService::class)->reject(
            ModerationItem::queue()->firstOrFail(),
            $this->admin,
            RejectionReason::Other,
            'Сигнал за обидно поведение в съобщения.',
        );
    }

    // --- duplicate photographs --------------------------------------------

    private function imageOn(Listing $listing, int $phash): ListingImage
    {
        return ListingImage::create([
            'listing_id' => $listing->id,
            'path'       => 'listings/'.uniqid().'.jpg',
            'width'      => 1200,
            'height'     => 900,
            'bytes'      => 100000,
            'phash'      => $phash,
            'position'   => 0,
        ]);
    }

    public function test_a_reused_photo_pulls_the_listing_off_the_site_and_flags_it(): void
    {
        $original = $this->listing();
        $this->imageOn($original, 0b0101010101010101010101010101010101010101010101010101010101010101);

        $thief = $this->listing(['user_id' => User::factory()->create()->id]);
        // Two bits different - the same photograph after a re-encode.
        $this->imageOn($thief, 0b0101010101010101010101010101010101010101010101010101010101010110);

        $flagged = app(ListingScreener::class)->screen($thief);

        $this->assertContains(ModerationTrigger::PhashCollision, $flagged);

        // Off the public site until a person has looked - a queued listing that
        // stays visible makes the queue decorative.
        $this->assertSame(ListingStatus::PendingReview, $thief->fresh()->status);

        $context = ModerationItem::queue()->firstOrFail()->context;
        $this->assertSame($original->uuid, $context['matched_listing_uuid']);
        $this->assertFalse($context['same_seller']);
    }

    public function test_a_different_photo_is_left_alone(): void
    {
        $a = $this->listing();
        $this->imageOn($a, 0b0111111111111111111111111111111111111111111111111111111111111111);

        $b = $this->listing(['user_id' => User::factory()->create()->id]);
        $this->imageOn($b, 0b0000000000000000000000000000000000000000000000000000000000000000);

        $this->assertSame([], app(ListingScreener::class)->screen($b));
        $this->assertSame(ListingStatus::Active, $b->fresh()->status);
    }

    /**
     * A seller relisting their own card is not a scam, but the moderator still
     * needs to know which of the two it was.
     */
    public function test_the_same_seller_relisting_is_flagged_but_marked_as_such(): void
    {
        $first = $this->listing();
        $this->imageOn($first, 0b0011001100110011001100110011001100110011001100110011001100110011);

        $second = $this->listing();
        $this->imageOn($second, 0b0011001100110011001100110011001100110011001100110011001100110011);

        app(ListingScreener::class)->screen($second);

        $this->assertTrue(ModerationItem::queue()->firstOrFail()->context['same_seller']);
    }

    // --- price outliers ----------------------------------------------------

    private function peers(Part $part, int $cents, int $count): void
    {
        Listing::factory()->count($count)->create([
            'part_id'      => $part->id,
            'price_cents'  => $cents,
            'status'       => ListingStatus::Active,
            'published_at' => now()->subDays(5),
        ]);
    }

    public function test_a_bait_price_is_flagged(): void
    {
        $part = Part::firstOrFail();
        $this->peers($part, 100000, 6);

        // A tenth of what everyone else is asking.
        $bait = $this->listing(['part_id' => $part->id, 'price_cents' => 10000]);

        $this->assertContains(ModerationTrigger::PriceOutlier, app(ListingScreener::class)->screen($bait));
        $this->assertSame(ListingStatus::PendingReview, $bait->fresh()->status);
        $this->assertSame('too_cheap', ModerationItem::queue()->firstOrFail()->context['direction']);
    }

    public function test_an_extra_zero_is_flagged_too(): void
    {
        $part = Part::firstOrFail();
        $this->peers($part, 100000, 6);

        $fatFinger = $this->listing(['part_id' => $part->id, 'price_cents' => 1000000]);

        $this->assertContains(ModerationTrigger::PriceOutlier, app(ListingScreener::class)->screen($fatFinger));
        $this->assertSame('too_expensive', ModerationItem::queue()->firstOrFail()->context['direction']);
    }

    public function test_a_fair_price_is_left_alone(): void
    {
        $part = Part::firstOrFail();
        $this->peers($part, 100000, 6);

        $fair = $this->listing(['part_id' => $part->id, 'price_cents' => 92000]);

        $this->assertSame([], app(ListingScreener::class)->screen($fair));
        $this->assertSame(ListingStatus::Active, $fair->fresh()->status);
    }

    /**
     * A median over two listings is not a median. Flagging on a thin sample
     * would flag every third listing of a new catalogue part and train the
     * moderator to approve without looking - worse than not checking at all.
     */
    public function test_too_thin_a_sample_flags_nothing(): void
    {
        $part = Part::firstOrFail();
        $this->peers($part, 100000, 2);

        $cheap = $this->listing(['part_id' => $part->id, 'price_cents' => 5000]);

        $this->assertSame([], app(ListingScreener::class)->screen($cheap));
    }

    public function test_an_uncatalogued_listing_has_nothing_to_compare_against(): void
    {
        $orphan = $this->listing(['part_id' => null, 'price_cents' => 100]);

        $this->assertSame([], app(ListingScreener::class)->screen($orphan));
    }

    // --- Turnstile ---------------------------------------------------------

    public function test_turnstile_is_skipped_when_no_keys_are_configured(): void
    {
        config(['remarket.turnstile.site_key' => null, 'remarket.turnstile.secret_key' => null]);

        // Local development and the LAN box have no keys, and a challenge that
        // cannot be solved there would block the flows most in need of testing.
        $this->assertFalse(Turnstile::enabled());
        $this->assertTrue(Turnstile::verify(null));
    }

    public function test_a_missing_token_is_refused_when_turnstile_is_on(): void
    {
        config(['remarket.turnstile.site_key' => 'site', 'remarket.turnstile.secret_key' => 'secret']);

        $this->assertFalse(Turnstile::verify(null));
        $this->assertFalse(Turnstile::verify(''));
    }

    public function test_a_rejected_token_blocks_the_action(): void
    {
        config(['remarket.turnstile.site_key' => 'site', 'remarket.turnstile.secret_key' => 'secret']);

        Http::fake(['*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        Livewire::test(ReportForm::class, ['subject' => $this->listing()])
            ->call('toggle')
            ->set('reason', ReportReason::Scam->value)
            ->set('detail', 'Иска плащане предварително по банков път.')
            ->set('email', 'a@example.com')
            ->set('turnstileToken', 'whatever')
            ->call('submit')
            ->assertHasErrors('turnstile');

        $this->assertSame(0, Report::count());
    }

    /**
     * Failing open is deliberate. If Cloudflare is unreachable, refusing every
     * signup and every offer turns their outage into ours, and phone
     * verification, the queue and the rate limits are all still standing.
     */
    public function test_an_unreachable_cloudflare_lets_the_action_through(): void
    {
        config(['remarket.turnstile.site_key' => 'site', 'remarket.turnstile.secret_key' => 'secret']);

        Http::fake(fn () => throw new \RuntimeException('network is down'));

        $this->assertTrue(Turnstile::verify('token'));
    }
}
