<?php

namespace App\Livewire\Messages;

use App\Enums\OfferStatus;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\Thread;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\ThreadService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShowThread extends Component
{
    #[Locked]
    public Thread $thread;

    public string $body = '';

    /**
     * Reached two ways: by thread uuid from the inbox, or by listing uuid from
     * the "message the seller" button, which opens the thread the first time.
     *
     * The service comes first in the signature because a required parameter
     * after an optional one is deprecated in PHP 8; Livewire resolves it by
     * type regardless of position.
     */
    public function mount(ThreadService $threads, ?Thread $thread = null, ?Listing $listing = null): void
    {
        if ($thread === null) {
            abort_unless($listing !== null, 404);

            try {
                $thread = $threads->open($listing, auth()->user());
            } catch (MessagingException $e) {
                abort(403, $e->getMessage());
            }

            // Redirect so the address bar shows the thread rather than the
            // listing. Without the return, the rest of mount would run against
            // a page that is already on its way somewhere else.
            $this->redirectRoute('thread', $thread, navigate: true);

            $this->thread = $thread;

            return;
        }

        abort_unless(
            $thread->buyer_id === auth()->id() || $thread->seller_id === auth()->id(),
            404,
        );

        $this->thread = $thread;

        $threads->markRead($thread, auth()->user());
    }

    public function send(ThreadService $threads): void
    {
        $data = $this->validate([
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ], [
            'body.required' => 'Напиши нещо.',
            'body.max'      => 'Съобщението е твърде дълго.',
        ]);

        try {
            $threads->send($this->thread, auth()->user(), trim($data['body']));
        } catch (MessagingException $e) {
            $this->addError('body', $e->getMessage());

            return;
        }

        $this->reset('body');

        // The pane is scrolled to the newest message on load; after sending one
        // it has to be told again, or your own message lands out of sight.
        $this->dispatch('scroll-to-latest');
    }

    /**
     * The live offer between these two on this listing, if no deal exists yet.
     *
     * A chat with no idea whether a price was agreed is how "ok, деал" ends up
     * being the only record of a transaction. The offer and deal screens knew
     * this; the thread - where the two of them are actually talking - did not,
     * and neither did the person reading it.
     *
     * Only looked up when there is no deal: once there is one, the offer that
     * produced it is history and the deal is the thing that matters.
     */
    private function liveOffer(): ?Offer
    {
        return Offer::query()
            ->where('listing_id', $this->thread->listing_id)
            ->where('buyer_id', $this->thread->buyer_id)
            ->where('seller_id', $this->thread->seller_id)
            ->where('status', OfferStatus::Pending)
            ->latest('id')
            ->first();
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $deal = $this->thread->currentDeal();

        return view('livewire.messages.show-thread', [
            'messages'     => $this->thread->messages()->with('sender')->get(),
            'other'        => $this->thread->counterparty(auth()->user()),
            // Asked of the thread, which is where the rule lives; $deal is the
            // same lookup, so the banner and the scrubber cannot disagree.
            'contactsOpen' => $deal !== null,
            'deal'         => $deal,
            'offer'        => $deal ? null : $this->liveOffer(),
        ]);
    }
}
