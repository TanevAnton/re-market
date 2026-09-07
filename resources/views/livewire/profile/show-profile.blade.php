<div>
    <div class="card-pad">
        <div class="flex flex-wrap items-start gap-4">
            <div class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-accent
                        font-mono text-lg font-semibold text-[var(--accent-ink)]">
                {{ mb_strtoupper(mb_substr($user->username, 0, 1)) }}
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-semibold tracking-tight">{{ $user->username }}</h1>
                    <span class="{{ $user->isTrader() ? 'badge-accent' : 'badge-neutral' }}">
                        {{ $user->seller_type->label() }}
                    </span>
                    @if ($user->phone_verified_at)
                        <span class="badge-good">телефон потвърден</span>
                    @endif
                </div>

                <p class="mt-1 text-sm text-ink-muted">
                    {{ $user->city?->name() }}
                    · в сайта от {{ $user->created_at->translatedFormat('F Y') }}
                </p>

                @if ($user->isTrader() && $user->trader_details)
                    <p class="hint">
                        {{ $user->trader_details['company'] ?? '' }}
                        @if (! empty($user->trader_details['uic'])) · ЕИК {{ $user->trader_details['uic'] }} @endif
                    </p>
                @endif
            </div>

            @if (auth()->id() === $user->id)
                <a href="{{ route('profile.edit') }}" wire:navigate class="btn-secondary btn-sm">Настройки</a>
            @endif
        </div>

        {{-- Three numbers, not one star rating. "Completes 92% of accepted
             deals" tells a buyer more than five stars ever will. --}}
        <dl class="mt-5 grid grid-cols-2 gap-px overflow-hidden rounded-md border border-line bg-line sm:grid-cols-4">
            <div class="bg-surface p-3">
                <dt class="text-xs text-ink-muted">Завършени сделки</dt>
                <dd class="mt-0.5 font-mono text-lg font-semibold tabular">{{ $user->deals_completed }}</dd>
            </div>
            <div class="bg-surface p-3">
                <dt class="text-xs text-ink-muted">Довежда до край</dt>
                <dd class="mt-0.5 font-mono text-lg font-semibold tabular">
                    {{ $user->completionRate() !== null ? $user->completionRate().'%' : '—' }}
                </dd>
            </div>
            <div class="bg-surface p-3">
                <dt class="text-xs text-ink-muted">Оценка</dt>
                <dd class="mt-0.5 font-mono text-lg font-semibold tabular">
                    {{ $user->rating_avg ?? '—' }}
                </dd>
            </div>
            <div class="bg-surface p-3">
                <dt class="text-xs text-ink-muted">Активни обяви</dt>
                <dd class="mt-0.5 font-mono text-lg font-semibold tabular">{{ $listings->total() }}</dd>
            </div>
        </dl>
    </div>

    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wider text-ink-muted">Обяви</h2>

    @if ($listings->isEmpty())
        <p class="card-pad mt-3 text-sm text-ink-muted">Няма активни обяви.</p>
    @else
        <div class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($listings as $listing)
                @include('partials.listing-card', ['listing' => $listing])
            @endforeach
        </div>
        <div class="mt-6">{{ $listings->links() }}</div>
    @endif
</div>
