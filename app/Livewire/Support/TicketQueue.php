<?php

namespace App\Livewire\Support;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Support\SupportService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * The support queue.
 *
 * ORDERED OLDEST FIRST, and that is not an arbitrary default: the ticket that
 * has been waiting longest is the one somebody has already given up on. Newest
 * first is how a queue quietly starves its own worst cases.
 *
 * Answering is one box and one button, on the same screen as the thread, for
 * the same reason: a queue that needs three clicks per reply is a queue that
 * gets worked on Fridays.
 */
class TicketQueue extends Component
{
    use WithPagination;

    #[Url(as: 'sast', except: 'open')]
    public string $tab = 'open';

    public ?int $replyingTo = null;
    public string $body = '';
    public bool $closeWithReply = false;

    public function tabs(): array
    {
        return [
            'open'   => 'Отворени',
            'mine'   => 'Чакат нас',
            'closed' => 'Затворени',
        ];
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->replyingTo = null;
    }

    public function openReply(int $id, SupportService $support): void
    {
        $this->replyingTo     = $id;
        $this->body           = '';
        $this->closeWithReply = false;

        // Opening it is reading it, and reading a new one is what „в работа"
        // means — see SupportService::markSeenByStaff().
        if ($ticket = Ticket::find($id)) {
            $support->markSeenByStaff($ticket);
        }
    }

    public function cancelReply(): void
    {
        $this->replyingTo = null;
        $this->resetErrorBag();
    }

    public function send(SupportService $support): void
    {
        $ticket = Ticket::find($this->replyingTo);

        if (! $ticket) {
            $this->addError('body', 'Запитването вече не съществува.');

            return;
        }

        $this->validate([
            'body' => ['required', 'string', 'min:2', 'max:5000'],
        ], ['body.required' => 'Напиши отговор.']);

        try {
            $support->answer($ticket, $this->body, auth()->user(), $this->closeWithReply);
        } catch (RuntimeException $e) {
            $this->addError('body', $e->getMessage());

            return;
        }

        $this->replyingTo = null;
        $this->body       = '';

        session()->flash('status', 'Отговорът е изпратен на '.$ticket->email.'.');
    }

    public function close(int $id, SupportService $support): void
    {
        if ($ticket = Ticket::find($id)) {
            $support->close($ticket, auth()->user());
        }
    }

    public function reopen(int $id, SupportService $support): void
    {
        if ($ticket = Ticket::find($id)) {
            $support->reopen($ticket);
        }
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $query = Ticket::query()->with(['user', 'messages']);

        $query = match ($this->tab) {
            'closed' => $query->where('status', TicketStatus::Closed)->latest('id'),
            /*
             * „Waiting on us": the ticket ends on a message nobody on this side
             * has read. Expressed against message ids rather than a flag, so
             * nothing has to remember to set one — the same derivation the
             * user's own unread badge uses, and for the same reason it is ids
             * and not timestamps (see the migration).
             */
            'mine'   => $query->working()
                ->whereNotNull('last_message_id')
                ->where(fn ($q) => $q->whereNull('staff_seen_message_id')
                    ->orWhereColumn('staff_seen_message_id', '<', 'last_message_id'))
                ->oldest('id'),
            default  => $query->working()->oldest('id'),
        };

        return view('livewire.support.ticket-queue', [
            'tickets' => $query->paginate(20),
        ]);
    }
}
