<?php

namespace App\Livewire\Support;

use App\Models\Ticket;
use App\Services\Support\SupportService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * One support conversation, from the user's side.
 *
 * WHO MAY READ IT, and the guest case is the interesting one:
 *
 *   - the account that opened it, signed in;
 *   - anybody who has opened the SIGNED link that was emailed to a guest;
 *   - an admin.
 *
 * THE SIGNATURE IS THE CREDENTIAL, AND THE SESSION IS WHERE IT IS KEPT.
 *
 * A guest ticket cannot be gated on an account, because there is none. So the
 * emailed link is signed — but the signature cannot be the thing checked on
 * every request, for two separate reasons:
 *
 *   The user's. They open the link, read the answer, and then refresh, or hit
 *   back, or follow a link and return. Every one of those loses the query
 *   string, and re-checking the signature would throw them out of their own
 *   support thread for doing something ordinary.
 *
 *   Livewire's. Its updates are POSTs to its own endpoint and carry none of the
 *   original URL. Re-checking would let a guest's FIRST reply through and
 *   refuse the second — the worst kind of bug, because it works when tested by
 *   hand once.
 *
 * So opening a valid signed link grants access to THAT ONE TICKET in the
 * session, and everything afterwards reads the session. Keyed by ticket id, so
 * a link to one conversation opens nothing else, and gone when the session is.
 */
class ShowTicket extends Component
{
    #[Locked]
    public Ticket $ticket;

    public string $body = '';

    /** The session key granting access to one ticket. */
    private static function accessKey(Ticket $ticket): string
    {
        return 'ticket-access.'.$ticket->id;
    }

    public function mount(Ticket $ticket, SupportService $support): void
    {
        $this->ticket = $ticket;

        if (request()->hasValidSignature()) {
            session()->put(self::accessKey($ticket), true);
        }

        // 404 rather than 403 throughout this codebase: a 403 confirms the
        // ticket exists to somebody guessing uuids.
        abort_unless($this->mayRead(), 404);

        /*
         * Opening it is reading it — but only when the reader is the person
         * whose ticket it is. An admin looking at it from the queue must not
         * clear the user's own unread mark, or the answer they are about to
         * write lands on a thread the user is never told about.
         */
        if (! $this->isStaffOnly()) {
            $support->markSeenByUser($ticket);
        }
    }

    public function reply(SupportService $support): void
    {
        // Re-established for the write rather than inherited from a decision
        // made in a different request.
        abort_unless($this->mayRead(), 404);

        $this->validate([
            'body' => ['required', 'string', 'min:2', 'max:5000'],
        ], [
            'body.required' => 'Напиши какво да добавим.',
        ]);

        try {
            $support->reply($this->ticket, $this->body, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('body', $e->getMessage());

            return;
        }

        $this->body = '';
        $this->ticket->refresh();
    }

    private function mayRead(): bool
    {
        return session()->get(self::accessKey($this->ticket), false)
            || (auth()->check() && $this->ticket->user_id === auth()->id())
            || (bool) auth()->user()?->is_admin;
    }

    /** An admin who is not the owner and has no link of their own. */
    private function isStaffOnly(): bool
    {
        return ! session()->get(self::accessKey($this->ticket), false)
            && $this->ticket->user_id !== auth()->id();
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.support.show-ticket', [
            'messages' => $this->ticket->messages()->with('author')->get(),
        ]);
    }
}
