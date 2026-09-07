<?php

namespace App\Livewire\Deals;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Services\Deals\DealException;
use App\Services\Deals\DealService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Everything the user agreed to, buying and selling, in one list.
 *
 * As with offers, every action re-fetches the deal scoped to this user and
 * lets DealService check ownership again. A Livewire round trip carries a
 * client-supplied id; a component that trusts it hands anyone else's deal to
 * whoever asks.
 */
class MyDeals extends Component
{
    #[Url(as: 'tab')]
    public string $tab = 'open';

    /** Which deal's cancel form is open, by id. */
    public ?int $cancellingId = null;

    public string $cancelReason = '';

    public function updatedTab(): void
    {
        $this->reset('cancellingId', 'cancelReason');
    }

    /** @return Collection<int, Deal> */
    public function deals(): Collection
    {
        return Deal::query()
            ->with(['listing.images', 'buyer', 'seller'])
            ->where(fn ($q) => $q->where('buyer_id', auth()->id())->orWhere('seller_id', auth()->id()))
            ->when($this->tab === 'open',
                fn ($q) => $q->where('status', DealStatus::Open),
                fn ($q) => $q->where('status', '!=', DealStatus::Open))
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    public function openCount(): int
    {
        return Deal::where('status', DealStatus::Open)
            ->where(fn ($q) => $q->where('buyer_id', auth()->id())->orWhere('seller_id', auth()->id()))
            ->count();
    }

    public function confirm(int $dealId, DealService $deals): void
    {
        $this->run(function (Deal $deal) use ($deals) {
            $deal = $deals->confirm($deal, auth()->user());

            session()->flash('status', $deal->status === DealStatus::Completed
                ? 'Сделката е завършена. И двамата я потвърдихте.'
                : 'Потвърди. Чакаме и другата страна.');
        }, $dealId);
    }

    public function startCancel(int $dealId): void
    {
        $this->cancellingId = $dealId;
        $this->cancelReason = '';
    }

    public function cancel(DealService $deals): void
    {
        $this->validate([
            'cancelReason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'cancelReason.required' => 'Кажи защо се отказваш - другата страна ще го види.',
            'cancelReason.min'      => 'Напиши поне няколко думи.',
        ]);

        $id = $this->cancellingId;

        if ($id === null) {
            return;
        }

        $this->run(function (Deal $deal) use ($deals) {
            $deals->cancel($deal, auth()->user(), $this->cancelReason);
            session()->flash('status', 'Сделката е отказана. Обявата се върна в продажба.');
        }, $id, errorField: 'cancelReason');

        $this->reset('cancellingId', 'cancelReason');
    }

    /**
     * Scoped lookup, then the service checks ownership again. A forged id 404s
     * here and would still be refused one layer down.
     */
    private function run(callable $action, int $dealId, string $errorField = 'deal'): void
    {
        $deal = Deal::where('id', $dealId)
            ->where(fn ($q) => $q->where('buyer_id', auth()->id())->orWhere('seller_id', auth()->id()))
            ->first();

        if (! $deal) {
            abort(404);
        }

        try {
            $action($deal);
        } catch (DealException $e) {
            $this->addError($errorField, $e->getMessage());
        }
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.deals.my-deals', [
            'deals' => $this->deals(),
        ]);
    }
}
