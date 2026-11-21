<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bottom bar a phone gets.
 *
 * Until it existed a phone had two menus behind taps at the top of a tall
 * screen, and the two things people do most - look, and sell - were both out of
 * a thumb's reach. These tests pin the things that quietly break it: it is
 * hidden on desktop, it sits outside the header (whose tests count badges),
 * it sends each kind of visitor to the right place, and it never grows a
 * numeric badge of its own.
 */
class MobileNavTest extends TestCase
{
    use RefreshDatabase;

    /** Only the bottom bar's markup. */
    private function bar(?User $as = null): string
    {
        $request = $as ? $this->actingAs($as) : $this;
        $html    = $request->get(route('home'))->assertOk()->getContent();

        $start = strpos($html, '<nav class="fixed inset-x-0 bottom-0');
        $this->assertNotFalse($start, 'the page rendered no bottom bar');

        $end = strpos($html, '</nav>', $start);

        return substr($html, $start, $end - $start);
    }

    public function test_it_is_phone_only(): void
    {
        $this->assertStringContainsString('md:hidden', $this->bar());
    }

    public function test_it_is_outside_the_header(): void
    {
        $html = $this->get(route('home'))->getContent();

        $this->assertGreaterThan(
            strpos($html, '</header>'),
            strpos($html, 'inset-x-0 bottom-0'),
            'the bottom bar is inside the header, so it would double HeaderTest\'s counts',
        );
    }

    public function test_a_guest_is_sent_to_register_to_sell(): void
    {
        $bar = $this->bar();

        $this->assertStringContainsString('href="'.route('register').'"', $bar);
        $this->assertStringContainsString('href="'.route('browse').'"', $bar);
        $this->assertStringContainsString('href="'.route('login').'"', $bar);
        $this->assertStringNotContainsString('href="'.route('messages').'"', $bar);
    }

    public function test_a_member_is_sent_to_the_listing_form_to_sell(): void
    {
        $user = User::factory()->create();
        $bar  = $this->bar($user);

        $this->assertStringContainsString('href="'.route('listing.create').'"', $bar);
        $this->assertStringContainsString('href="'.route('messages').'"', $bar);
        $this->assertStringContainsString('href="'.route('profile', $user->username).'"', $bar);
        $this->assertStringNotContainsString('href="'.route('register').'"', $bar);
    }

    public function test_the_current_page_is_marked(): void
    {
        $this->assertStringContainsString('aria-current="page"', $this->bar());
    }

    /** The header owns the numbers; this bar is allowed a dot and nothing more. */
    public function test_it_never_carries_a_count_badge(): void
    {
        $this->assertStringNotContainsString('badge-accent', $this->bar(User::factory()->create()));
    }
}
