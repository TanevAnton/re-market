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
                                    <span class="text-xs text-ink-faint">
                                        срок {{ $deal->expires_at->diffForHumans() }}
                                    </span>
                                    @break
                                @default
                                    <span class="badge-neutral">{{ $deal->status->label() }}</span>
                            @endswitch
                        </div>

                        @if ($deal->inspect_test_selected)
                            <p class="mt-2 text-xs text-good">
                                Продавачът приема преглед и тест — отвори пратката в офиса преди да платиш.
                            </p>
                        @endif

                        @if ($deal->cancel_reason)
                            <p class="mt-2 text-xs italic text-ink-muted">
                                Отказана{{ $deal->cancelled_by === auth()->id() ? ' от теб' : ' от '.$other->username }}:
                                „{{ $deal->cancel_reason }}"
                            </p>
                        @endif
                    </div>
                </div>

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
