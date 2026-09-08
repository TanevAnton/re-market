<?php

namespace App\Services\Messaging;

use App\Models\Listing;
use App\Models\Message;
use App\Models\Thread;
use App\Notifications\NewMessage;
use App\Models\User;
use App\Support\ContactScrubber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Conversations between a buyer and a seller about one listing.
 *
 * One thread per (listing, buyer) - enforced by a unique index, not by hoping
 * the application remembers. A seller talking to nine interested buyers has
 * nine threads; a buyer who comes back a week later returns to the same one.
 */
class ThreadService
{
    /** Seconds between messages from one person. */
    private const COOLDOWN = 3;

    /**
     * Find or open the thread between this buyer and the listing's seller.
     */
    public function open(Listing $listing, User $buyer): Thread
    {
        if ($listing->user_id === $buyer->id) {
            throw MessagingException::ownListing();
        }

        if (! $listing->status->isPubliclyVisible()) {
            throw MessagingException::unavailable();
        }

        // firstOrCreate rather than a check-then-insert: two taps on a slow
        // connection would otherwise race and one of them would hit the unique
        // index as a 500.
        return Thread::firstOrCreate(
            ['listing_id' => $listing->id, 'buyer_id' => $buyer->id],
            ['seller_id' => $listing->user_id],
        );
    }

    /**
     * Post a message, scrubbed unless these two have already agreed a deal.
     */
    public function send(Thread $thread, User $sender, string $body): Message
    {
        $this->assertParty($thread, $sender);

        /*
         * The account gate while SMS is off. In the service rather than the
         * component, so the rule holds wherever a message is sent from - and
         * before the rate limiter is hit, so an unverified account does not
         * burn its own cooldown on a message that was never going to send.
         */
        if (! $sender->hasVerifiedEmail()) {
            throw MessagingException::emailUnverified();
        }

        if ($thread->is_locked) {
            throw MessagingException::locked();
        }

        $key = "msg:{$thread->id}:{$sender->id}";

        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw MessagingException::tooFast(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::COOLDOWN);

        $scrubbed = ContactScrubber::scrub($body, $thread->allowsContactExchange());

        /*
         * Read BEFORE the write, because the write moves the timestamps this
         * question depends on.
         *
         * One notification per unread streak, not one per message: if the
         * recipient already has something unread in this thread they have been
         * told, and ten messages in a row should not be ten pings. They get the
         * next one after they have actually read.
         */
        $alreadyPending = $this->hasUnreadFor($thread, $sender);

        $message = DB::transaction(function () use ($thread, $sender, $body, $scrubbed) {
            $message = Message::create([
                'thread_id'        => $thread->id,
                'sender_id'        => $sender->id,
                'body'             => $body,
                'body_clean'       => $scrubbed['clean'],
                'had_contact_info' => $scrubbed['had_contact_info'],
                'had_price_talk'   => $scrubbed['had_price_talk'],
            ]);

            // The sender has by definition read their own message, so their
            // own read marker moves too - otherwise every thread you write in
            // immediately shows as having something unread in it.
            $ownRead = $sender->id === $thread->buyer_id ? 'buyer_read_at' : 'seller_read_at';

            $thread->forceFill([
                'last_message_at' => now(),
                $ownRead          => now(),
            ])->save();

            return $message;
        });

        if (! $alreadyPending) {
            $recipient = $sender->id === $thread->buyer_id ? $thread->seller : $thread->buyer;
            $recipient->notify(new NewMessage($thread, $sender->username));
        }

        return $message;
    }

    /**
     * Does the OTHER party already have something unread here?
     *
     * Nothing sent yet counts as nothing unread, so the first message in a
     * thread always notifies.
     */
    private function hasUnreadFor(Thread $thread, User $sender): bool
    {
        $isBuyer   = $sender->id === $thread->buyer_id;
        $theirRead = $isBuyer ? $thread->seller_read_at : $thread->buyer_read_at;

        if ($thread->last_message_at === null) {
            return false;
        }

        return $theirRead === null || $theirRead->lessThan($thread->last_message_at);
    }

    public function markRead(Thread $thread, User $reader): void
    {
        $this->assertParty($thread, $reader);

        $column = $reader->id === $thread->buyer_id ? 'buyer_read_at' : 'seller_read_at';

        $thread->forceFill([$column => now()])->save();
    }

    private function assertParty(Thread $thread, User $user): void
    {
        if ($thread->buyer_id !== $user->id && $thread->seller_id !== $user->id) {
            throw MessagingException::notYours();
        }
    }
}
