<?php

namespace App\Livewire\Traders;

use App\Models\User;
use App\Services\Traders\TraderVerification;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Companies waiting to be looked up.
 *
 * WHAT THE MODERATOR ACTUALLY DOES HERE is open the Commercial Register in
 * another tab, type the ЕИК, and compare three things: does the company exist,
 * is the name the registered one, is the address the registered seat. That is
 * the whole job, and the screen is built around it — the ЕИК is selectable
 * monospace so it can be copied in one gesture, and the declared name and
 * address sit directly under it so the comparison is side by side rather than
 * from memory.
 *
 * NO DEEP LINK INTO THE REGISTER. The portal has a search URL, but nobody here
 * has confirmed its query shape, and a button that lands on an error page is
 * worse than no button: it teaches the moderator to stop trusting the screen.
 * When somebody checks it, it goes in the view next to the ЕИК.
 *
 * WHAT THIS SCREEN DOES NOT DO is decide whether somebody may sell as a trader.
 * They already are one — they declared it, the consumer notice is on their
 * listings, and rejecting a verification takes nothing away. The only thing at
 * stake is a badge, which is why there is no „ban" here and why a refusal is
 * worded as „we could not confirm this" rather than a verdict.
 */
class TraderQueue extends Component
{
    use WithPagination;

    #[Url(as: 'sast', except: 'pending')]
    public string $tab = 'pending';

    public ?int $deciding = null;
    public string $note = '';

    public function tabs(): array
    {
        return [
            'pending'  => 'Чакащи',
            'verified' => 'Проверени',
            'rejected' => 'Отказани',
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
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->deciding = null;
        $this->resetErrorBag();
    }

    public function verify(int $id, TraderVerification $traders): void
    {
        $this->decide($id, fn (User $u) => $traders->verify($u, auth()->user(), $this->note),
            'Фирмата е проверена. Продавачът е уведомен.');
    }

    public function reject(int $id, TraderVerification $traders): void
    {
        $this->decide($id, fn (User $u) => $traders->reject($u, auth()->user(), $this->note),
            'Заявката е отказана. Продавачът вижда бележката.');
    }

    public function revoke(int $id, TraderVerification $traders): void
    {
        $this->decide($id, fn (User $u) => $traders->revoke($u, auth()->user(), $this->note),
            'Етикетът е отнет.');
    }

    /**
     * All three decisions fail the same handful of ways — no pending request,
     * your own company, a note too short to be useful — and every one of them
     * is a sentence that belongs on the screen next to the button.
     */
    private function decide(int $id, callable $action, string $ok): void
    {
        $user = User::find($id);

        if (! $user) {
            $this->addError('note', 'Профилът вече не съществува.');

            return;
        }

        try {
            $action($user);
        } catch (RuntimeException $e) {
            $this->addError('note', $e->getMessage());

            return;
        }

        $this->deciding = null;
        $this->note     = '';

        session()->flash('status', $ok);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        // withCount, not ->listings()->count() in the loop: twenty rows is
        // twenty queries, and „how much is this account actually selling" is
        // the one number that tells a moderator whether the badge matters here.
        $query = User::query()
            ->with(['city', 'traderVerifier'])
            ->withCount('listings');

        $query = match ($this->tab) {
            'verified' => $query->where('trader_status', User::TRADER_VERIFIED)
                ->latest('trader_verified_at'),
            'rejected' => $query->where('trader_status', User::TRADER_REJECTED)
                ->latest('updated_at'),
            // Oldest first, like every other queue here: the person who has
            // been waiting longest is the one who has given up on us.
            default    => $query->where('trader_status', User::TRADER_PENDING)
                ->oldest('updated_at'),
        };

        return view('livewire.traders.trader-queue', [
            'traders' => $query->paginate(20),
        ]);
    }
}
