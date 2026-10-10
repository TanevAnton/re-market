<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\ModerationTrigger;
use App\Livewire\Auth\Register;
use App\Models\City;
use App\Models\Listing;
use App\Models\ModerationItem;
use App\Models\User;
use App\Services\Moderation\ListingScreener;
use App\Support\Profanity;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The word filter, and mostly the words it must NOT match.
 *
 * THE EXPENSIVE ERROR HERE IS THE FALSE POSITIVE, and it is not close. A slur
 * that reaches the queue late costs a moderator ten seconds. An honest seller
 * blocked for writing „курсор" is told nothing useful, retypes it, fails again
 * and leaves — and this site has two sellers.
 *
 * Bulgarian makes that easy to get wrong. „кур" sits inside курс, курсор,
 * курорт, конкурс, Меркурий and курсив; „гъз" inside гъзер. And the one that is
 * specific to THIS site: **педали are racing-sim pedals**, a real product
 * category, so a list that blocks the plural blocks Logitech and Fanatec
 * listings on a site that sells gaming gear.
 *
 * So most of this file is clean text that must survive, and the handful of
 * flagging tests exist mainly to prove the filter is switched on at all — „a
 * checker that finds nothing has proved nothing".
 */
class ProfanityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(CitySeeder::class);

        // The compiled pattern is cached per process and config is rebuilt
        // between cases.
        Profanity::flush();
    }

    protected function tearDown(): void
    {
        Profanity::flush();

        parent::tearDown();
    }

    // --- what must never match -------------------------------------------

    /**
     * The attribute, not an `@dataProvider` docblock: PHPUnit 12 removed
     * annotation metadata entirely, and a provider it cannot see is a test
     * that runs with no arguments rather than one that fails loudly.
     */
    #[DataProvider('innocentText')]
    public function test_ordinary_bulgarian_is_not_profanity(string $text): void
    {
        $this->assertSame(
            [],
            Profanity::found($text),
            'Ordinary text must not be flagged: '.$text,
        );
    }

    public static function innocentText(): array
    {
        return [
            'курс'      => ['Продавам курс по програмиране, 10 урока'],
            'курсор'    => ['Мишката движи курсора плавно'],
            'конкурс'   => ['Спечелих я от конкурс'],
            'Меркурий'  => ['Ретро компютър Меркурий 3'],
            'курорт'    => ['Вземане от курорт Боровец'],
            'курсив'    => ['Текстът е в курсив'],
            'гъзер'     => ['Гъзер модел, но работи перфектно'],
            'обичам'    => ['Обичам тази видеокарта, жалко че я продавам'],
            'цигани'    => ['Цигани не ме интересуват, само сериозни купувачи'],
            'model nos' => ['RTX 4070 Super 12GB · i5-13400F · 32GB DDR5 6000'],
        ];
    }

    /**
     * The one that is specific to this site, and the reason the list is written
     * out word by word instead of as stems.
     */
    public function test_racing_sim_pedals_are_a_product_not_a_slur(): void
    {
        foreach ([
            'Волан Logitech G29 с педали',
            'Thrustmaster T300 RS + педали, като нови',
            'Продавам само педала за газ',
        ] as $text) {
            $this->assertSame([], Profanity::found($text), 'Must publish freely: '.$text);
        }
    }

    /**
     * The `never` list is a guard rail with teeth. An edit putting „педали"
     * back will look perfectly reasonable to whoever makes it next year.
     */
    public function test_a_word_cannot_be_held_and_never_at_once(): void
    {
        config(['profanity.hold' => ['педали'], 'profanity.never' => ['педали' => 'Racing-sim pedals.']]);
        Profanity::flush();

        $this->expectException(RuntimeException::class);

        Profanity::found('каквото и да е');
    }

    // --- what must match --------------------------------------------------

    public function test_a_listed_word_is_found_whatever_the_case(): void
    {
        $this->assertNotSame([], Profanity::found('КУРВА'));
        $this->assertNotSame([], Profanity::found('Тази карта е шибана работа'));
    }

    /**
     * Light obfuscation only. Determined evasion is not caught and is not
     * meant to be — the report button and the queue are the backstop.
     */
    public function test_light_obfuscation_is_undone(): void
    {
        $this->assertNotSame([], Profanity::found('кууурва'), 'repeated letters');
        $this->assertNotSame([], Profanity::found('kurv@'), '@ for a');
        $this->assertNotSame([], Profanity::found('к0пеле'), 'zero for о');
        $this->assertNotSame([], Profanity::found("шиба\u{200B}на"), 'zero-width joiner');
    }

    public function test_the_matched_word_is_reported_back(): void
    {
        // The moderator gets the word, not just „нецензурен език" — a four
        // hundred word description is not something to re-read for a verdict.
        $this->assertSame(['курва'], Profanity::found('абе ти си курва'));
    }

    public function test_the_filter_can_be_switched_off(): void
    {
        config(['profanity.enabled' => false]);

        $this->assertSame([], Profanity::found('курва'));
    }

    // --- the listing path -------------------------------------------------

    public function test_a_listing_with_a_slur_is_held_for_review_not_deleted(): void
    {
        $listing = Listing::factory()->create([
            'status'      => ListingStatus::Active,
            'title'       => 'Видеокарта RTX 3060',
            'description' => 'Абе вие сте мангали, цената е цената.',
        ]);

        app(ListingScreener::class)->screen($listing);

        $this->assertSame(
            ListingStatus::PendingReview,
            $listing->fresh()->status,
            'A flagged listing comes off the public site until somebody looks.',
        );

        $item = ModerationItem::where('subject_id', $listing->id)
            ->where('trigger', ModerationTrigger::Profanity)
            ->first();

        $this->assertNotNull($item, 'and it is queued rather than quietly removed');
        $this->assertSame(['мангали'], $item->context['description'] ?? null);
        $this->assertArrayNotHasKey('title', $item->context);
    }

    public function test_a_clean_listing_passes_screening(): void
    {
        $listing = Listing::factory()->create([
            'status'      => ListingStatus::Active,
            'title'       => 'Волан с педали, курс на еврото',
            'description' => 'Купен от курорт, с курсор и всичко останало.',
        ]);

        $flagged = app(ListingScreener::class)->screen($listing);

        $this->assertNotContains(ModerationTrigger::Profanity, $flagged);
        $this->assertSame(ListingStatus::Active, $listing->fresh()->status);
    }

    // --- the username path ------------------------------------------------

    /**
     * Refused outright, unlike a listing. A username is permanent and appears
     * on every listing and in every conversation, so there is nothing for a
     * moderator to weigh up.
     */
    public function test_a_profane_username_is_refused_at_registration(): void
    {
        Livewire::test(Register::class)
            ->set('username', 'pederast')
            ->set('email', 'nov@example.com')
            ->set('password', 'Parola-123456')
            ->set('password_confirmation', 'Parola-123456')
            ->set('city_id', City::first()->id)
            ->set('seller_type', 'private')
            ->set('terms', true)
            ->call('register')
            ->assertHasErrors('username');

        $this->assertDatabaseMissing('users', ['username' => 'pederast']);
    }

    /** And the ordinary case still gets through. */
    public function test_an_ordinary_username_registers(): void
    {
        Livewire::test(Register::class)
            ->set('username', 'kursor_bg')
            ->set('email', 'kursor@example.com')
            ->set('phone', '0888123456')
            ->set('password', 'Parola-123456')
            ->set('password_confirmation', 'Parola-123456')
            ->set('city_id', City::first()->id)
            ->set('seller_type', 'private')
            ->set('terms', true)
            ->call('register')
            ->assertHasNoErrors('username');

        $this->assertDatabaseHas('users', ['username' => 'kursor_bg']);
    }
}
