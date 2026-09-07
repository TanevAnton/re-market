<?php

namespace App\Livewire\Moderation;

use App\Enums\RejectionReason;
use App\Models\Listing;
use App\Models\ModerationItem;
use App\Models\User;
use App\Services\Moderation\ModerationService;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * The review queue.
 *
 * One listing at a time, in priority order, with everything needed to judge it
 * on the same screen: the photos, the price, and what the seller's account
 * looks like. A moderator who has to open three tabs to decide will stop
 * looking properly by the tenth listing, and a queue that is not worked
 * properly is worse than no queue - it delays honest sellers without catching
 * anyone.
 */
class Queue extends Component
{
    use WithPagination;

    /** The item whose reject form is open. Locked: it addresses a database row. */
    #[Locked]
    public ?int $rejecting = null;

    public string $reason = '';
    public string $facts  = '';

    public function pendingCount(): int
    {
        return ModerationItem::queue()->count();
    }

    public function approve(int $id): void
    {
        $this->decide(fn (ModerationService $s, ModerationItem $i) => $s->approve($i, auth()->user()), $id);
    }

    public function startReject(int $id): void
    {
        $this->rejecting = $id;
        $this->reset(['reason', 'facts']);
        $this->resetErrorBag();
    }

    public function cancelReject(): void
    {
        $this->reset(['rejecting', 'reason', 'facts']);
    }

    public function reject(int $id): void
    {
        $this->validate([
            'reason' => ['required', 'string'],
            // The length floor is the point, not a formality: it is what stops
            // the statement of reasons being the category typed out again.
            'facts'  => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'reason.required' => 'Избери причина.',
            'facts.required'  => 'Опиши какво точно е нередно.',
            'facts.min'       => 'Опиши какво точно е нередно - това го чете продавачът.',
        ]);

        $reason = RejectionReason::tryFrom($this->reason);

        if (! $reason) {
            $this->addError('reason', 'Избери причина.');

            return;
        }

        $this->decide(
            fn (ModerationService $s, ModerationItem $i) => $s->reject($i, auth()->user(), $reason, $this->facts),
            $id,
        );

        $this->reset(['rejecting', 'reason', 'facts']);
    }

    /**
     * Both decisions fail the same ways - someone else got there first, or the
     * item is the moderator's own listing - and both must say so on the screen
     * rather than throwing a 500 at someone halfway through a queue.
     */
    private function decide(callable $action, int $id): void
    {
        $item = ModerationItem::find($id);

        if (! $item) {
            $this->addError('queue', 'Тази обява вече не е в опашката.');

            return;
        }

        try {
            $action(app(ModerationService::class), $item);
        } catch (RuntimeException $e) {
            $this->addError('queue', $e->getMessage());

            return;
        }

        // Deciding the last item on page 3 must not leave the moderator staring
        // at an empty page.
        $this->resetPage();
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        // morphWith, not dot notation: `subject` is polymorphic, and asking for
        // `subject.user` would go looking for a `user` relation on every type
        // that ever lands in this queue - reports and users included.
        $items = ModerationItem::queue()
            ->with(['subject' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                Listing::class => ['user', 'images', 'city', 'part'],
                User::class    => [],
            ])])
            ->paginate(10);

        return view('livewire.moderation.queue', [
            'items'   => $items,
            'reasons' => RejectionReason::options(),
        ]);
    }
}
