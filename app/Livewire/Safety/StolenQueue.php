<?php

namespace App\Livewire\Safety;

use App\Models\StolenReport;
use App\Services\Safety\StolenRegistry;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Theft claims, waiting for a person.
 *
 * ITS OWN SCREEN, NOT THE MODERATION QUEUE, and the separation is the point.
 * The moderation queue is about content somebody published here and carries the
 * DSA machinery for it — a statement of reasons, an appeal, a notified author.
 * A theft claim is about an OBJECT, made by someone who may have no account and
 * whose claim concerns no content of theirs. Bending the content queue around
 * it would produce statements of reasons addressed to nobody.
 *
 * So: the claim is decided here. Its CONSEQUENCE — a listing coming down —
 * goes through the content queue as a StolenClaim item, where the seller gets
 * the reasons and the appeal they are entitled to.
 *
 * CONFIRMING IS NOT REMOVING. It queues the matching listings and nothing more.
 * A moderator who confirms a claim has said „this looks like a real report",
 * not „this seller is a thief", and the screen is worded so that the person
 * clicking knows which of those they are doing.
 */
class StolenQueue extends Component
{
    use WithPagination;

    #[Url(as: 'sast', except: 'pending')]
    public string $tab = 'pending';

    public ?int $deciding = null;
    public string $note = '';

    public function tabs(): array
    {
        return [
            'pending'   => 'Чакащи',
            'confirmed' => 'Потвърдени',
            'rejected'  => 'Отхвърлени',
        ];
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->deciding = null;
    }

    public function open(int $id): void
    {
        $this->deciding = $id;
        $this->note     = '';
    }

    public function cancel(): void
    {
        $this->deciding = null;
        $this->resetErrorBag();
    }

    public function confirm(int $id, StolenRegistry $registry): void
    {
        $report = StolenReport::find($id);

        if (! $report) {
            $this->addError('note', 'Сигналът вече не съществува.');

            return;
        }

        try {
            $queued = $registry->confirm($report, auth()->user(), $this->note);
        } catch (RuntimeException $e) {
            $this->addError('note', $e->getMessage());

            return;
        }

        $this->deciding = null;

        session()->flash('status', $queued > 0
            ? "Сигналът е потвърден. {$queued} обяви са свалени за проверка."
            : 'Сигналът е потвърден. В момента няма активни обяви с този номер — '
                .'ако се появи, отива автоматично за проверка.');
    }

    public function reject(int $id, StolenRegistry $registry): void
    {
        $report = StolenReport::find($id);

        if (! $report) {
            $this->addError('note', 'Сигналът вече не съществува.');

            return;
        }

        try {
            $registry->reject($report, auth()->user(), $this->note);
        } catch (RuntimeException $e) {
            $this->addError('note', $e->getMessage());

            return;
        }

        $this->deciding = null;

        session()->flash('status', 'Сигналът е отхвърлен.');
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $query = StolenReport::query()->with(['reporter', 'decider']);

        $query = match ($this->tab) {
            'confirmed' => $query->confirmed()->latest('decided_at'),
            'rejected'  => $query->where('status', StolenReport::REJECTED)->latest('decided_at'),
            // Oldest first: somebody is waiting, and the one that has been
            // waiting longest is the one they have given up on.
            default     => $query->pending()->oldest('id'),
        };

        return view('livewire.safety.stolen-queue', [
            'reports' => $query->paginate(20),
        ]);
    }
}
