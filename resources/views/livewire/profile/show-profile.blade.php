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
                    @if ($user->rating_count)
                        <span class="text-xs font-normal text-ink-muted">({{ $user->rating_count }})</span>
                    @endif
                </dd>
            </div>
            <div class="bg-surface p-3">
                <dt class="text-xs text-ink-muted">Активни обяви</dt>
                <dd class="mt-0.5 font-mono text-lg font-semibold tabular">{{ $listings->total() }}</dd>
            </div>
        </dl>

        {{-- A brand-new seller and a bad one produce the same row of dashes,
             and a buyer looking at "0 / — / —" reads it as the second one.
             Saying "new" is both true and the more useful of the two, and it
             costs nothing: the numbers arrive on their own. --}}
        {{-- Loose, not ===: these two columns are zero on a new row and null on
             an older one, and a strict check quietly stops showing the note for
             exactly the accounts it exists for. --}}
        @if (! $user->deals_completed && ! $user->rating_count)
            <p class="mt-3 rounded-md border border-line bg-surface-alt px-3 py-2 text-xs leading-relaxed text-ink-muted">
                Нов профил — още няма завършени сделки.
                Уговаряйте се през платформата и използвайте „преглед и тест" при куриера.
            </p>
        @endif
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

    {{-- ------------------------------------------------------- ratings --}}
    @if ($ratings->isNotEmpty())
        <h2 class="mt-10 text-sm font-semibold uppercase tracking-wider text-ink-muted">
            Оценки
        </h2>

        <div class="mt-3 space-y-3">
            @foreach ($ratings as $rating)
                <article class="card-pad">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        @include('partials.stars', ['score' => $rating->score])

                        {{-- Which side they were. Being reliable as a seller
                             says little about being reliable as a buyer. --}}
                        <span class="badge-neutral">
                            {{ $rating->role === 'seller' ? 'като продавач' : 'като купувач' }}
                        </span>

                        <a href="{{ route('profile', $rating->rater->username) }}" wire:navigate
                           class="link text-xs">{{ $rating->rater->username }}</a>

                        <span class="ml-auto text-xs text-ink-faint">
                            {{ $rating->created_at->diffForHumans() }}
                        </span>
                    </div>

                    @if ($rating->comment)
                        <p class="mt-2 text-sm leading-relaxed">{{ $rating->comment }}</p>
                    @endif

                    @if ($rating->reply)
                        <div class="mt-3 border-l-2 border-line pl-3">
                            <p class="text-xs text-ink-muted">Отговор от {{ $user->username }}</p>
                            <p class="mt-0.5 text-sm leading-relaxed">{{ $rating->reply }}</p>
                        </div>
                    @elseif (auth()->id() === $user->id)
                        @if ($replyingId === $rating->id)
                            <form wire:submit="submitReply" class="mt-3 space-y-2">
                                <input type="text" wire:model="replyBody" maxlength="1000"
                                       placeholder="Твоят отговор — само един, публичен" autofocus>
                                @error('replyBody') <p class="error">{{ $message }}</p> @enderror
                                <div class="flex gap-2">
                                    <button type="submit" class="btn-primary btn-sm">Отговори</button>
                                    <button type="button" wire:click="$set('replyingId', null)"
                                            class="btn-ghost btn-sm">Откажи</button>
                                </div>
                            </form>
                        @else
                            <button type="button" wire:click="startReply({{ $rating->id }})"
                                    class="btn-ghost btn-sm mt-2">
                                Отговори
                            </button>
                        @endif
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    {{-- Notice and action, DSA Art. 16 - a user, not only a listing, can be
         the thing that is wrong. --}}
    @if (auth()->id() !== $user->id)
        @livewire('reports.report-form', ['subject' => $user], key('report-user-'.$user->id))
    @endif
</div>
