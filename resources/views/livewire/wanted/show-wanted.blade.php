<div class="mx-auto max-w-4xl">

    <a href="{{ route('wanted') }}" wire:navigate class="link text-sm">← Търсения</a>

    <div class="card-pad mt-3">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="hint">Търси се</p>
                <h1 class="mt-1 text-xl font-bold tracking-tight">{{ $ad->title }}</h1>

                @if ($ad->part)
                    <a href="{{ route('part', $ad->part) }}" wire:navigate
                       class="link mt-1 block font-mono text-xs">{{ $ad->part->fullName() }}</a>
                @endif
            </div>

            <div class="shrink-0 text-right">
                @if ($ad->formattedBudget())
                    <p class="price text-2xl leading-none">{{ $ad->formattedBudget() }}</p>
                    <p class="hint mt-1">бюджет</p>
                @else
                    <p class="hint">без посочен бюджет</p>
                @endif
            </div>
        </div>

        @if ($ad->detail)
            <p class="mt-4 whitespace-pre-line border-t border-line pt-4 text-sm leading-relaxed">{{ $ad->detail }}</p>
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-line pt-3 text-xs text-ink-muted">
            <span>{{ $ad->city?->name() ?? 'Цялата страна' }}</span>
            <span>{{ $ad->created_at->diffForHumans() }}</span>
            <span class="font-mono tabular">{{ $ad->view_count }} преглеждания</span>

            @unless ($ad->status->isPubliclyVisible())
                <span class="badge-neutral">{{ $ad->status->label() }}</span>
            @endunless

            @if ($ad->status->isPubliclyVisible() && $ad->daysLeft() !== null)
                <span>изтича след {{ $ad->daysLeft() }} дни</span>
            @endif
        </div>
    </div>

    @error('offer') <p class="error mt-3">{{ $message }}</p> @enderror

    {{-- ── The seller's side ────────────────────────────────────────────────
         One button per listing they already own. The entire cost of answering
         somebody's request is recognising your own card — no form, no message,
         nothing to write. Everything the buyer needs comes with the listing. --}}
    @auth
        @if (! $this->isMine() && $ad->status->isPubliclyVisible())
            @if ($offerable->isNotEmpty())
                <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-muted">
                    Имаш подходящи обяви
                </h2>

                <div class="mt-3 space-y-3">
                    @foreach ($offerable as $listing)
                        <div class="card-pad flex flex-wrap items-center justify-between gap-4"
                             wire:key="offerable-{{ $listing->id }}">
                            <div class="flex min-w-0 items-center gap-3">
                                @if ($img = $listing->coverImage())
                                    <img src="{{ $img->thumbUrl() }}" alt=""
                                         class="h-14 w-14 shrink-0 rounded-lg border border-line object-cover">
                                @else
                                    <div class="grid h-14 w-14 shrink-0 place-items-center rounded-lg
                                                border border-line bg-surface-alt text-[10px] text-ink-faint">
                                        без снимка
                                    </div>
                                @endif

                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">{{ $listing->title }}</p>
                                    <p class="price mt-0.5 text-sm">{{ $listing->formattedPrice() }}</p>

                                    {{-- Said out loud rather than hidden. A seller
                                         over budget may still have the better
                                         argument, and the buyer gets to judge it —
                                         see WantedMatcher::matches(). --}}
                                    @if ($ad->budget_max_cents && $listing->price_cents > $ad->budget_max_cents)
                                        <p class="hint mt-0.5">над бюджета на купувача</p>
                                    @endif
                                </div>
                            </div>

                            <button type="button" wire:click="offer({{ $listing->id }})"
                                    class="btn-secondary btn-sm shrink-0" wire:loading.attr="disabled">
                                Предложи я
                            </button>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="card-pad mt-8 text-center">
                    <p class="text-sm font-medium">Нямаш обява, която да отговаря</p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-ink-muted">
                        Предлагат се само активни обяви от същата категория
                        {{ $ad->part ? 'и модел' : '' }}{{ $ad->city ? ', в същия град' : '' }}.
                    </p>
                    <a href="{{ route('listing.create') }}" wire:navigate class="btn-primary mt-4 inline-block">
                        Публикувай обява
                    </a>
                </div>
            @endif
        @endif
    @else
        <div class="card-pad mt-8 text-center">
            <p class="text-sm text-ink-muted">
                <a href="{{ route('login') }}" wire:navigate class="link">Влез</a>, за да предложиш своя обява.
            </p>
        </div>
    @endauth

    {{-- ── The buyer's side ─────────────────────────────────────────────── --}}
    @if ($this->isMine())
        <div class="mt-8 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-muted">
                Предложени обяви ({{ $responses->count() }})
            </h2>

            @if ($ad->status->isPubliclyVisible())
                <button type="button" wire:click="fulfil" class="btn-ghost btn-sm">
                    Намерих — затвори търсенето
                </button>
            @elseif (in_array($ad->status, [\App\Enums\WantedStatus::Fulfilled, \App\Enums\WantedStatus::Expired], true))
                <button type="button" wire:click="reopen" class="btn-ghost btn-sm">Отвори отново</button>
            @endif
        </div>

        @if ($responses->isEmpty())
            <div class="card-pad mt-3 text-center">
                <p class="text-sm text-ink-muted">
                    Още никой не е предложил обява. Продавачите с подходяща
                    техника получиха известие.
                </p>
            </div>
        @else
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                @foreach ($responses as $response)
                    <div wire:key="resp-{{ $response->id }}">
                        @include('partials.listing-card', [
                            'listing'       => $response->listing,
                            'withFavorite'  => false,
                        ])

                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                            <p class="hint">
                                от {{ $response->seller->username }}
                                @if ($ad->budget_max_cents && $response->listing->price_cents > $ad->budget_max_cents)
                                    · <span class="text-ink">над бюджета</span>
                                @endif
                            </p>

                            {{-- Dismissing tells nobody. A seller who answered
                                 honestly and got a rejection notification
                                 learns to stop answering. --}}
                            <button type="button" wire:click="dismiss({{ $response->id }})"
                                    class="btn-ghost btn-sm">Махни</button>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="hint mt-4">
                Цената се договаря през офертите в самата обява — отвори я и
                изпрати оферта.
            </p>
        @endif
    @endif
</div>
