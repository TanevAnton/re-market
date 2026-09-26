<div class="mx-auto max-w-3xl">

    <h1 class="text-2xl font-semibold tracking-tight">Сделки</h1>
    <p class="mt-1 text-sm text-ink-muted">
        Плащането е между вас и куриера. Платформата не участва в него.
    </p>

    <div class="mt-4 flex gap-1 border-b border-line">
        <button type="button" wire:click="$set('tab', 'open')"
                @class([
                    'px-3 py-2 text-sm font-medium -mb-px border-b-2 transition',
                    'border-accent text-ink' => $tab === 'open',
                    'border-transparent text-ink-muted hover:text-ink' => $tab !== 'open',
                ])>
            Текущи
            @if ($n = $this->openCount())
                <span class="badge-accent ml-1 font-mono">{{ $n }}</span>
            @endif
        </button>

        <button type="button" wire:click="$set('tab', 'past')"
                @class([
                    'px-3 py-2 text-sm font-medium -mb-px border-b-2 transition',
                    'border-accent text-ink' => $tab === 'past',
                    'border-transparent text-ink-muted hover:text-ink' => $tab !== 'past',
                ])>
            Приключени
        </button>
    </div>

    @error('deal') <p class="error mt-3">{{ $message }}</p> @enderror

    @if ($deals->isEmpty())
        <div class="card-pad mt-6 text-center">
            <p class="text-sm text-ink-muted">
                @if ($tab === 'open')
                    Нямаш текущи сделки. Сделка се създава, когато оферта бъде приета.
                @else
                    Още нямаш приключени сделки.
                @endif
            </p>
            <a href="{{ route('offers') }}" wire:navigate class="btn-secondary btn-sm mt-4">Към офертите</a>
        </div>
    @endif

    <div class="mt-4 space-y-3">
        @foreach ($deals as $deal)
            @php
                $listing = $deal->listing;
                $isBuyer = $deal->buyer_id === auth()->id();
                $other   = $isBuyer ? $deal->seller : $deal->buyer;
                $mineOk  = $isBuyer ? $deal->buyer_confirmed_at : $deal->seller_confirmed_at;
                $theirOk = $isBuyer ? $deal->seller_confirmed_at : $deal->buyer_confirmed_at;
                $open    = $deal->status === \App\Enums\DealStatus::Open;
            @endphp

            @php
                // An open deal you have not confirmed is the single most
                // expensive thing on this screen to overlook: past the window
                // it is marked abandoned, and that follows the profile.
                $yourMove = $open && ! $mineOk;
            @endphp

            <article @class([
                'card p-4',
                'border-l-2 border-l-accent' => $yourMove,
            ])>
                @if ($yourMove)
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-accent">
                        Чака твоето потвърждение
                    </p>
                @elseif ($open && ! $theirOk)
                    {{-- Said plainly, so the person who did their part does not
                         think the site has forgotten about them. --}}
                    <p class="mb-2 text-xs font-medium text-ink-muted">
                        Ти потвърди. Чакаме {{ $other->username }}.
                    </p>
                @endif

                <div class="flex gap-3">
                    <a href="{{ route('listing', $listing) }}" wire:navigate
                       class="h-16 w-20 shrink-0 overflow-hidden rounded-md bg-surface-alt">
                        @if ($cover = $listing->images->first())
                            <img src="{{ $cover->thumbUrl() }}" alt="" class="h-full w-full object-cover">
                        @endif
                    </a>

                    <div class="min-w-0 flex-1">
                        <a href="{{ route('listing', $listing) }}" wire:navigate
                           class="line-clamp-1 text-sm font-medium hover:text-accent">
                            {{ $listing->title }}
                        </a>

                        <p class="mt-0.5 text-xs text-ink-muted">
                            {{ $isBuyer ? 'Купуваш от' : 'Продаваш на' }}
                            <a href="{{ route('profile', $other->username) }}" wire:navigate class="link">
                                {{ $other->username }}
                            </a>
                        </p>

                        <div class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            @if ($deal->agreed_price_cents)
                                <span class="font-mono text-lg font-semibold tabular">
                                    {{ number_format($deal->agreed_price_cents / 100, 2, ',', ' ') }} €
                                </span>
                            @endif

                            @switch($deal->status)
                                @case(\App\Enums\DealStatus::Completed)
                                    <span class="badge-good">{{ $deal->status->label() }}</span>
                                    @break
                                @case(\App\Enums\DealStatus::Abandoned)
                                @case(\App\Enums\DealStatus::Disputed)
                                    <span class="badge-bad">{{ $deal->status->label() }}</span>
                                    @break
                                @case(\App\Enums\DealStatus::Open)
                                    <span class="badge-accent">{{ $deal->status->label() }}</span>
                                    @include('partials.deadline', [
                                        'at'     => $deal->expires_at,
                                        'prefix' => 'срок,',
                                        // Already confirmed by this side, so
                                        // the clock is no longer theirs to
                                        // worry about - it is the other party
                                        // who is now late, and shouting at the
                                        // person who did their part is wrong.
                                        'done'   => (bool) $mineOk,
                                    ])
                                    @break
                                @default
                                    <span class="badge-neutral">{{ $deal->status->label() }}</span>
                            @endswitch
                        </div>

                        {{-- `inspect_test_selected` now means two things in sequence,
                             and this says the right one at each point.

                             OfferService seeds it at deal creation from the listing's
                             `accepts_inspect_test`, so before the buyer has filled in
                             the delivery form it is the SELLER's standing offer. Once
                             they have, it is the buyer's own choice — and only then is
                             a courier known, which matters: Еконт calls the service
                             „преглед и тест" and Спиди „отвори и тествай". A buyer
                             asking for the wrong one at a counter gets a blank look,
                             and this is the one instruction on the site that has to
                             survive contact with a courier employee. It was hard-coded
                             to Еконт's wording for both. --}}
                        @if ($deal->inspect_test_selected)
                            @if ($svc = $deal->courierEnum()?->inspectTestName())
                                <p class="mt-2 text-xs text-good">
                                    Поискай „{{ $svc }}" в офиса — отваряш и пробваш пратката, преди да платиш.
                                </p>
                            @elseif (! $deal->deliveryArranged())
                                <p class="mt-2 text-xs text-good">
                                    Продавачът приема преглед и тест — избери го, когато попълниш доставката.
                                </p>
                            @endif
                        @endif

                        @if ($deal->cancel_reason)
                            <p class="mt-2 text-xs italic text-ink-muted">
                                Отказана{{ $deal->cancelled_by === auth()->id() ? ' от теб' : ' от '.$other->username }}:
                                „{{ $deal->cancel_reason }}"
                            </p>
                        @endif
                    </div>
                </div>

                {{-- THE HANDOVER. Two halves, belonging to two people: the buyer
                     says where it goes, the seller says it is on its way.

                     Above the confirmation controls on purpose — that is the order
                     it happens in, and „Получих го" is the last step rather than
                     the first thing the screen offers. --}}
                @if ($open && $deal->courierEnum()?->ships() !== false)
                    @if ($isBuyer)
                        @if ($editingDelivery === $deal->id)
                            <form wire:submit="saveDelivery"
                                  class="mt-4 space-y-3 rounded-lg border border-line bg-surface-alt p-4">
                                <h3 class="text-sm font-semibold">Къде да се изпрати</h3>
                                <p class="hint">
                                    Продавачът вижда тези данни, за да направи товарителницата.
                                    Изтриваме ги {{ config('remarket.delivery.retention_days') }} дни
                                    след като сделката приключи.
                                </p>

                                <div>
                                    <label class="label" for="dc-{{ $deal->id }}">Куриер</label>
                                    <select id="dc-{{ $deal->id }}" wire:model.live="dCourier" class="mt-1">
                                        <option value="" disabled>Избери</option>
                                        @foreach ((array) ($listing->delivery_options ?? []) as $opt)
                                            <option value="{{ $opt }}">{{ \App\Enums\Courier::labelFor($opt) }}</option>
                                        @endforeach
                                    </select>
                                    @error('dCourier') <p class="error">{{ $message }}</p> @enderror
                                </div>

                                @if (\App\Enums\Courier::tryFrom($dCourier)?->ships())
                                    <fieldset>
                                        <legend class="label">До</legend>
                                        <div class="mt-1 flex gap-4 text-sm">
                                            <label class="flex items-center gap-1.5">
                                                <input type="radio" value="office" wire:model.live="dKind"> офис
                                            </label>
                                            <label class="flex items-center gap-1.5">
                                                <input type="radio" value="address" wire:model.live="dKind"> адрес
                                            </label>
                                        </div>
                                    </fieldset>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label class="label" for="dcity-{{ $deal->id }}">Град</label>
                                            <select id="dcity-{{ $deal->id }}" wire:model="dCityId" class="mt-1">
                                                <option value="">Избери</option>
                                                @foreach ($cities as $city)
                                                    <option value="{{ $city->id }}">{{ $city->name_bg }}</option>
                                                @endforeach
                                            </select>
                                            @error('dCityId') <p class="error">{{ $message }}</p> @enderror
                                        </div>

                                        @if ($dKind === 'office')
                                            <div>
                                                <label class="label" for="doff-{{ $deal->id }}">Офис</label>
                                                <input id="doff-{{ $deal->id }}" type="text" wire:model.blur="dOffice"
                                                       maxlength="120" class="mt-1" placeholder="напр. Офис Център">
                                                @error('dOffice') <p class="error">{{ $message }}</p> @enderror
                                            </div>
                                        @else
                                            <div>
                                                <label class="label" for="dadr-{{ $deal->id }}">Адрес</label>
                                                <input id="dadr-{{ $deal->id }}" type="text" wire:model.blur="dAddress"
                                                       maxlength="255" class="mt-1" placeholder="ул. и номер, вход, етаж">
                                                @error('dAddress') <p class="error">{{ $message }}</p> @enderror
                                            </div>
                                        @endif
                                    </div>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label class="label" for="dnm-{{ $deal->id }}">Получател</label>
                                            <input id="dnm-{{ $deal->id }}" type="text" wire:model.blur="dName"
                                                   maxlength="120" class="mt-1" placeholder="Три имена">
                                            @error('dName') <p class="error">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="label" for="dph-{{ $deal->id }}">Телефон</label>
                                            <input id="dph-{{ $deal->id }}" type="tel" wire:model.blur="dPhone"
                                                   maxlength="32" class="mt-1">
                                            @error('dPhone') <p class="error">{{ $message }}</p> @enderror
                                            <p class="hint">За куриера. Не сменя телефона на профила ти.</p>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="label" for="dnt-{{ $deal->id }}">Бележка (по избор)</label>
                                        <input id="dnt-{{ $deal->id }}" type="text" wire:model.blur="dNote"
                                               maxlength="255" class="mt-1" placeholder="напр. звънни преди да пратиш">
                                    </div>

                                    {{-- Only offered when the SELLER accepts it. A tick
                                         box on a listing that said no produces a waybill
                                         the seller refuses to create, which is worse than
                                         the box being absent. --}}
                                    @if ($listing->accepts_inspect_test)
                                        <label class="flex items-start gap-2 rounded-md border border-line p-3 text-sm">
                                            <input type="checkbox" wire:model="dInspect" class="mt-0.5">
                                            <span>
                                                <span class="font-medium">
                                                    „{{ \App\Enums\Courier::tryFrom($dCourier)?->inspectTestName() ?? 'преглед и тест' }}"
                                                </span>
                                                <span class="mt-0.5 block text-xs text-ink-muted">
                                                    Отваряш и пробваш вещта в офиса, преди да платиш. Струва малко повече и си заслужава.
                                                </span>
                                            </span>
                                        </label>
                                    @endif
                                @endif

                                <div class="flex flex-wrap gap-2">
                                    <button type="submit" class="btn-primary btn-sm">Запази</button>
                                    <button type="button" wire:click="closeDelivery" class="btn-ghost btn-sm">Назад</button>
                                </div>
                            </form>
                        @else
                            <div class="mt-4 rounded-lg border border-line bg-surface-alt p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h3 class="text-sm font-semibold">Доставка</h3>
                                    <button type="button" wire:click="openDelivery({{ $deal->id }})"
                                            class="btn-secondary btn-sm">
                                        {{ $deal->deliveryArranged() ? 'Промени' : 'Попълни данните' }}
                                    </button>
                                </div>

                                @if ($deal->deliveryArranged() && ! $deal->deliveryPurged())
                                    <p class="mt-2 text-sm">
                                        {{ $deal->courierEnum()?->label() }} ·
                                        {{ $deal->delivery_kind === 'office' ? 'до офис' : 'до адрес' }}
                                        @if ($deal->deliveryCity) · {{ $deal->deliveryCity->name() }} @endif
                                    </p>
                                @elseif ($deal->deliveryPurged())
                                    <p class="hint mt-2">Данните са изтрити след приключване на сделката.</p>
                                @else
                                    <p class="hint mt-2">
                                        Продавачът не може да направи товарителница, докато не попълниш това.
                                    </p>
                                @endif

                                @if ($deal->tracking_number)
                                    <p class="mt-3 border-t border-line pt-3 text-sm">
                                        Товарителница
                                        <span class="select-all font-mono font-medium">{{ $deal->tracking_number }}</span>
                                        @if ($url = $deal->trackingUrl())
                                            · <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                                                 class="text-accent hover:underline">проследи</a>
                                            @unless ($deal->courierEnum()?->deepLinksTracking())
                                                {{-- The configured URL carries no number, so
                                                     the buyer pastes it. Said out loud rather
                                                     than letting them click and wonder why the
                                                     page is empty. --}}
                                                <span class="hint">(копирай номера в страницата на куриера)</span>
                                            @endunless
                                        @endif
                                    </p>
                                @endif
                            </div>
                        @endif
                    @else
                        {{-- The seller's side: the fields to copy, then the number. --}}
                        @include('partials.waybill', ['deal' => $deal])

                        @if ($editingTracking === $deal->id)
                            <form wire:submit="saveTracking" class="mt-3 space-y-2 rounded-md bg-surface-alt p-3">
                                <label class="label" for="trk-{{ $deal->id }}">Номер на товарителницата</label>
                                <input id="trk-{{ $deal->id }}" type="text" wire:model="trackingNumber"
                                       maxlength="40" class="font-mono" autofocus>
                                @error('trackingNumber') <p class="error">{{ $message }}</p> @enderror

                                <div class="flex gap-2">
                                    <button type="submit" class="btn-primary btn-sm">Запази</button>
                                    <button type="button" wire:click="closeTracking" class="btn-ghost btn-sm">Назад</button>
                                </div>
                                <p class="hint">Купувачът получава известие и може да проследи пратката.</p>
                            </form>
                        @elseif ($deal->deliveryArranged())
                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                <button type="button" wire:click="openTracking({{ $deal->id }})"
                                        class="btn-secondary btn-sm">
                                    {{ $deal->tracking_number ? 'Промени товарителницата' : 'Изпратих я — въведи номер' }}
                                </button>
                                @if ($deal->tracking_number)
                                    <span class="select-all font-mono text-sm">{{ $deal->tracking_number }}</span>
                                @endif
                            </div>
                        @endif
                    @endif
                @endif

                {{-- Both sides must say it happened. Showing each side's state
                     separately is what makes that rule legible rather than
                     feeling like a button that did nothing. --}}
                @if ($open)
                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-line pt-3 text-xs">
                        <span class="{{ $mineOk ? 'text-good' : 'text-ink-faint' }}">
                            {{ $mineOk ? '✓' : '○' }} ти
                        </span>
                        <span class="{{ $theirOk ? 'text-good' : 'text-ink-faint' }}">
                            {{ $theirOk ? '✓' : '○' }} {{ $other->username }}
                        </span>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        @unless ($mineOk)
                            <button type="button" wire:click="confirm({{ $deal->id }})"
                                    wire:confirm="Потвърждаваш, че сделката се състоя?"
                                    class="btn-primary btn-sm">
                                {{ $isBuyer ? 'Получих го' : 'Предадох го' }}
                            </button>
                        @endunless

                        <button type="button" wire:click="startCancel({{ $deal->id }})"
                                class="btn-ghost btn-sm">
                            Откажи сделката
                        </button>
                    </div>

                @elseif ($deal->status === \App\Enums\DealStatus::Completed)
                    {{-- Its own component so rating one deal re-renders that
                         box, not the whole list. --}}
                    @livewire('ratings.rate-deal', ['deal' => $deal], key('rate-'.$deal->id))
                @endif

                @if ($open)
                    @if ($cancellingId === $deal->id)
                        <form wire:submit="cancel" class="mt-3 space-y-2 rounded-md bg-surface-alt p-3">
                            <label class="label" for="reason-{{ $deal->id }}">Защо?</label>
                            <input id="reason-{{ $deal->id }}" type="text" wire:model="cancelReason"
                                   maxlength="255" placeholder="Например: намерих друга карта" autofocus>
                            @error('cancelReason') <p class="error">{{ $message }}</p> @enderror

                            <div class="flex gap-2">
                                <button type="submit" class="btn-primary btn-sm">Откажи сделката</button>
                                <button type="button" wire:click="$set('cancellingId', null)"
                                        class="btn-ghost btn-sm">Назад</button>
                            </div>
                            <p class="hint">
                                Отказът не вреди на репутацията ти. Изчезването без дума — да.
                            </p>
                        </form>
                    @endif
                @endif
            </article>
        @endforeach
    </div>

    @if ($tab === 'open' && $deals->isNotEmpty())
        <p class="mt-6 text-xs leading-relaxed text-ink-faint">
            Ако никой не потвърди до изтичане на срока, сделката се отбелязва като изоставена
            и това се вижда в профила на страната, която не е потвърдила.
        </p>
    @endif
</div>
