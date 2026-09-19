<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\City;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use App\Support\ReplySpeed;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * „Обикновено отговаря до 2 часа."
 *
 * Nothing new is stored: a thread records who the seller is and every message
 * records who sent it and when, so this reads what messaging has been writing
 * all along.
 *
 * THE TEST THAT MATTERS IS THE SURVIVORSHIP ONE. Response time can only be
 * measured on threads that GOT a response, so a seller who answers one message
 * in five — fast — scores better than one who answers all five within a day.
 * That is exactly backwards, and it is the kind of statistic that ships because
 * the query looks right. The badge is withheld below an answered rate for
 * precisely that reason, and `test_a_seller_who_ignores_most_messages_gets_no_badge`
 * is the reason the rate exists.
 */
class ReplySpeedTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        Cache::flush();

        $this->seller  = User::factory()->create(['city_id' => City::first()->id]);
        $this->listing = Listing::factory()->create([
            'user_id' => $this->seller->id,
            'city_id' => City::first()->id,
            'status'  => ListingStatus::Active,
        ]);
    }

    /**
     * One conversation: a buyer asks, and the seller either answers after
     * `$afterSeconds` or never.
     */
    private function conversation(?int $afterSeconds, int $daysAgo = 5): Thread
    {
        $asked = now()->subDays($daysAgo);

        $thread = Thread::create([
            'listing_id' => $this->listing->id,
            'buyer_id'   => User::factory()->create()->id,
            'seller_id'  => $this->seller->id,
        ]);

        // created_at is not fillable on either model here, and it is the whole
        // measurement - so it is set directly rather than hoped for.
        $thread->forceFill(['created_at' => $asked])->save();

        Message::create([
            'thread_id'  => $thread->id,
            'sender_id'  => $thread->buyer_id,
            'body'       => 'Здравей, още ли е налична?',
            'body_clean' => 'Здравей, още ли е налична?',
        ])->forceFill(['created_at' => $asked])->save();

        if ($afterSeconds !== null) {
            Message::create([
                'thread_id'  => $thread->id,
                'sender_id'  => $this->seller->id,
                'body'       => 'Да, налична е.',
                'body_clean' => 'Да, налична е.',
            ])->forceFill(['created_at' => $asked->copy()->addSeconds($afterSeconds)])->save();
        }

        return $thread;
    }

    // --- the sample floors ------------------------------------------------

    public function test_one_fast_reply_is_an_anecdote_not_a_habit(): void
    {
        $this->conversation(600);

        $this->assertNull(ReplySpeed::forSeller($this->seller),
            'a badge was shown on the strength of a single conversation');
    }

    public function test_three_fast_replies_are_enough(): void
    {
        foreach ([600, 900, 1200] as $seconds) {
            $this->conversation($seconds);
        }

        $speed = ReplySpeed::forSeller($this->seller);

        $this->assertNotNull($speed);
        $this->assertSame(900, $speed['seconds'], 'the median of 600/900/1200 is 900');
        $this->assertSame(3, $speed['answered']);
    }

    /** The median, not the mean — one holiday must not decide the number. */
    public function test_a_single_slow_reply_does_not_move_the_median(): void
    {
        foreach ([300, 600, 900, 1200] as $seconds) {
            $this->conversation($seconds);
        }

        $this->conversation(20 * 3600);

        $speed = ReplySpeed::forSeller($this->seller);

        // Five gaps: 300, 600, 900, 1200, 72000 -> median 900. A mean would be
        // over four hours and would describe nobody.
        $this->assertSame(900, $speed['seconds']);
    }

    // --- the survivorship guard, which is the point -----------------------

    /**
     * Answering one in five, quickly, must not beat answering five in five.
     *
     * Without the answered-rate floor this seller scores „до 15 минути" while
     * ignoring 80% of the people who wrote to them.
     */
    public function test_a_seller_who_ignores_most_messages_gets_no_badge(): void
    {
        $this->conversation(300);
        $this->conversation(420);
        $this->conversation(600);

        foreach (range(1, 9) as $i) {
            $this->conversation(null);
        }

        $this->assertNull(ReplySpeed::forSeller($this->seller),
            'a seller who answered 3 of 12 threads was advertised as fast');
    }

    public function test_answering_most_of_them_still_counts(): void
    {
        foreach ([300, 600, 900, 1200] as $seconds) {
            $this->conversation($seconds);
        }

        $this->conversation(null);

        $this->assertNotNull(ReplySpeed::forSeller($this->seller),
            '4 answered out of 5 is above the rate floor and should still qualify');
    }

    // --- one-sided, like the deal badge -----------------------------------

    public function test_a_slow_seller_is_not_labelled_slow(): void
    {
        foreach ([50 * 3600, 60 * 3600, 70 * 3600] as $seconds) {
            $this->conversation($seconds);
        }

        $this->assertNull(ReplySpeed::forSeller($this->seller),
            'the site published „отговаря до 3 дни", which is a punishment rather than information');
    }

    /** Old conversations are not evidence about this month. */
    public function test_conversations_outside_the_window_are_ignored(): void
    {
        foreach ([600, 900, 1200] as $seconds) {
            $this->conversation($seconds, daysAgo: 200);
        }

        $this->assertNull(ReplySpeed::forSeller($this->seller));
    }

    // --- the wording ------------------------------------------------------

    /**
     * Rounded UP and coarse. „до 47 минути" is a promise nobody made and
     * invites a buyer to time it; an upper bound is a claim a seller can keep.
     */
    public function test_the_label_rounds_up_and_stays_vague(): void
    {
        $this->assertSame('до 15 минути', ReplySpeed::label(60));
        $this->assertSame('до 15 минути', ReplySpeed::label(900));
        $this->assertSame('до час', ReplySpeed::label(3600));
        $this->assertSame('до 2 часа', ReplySpeed::label(3601));
        $this->assertSame('до 5 часа', ReplySpeed::label(4 * 3600 + 1));
        $this->assertSame('до 1 ден', ReplySpeed::label(86400));
        $this->assertSame('до 2 дни', ReplySpeed::label(86401));
    }

    // --- where it shows ---------------------------------------------------

    public function test_the_listing_page_shows_it(): void
    {
        foreach ([600, 900, 1200] as $seconds) {
            $this->conversation($seconds);
        }

        $this->get(route('listing', $this->listing))
            ->assertOk()
            ->assertSee('Обикновено отговаря')
            ->assertSee('до 15 минути');
    }

    public function test_the_listing_page_says_nothing_when_there_is_nothing_to_say(): void
    {
        $this->get(route('listing', $this->listing))
            ->assertOk()
            ->assertDontSee('Обикновено отговаря');
    }

    public function test_the_profile_shows_it_too(): void
    {
        foreach ([600, 900, 1200] as $seconds) {
            $this->conversation($seconds);
        }

        $this->get(route('profile', $this->seller->username))
            ->assertOk()
            ->assertSee('Обикновено отговаря');
    }

    /**
     * Cached, and what goes in the cache is three integers.
     *
     * An Eloquent model in a cache entry is a page that works exactly once, and
     * this codebase has already paid for that lesson once this week.
     */
    public function test_what_gets_cached_survives_being_serialised(): void
    {
        foreach ([600, 900, 1200] as $seconds) {
            $this->conversation($seconds);
        }

        $speed = ReplySpeed::forSeller($this->seller);

        $this->assertSame($speed, unserialize(serialize($speed)));

        foreach ($speed as $key => $value) {
            $this->assertIsInt($value, "[{$key}] is not an integer");
        }
    }
}
