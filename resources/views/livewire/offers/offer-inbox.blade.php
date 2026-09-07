<div class="mx-auto max-w-3xl">

    <h1 class="text-2xl font-semibold tracking-tight">Оферти</h1>

    <div class="mt-4 flex gap-1 border-b border-line">
        <button type="button" wire:click="$set('tab', 'received')"
                @class([
                    'px-3 py-2 text-sm font-medium -mb-px border-b-2 transition',
                    'border-accent text-ink' => $tab === 'received',
                    'border-transparent text-ink-muted hover:text-ink' => $tab !== 'received',
                ])>
            Получени
            @if ($n = $this->pendingCount())
                <span class="badge-accent ml-1 font-mono">{{ $n }}</span>
            @endif
        </button>

        <button type="button" wire:click="$set('tab', 'sent')"
                @class([
                    'px-3 py-2 text-sm font-medium -mb-px border-b-2 transition',
                    'border-accent text-ink' => $tab === 'sent',
                    'border-transparent text-ink-muted hover:text-ink' => $tab !== 'sent',
                ])>
            Изпратени
            @if ($c = $this->counterCount())
                <span class="badge-accent ml-1 font-mono">{{ $c }}</span>
            @endif
        </button>
    </div>

    @error('offer')
        <p class="error mt-3">{{ $message }}</p>
    @enderror

    @if ($offers->isEmpty())
        <div class="card-pad mt-6 text-center">
            <p class="text-sm text-ink-muted">
                @if ($tab === 'received')
                    Още никой не ти е пратил оферта.
                @else
                    Още не си пратил оферта.
                @endif
            </p>

            {{-- The counter lives in the other tab. Without this the buyer sees
                 an empty list and concludes the seller never replied. --}}
            @if ($tab === 'received' && ($c = $this->counterCount()))
                <p class="mt-3 text-sm">
                    Продавач ти прати насрещна оферта.
                    <button type="button" wire:click="$set('tab', 'sent')" class="link">
                        Виж я в „Изпратени"
                    </button>
                </p>
            @endif
            <a href="{{ route('browse') }}" wire:navigate class="btn-secondary btn-sm mt-4">Разгледай обявите</a>
        </div>
    @endif

    <div class="mt-4 space-y-3">
        @foreach ($offers as $offer)
            @php
                $listing = $offer->listing;
                $isMine  = $offer->buyer_id === auth()->id();
                $live    = $offer->status === \App\Enums\OfferStatus::Pending;
            @endphp

            <article class="card p-4">
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
                            Иска <span class="font-mono tabular">{{ $listing->formattedPrice() }}</span>
                            ·
                            {{ $isMine ? 'продавач' : 'купувач' }}
                            <a href="{{ route('profile', ($isMine ? $offer->seller : $offer->buyer)->username) }}"
                               wire:navigate class="link">
                                {{ ($isMine ? $offer->seller : $offer->buyer)->username }}
                            </a>
                        </p>

                        <div class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span class="font-mono text-lg font-semibold tabular">
                                {{ $offer->formattedAmount() }}
                            </span>
                            <span class="text-xs text-ink-muted">
                                −{{ $offer->discountPercent() }}%
                            </span>

                            @if ($live)
                                <span class="badge-accent">{{ $offer->status->label() }}</span>
                                <span class="text-xs text-ink-faint">
                                    изтича {{ $offer->expires_at->diffForHumans() }}
                                </span>
                            @elseif ($offer->status === \App\Enums\OfferStatus::Accepted)
                                <span class="badge-good">{{ $offer->status->label() }}</span>
                            @elseif ($offer->status === \App\Enums\OfferStatus::Countered)
                                <span class="badge-warn">{{ $offer->status->label() }}</span>
                            @else
                                <span class="badge-neutral">{{ $offer->status->label() }}</span>
                            @endif
                        </div>

                        @if ($offer->note)
                            <p class="mt-2 text-xs italic leading-relaxed text-ink-muted">„{{ $offer->note }}"</p>
                        @endif
                    </div>
                </div>

                {{-- the counter the seller sent back, shown under the offer it answers --}}
                @foreach ($offer->counters as $counter)
                    <div class="mt-3 border-l-2 border-accent pl-3">
                        <p class="text-xs text-ink-muted">Насрещна оферта</p>
                        <p class="font-mono text-base font-semibold tabular">{{ $counter->formattedAmount() }}</p>
                        <p class="text-xs text-ink-faint">{{ $counter->status->label() }}</p>
                    </div>
                @endforeach

                {{-- ---------------------------------------------- actions --}}
                @if ($live && ! $isMine)
                    {{-- seller looking at a buyer's offer --}}
                    <div class="mt-3 flex flex-wrap gap-2 border-t border-line pt-3">
                        <button type="button" wire:click="accept({{ $offer->id }})"
                                wire:confirm="Приемаш офертата? Останалите оферти по обявата ще бъдат отказани."
                                class="btn-primary btn-sm">
                            Приеми
                        </button>
                        <button type="button" wire:click="startCounter({{ $offer->id }})"
                                class="btn-secondary btn-sm">
                            Насрещна оферта
                        </button>
                        <button type="button" wire:click="decline({{ $offer->id }})"
                                class="btn-ghost btn-sm">
                            Откажи
                        </button>
                    </div>

                    @if ($counteringId === $offer->id)
                        <form wire:submit="sendCounter" class="mt-3 space-y-2 rounded-md bg-surface-alt p-3">
                            <label class="label" for="counter-{{ $offer->id }}">Твоята насрещна цена (€)</label>
                            <input id="counter-{{ $offer->id }}" type="text" inputmode="decimal"
                                   wire:model="counterAmount" class="font-mono" autofocus>
                            @error('counterAmount') <p class="error">{{ $message }}</p> @enderror

                            <input type="text" wire:model="counterNote"
                                   maxlength="{{ config('remarket.offers.note_max_length', 200) }}"
                                   placeholder="Съобщение (по избор)">

                            <div class="flex gap-2">
                                <button type="submit" class="btn-primary btn-sm">Изпрати</button>
                                <button type="button" wire:click="$set('counteringId', null)"
                                        class="btn-ghost btn-sm">Откажи</button>
                            </div>
                            <p class="hint">
                                Една насрещна оферта на разговор. Повече от това е пазарлък.
                            </p>
                        </form>
                    @endif

                @elseif ($live && $isMine && $offer->is_counter)
                    {{-- buyer looking at the seller's counter --}}
                    <div class="mt-3 flex flex-wrap gap-2 border-t border-line pt-3">
                        <button type="button" wire:click="acceptCounter({{ $offer->id }})"
                                class="btn-primary btn-sm">
                            Приеми {{ $offer->formattedAmount() }}
                        </button>
                        <button type="button" wire:click="declineCounter({{ $offer->id }})"
                                class="btn-ghost btn-sm">
                            Откажи
                        </button>
                    </div>

                @elseif ($live && $isMine)
                    {{-- buyer's own offer, still waiting --}}
                    <div class="mt-3 border-t border-line pt-3">
                        <button type="button" wire:click="withdraw({{ $offer->id }})"
                                wire:confirm="Да оттеглим ли офертата?"
                                class="btn-ghost btn-sm">
                            Оттегли
                        </button>
                    </div>
                @endif
            </article>
        @endforeach
    </div>

    <p class="mt-6 text-xs leading-relaxed text-ink-faint">
        Офертите не са обвързващи и не сключват договор. Платформата не обработва плащания —
        сделката се уговаря пряко между вас.
    </p>
</div>
