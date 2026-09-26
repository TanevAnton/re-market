<?php

namespace App\Livewire\Deals;

use App\Enums\Courier;
use App\Enums\DealStatus;
use App\Models\City;
use App\Models\Deal;
use App\Services\Deals\DealException;
use App\Services\Deals\DealService;
use App\Services\Deals\DeliveryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
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

    /*
     * The handover, one deal at a time.
     *
     * Two separate panels because they belong to two different people: the buyer
     * fills in the delivery details, the seller records the waybill number. A
     * single „handover" form would have to hide half of itself from whoever
     * opened it, and DeliveryService refuses the wrong party anyway — so the
     * screen may as well say plainly which half is yours.
     */
    public ?int $editingDelivery = null;
    public ?int $editingTracking = null;

    public string $dCourier = '';
    public string $dKind    = 'office';
    public string $dOffice  = '';
    public ?int   $dCityId  = null;
    public string $dAddress = '';
    public string $dName    = '';
    public string $dPhone   = '';
    public string $dNote    = '';
    public bool   $dInspect = true;

    public string $trackingNumber = '';

    public function updatedTab(): void
    {
        $this->reset('cancellingId', 'cancelReason', 'editingDelivery', 'editingTracking');
    }

    /** @return Collection<int, Deal> */
    public function deals(): Collection
    {
        return Deal::query()
            // deliveryCity because the waybill panel prints it on every open
            // deal, and without it that is a query per row on a page that shows
            // a hundred. Same lesson BoostEagerLoadTest guards on the grids.
            ->with(['listing.images', 'buyer', 'seller', 'deliveryCity'])
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

    // --- the handover -----------------------------------------------------

    /**
     * The buyer opens the delivery form, pre-filled from what is already there.
     *
     * Scoped through `run()` like every other action, so opening a form for
     * somebody else's deal 404s before it can echo their address back.
     */
    public function openDelivery(int $dealId): void
    {
        $this->run(function (Deal $deal) {
            if ($deal->buyer_id !== auth()->id()) {
                abort(404);
            }

            $offered = (array) ($deal->listing->delivery_options ?? []);

            $this->editingDelivery = $deal->id;
            $this->editingTracking = null;

            $this->dCourier = (string) ($deal->courier ?: (reset($offered) ?: ''));
            $this->dKind    = $deal->delivery_kind ?: 'office';
            $this->dOffice  = (string) $deal->delivery_office;
            $this->dCityId  = $deal->delivery_city_id ?: auth()->user()->city_id;
            $this->dAddress = (string) $deal->delivery_address;
            $this->dNote    = (string) $deal->delivery_note;

            // Their own username is a worse default than blank — a waybill needs
            // the name on the ID they show at the counter, and „matrix_99" is not
            // it. The phone likewise: the site holds only an HMAC of the verified
            // number, deliberately, so there is nothing to pre-fill from.
            $this->dName  = (string) $deal->delivery_name;
            $this->dPhone = (string) $deal->delivery_phone;

            $this->dInspect = $deal->delivery_set_at
                ? (bool) $deal->inspect_test_selected
                : (bool) $deal->listing->accepts_inspect_test;
        }, $dealId);
    }

    public function closeDelivery(): void
    {
        $this->reset('editingDelivery');
        $this->resetErrorBag();
    }

    public function saveDelivery(DeliveryService $delivery): void
    {
        $id = $this->editingDelivery;

        if ($id === null) {
            return;
        }

        $ships = Courier::tryFrom($this->dCourier)?->ships() ?? false;

        $this->validate([
            'dCourier' => ['required', Rule::enum(Courier::class)],
            'dKind'    => [Rule::requiredIf($ships), 'in:office,address'],
            'dCityId'  => [Rule::requiredIf($ships), 'nullable', 'exists:cities,id'],
            'dOffice'  => [Rule::requiredIf($ships && $this->dKind === 'office'), 'nullable', 'string', 'max:120'],
            'dAddress' => [Rule::requiredIf($ships && $this->dKind === 'address'), 'nullable', 'string', 'max:255'],
            'dName'    => [Rule::requiredIf($ships), 'nullable', 'string', 'min:3', 'max:120'],
            // Loose on purpose. A courier phone field takes anything a courier
            // can dial, and a regex that rejects a real number is a buyer who
            // cannot receive a parcel. `PhoneNumber` is strict because a
            // VERIFICATION has to be; this is a note for a human to read.
            'dPhone'   => [Rule::requiredIf($ships), 'nullable', 'string', 'min:6', 'max:32'],
            'dNote'    => ['nullable', 'string', 'max:255'],
        ], [
            'dCourier.required' => 'Избери начин на доставка.',
            'dCityId.required'  => 'Избери град.',
            'dOffice.required'  => 'Напиши кой офис.',
            'dAddress.required' => 'Напиши адреса.',
            'dName.required'    => 'Трите имена, както са на документа ти за самоличност.',
            'dPhone.required'   => 'Куриерът трябва на какво да звънне.',
        ]);

        $this->run(function (Deal $deal) use ($delivery) {
            $delivery->setDelivery($deal, auth()->user(), [
                'courier'      => $this->dCourier,
                'kind'         => $this->dKind,
                'office'       => $this->dOffice,
                'city_id'      => $this->dCityId,
                'address'      => $this->dAddress,
                'name'         => $this->dName,
                'phone'        => $this->dPhone,
                'note'         => $this->dNote,
                'inspect_test' => $this->dInspect,
            ]);

            $this->reset('editingDelivery');

            session()->flash('status', 'Данните за доставка са записани. Продавачът е уведомен.');
        }, $id, errorField: 'dCourier');
    }

    public function openTracking(int $dealId): void
    {
        $this->run(function (Deal $deal) {
            if ($deal->seller_id !== auth()->id()) {
                abort(404);
            }

            $this->editingTracking = $deal->id;
            $this->editingDelivery = null;
            $this->trackingNumber  = (string) $deal->tracking_number;
        }, $dealId);
    }

    public function closeTracking(): void
    {
        $this->reset('editingTracking');
        $this->resetErrorBag();
    }

    public function saveTracking(DeliveryService $delivery): void
    {
        $id = $this->editingTracking;

        if ($id === null) {
            return;
        }

        $this->validate([
            'trackingNumber' => ['required', 'string', 'min:6', 'max:40'],
        ], [
            'trackingNumber.required' => 'Въведи номера на товарителницата.',
            'trackingNumber.min'      => 'Номерът изглежда твърде кратък.',
        ]);

        $this->run(function (Deal $deal) use ($delivery) {
            $delivery->setTracking($deal, auth()->user(), $this->trackingNumber);

            $this->reset('editingTracking');

            session()->flash('status', 'Товарителницата е записана. Купувачът е уведомен.');
        }, $id, errorField: 'trackingNumber');
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
            // Only when a form is actually open: the city list is every city in
            // the country and it has no business being built for a page that is
            // just showing a list of deals.
            'cities' => $this->editingDelivery
                ? City::orderByDesc('population')->get()
                : collect(),
        ]);
    }
}
