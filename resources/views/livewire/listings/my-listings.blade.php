<div class="mx-auto max-w-4xl">

    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight">Моите обяви</h1>
        <a href="{{ route('listing.create') }}" wire:navigate class="btn-primary btn-sm">Публикувай нова</a>
    </div>

    <div class="mt-4 flex flex-wrap gap-1 border-b border-line">
        @foreach ($this->tabs() as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition',
                        'border-accent text-ink' => $tab === $key,
                        'border-transparent text-ink-muted hover:text-ink' => $tab !== $key,
                    ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    @error('listing') <p class="error mt-3">{{ $message }}</p> @enderror
    {{-- Its own key, not 'listing': a refused boost and a refused delete can
         both be on screen, and one must not overwrite the other.
         The success side is flashed to session('status'), which the layout
         already renders for every page. --}}
    @error('boost') <p class="error mt-3">{{ $message }}</p> @enderror

    @if ($listings->isEmpty())
        <div class="card-pad mt-6 text-center">
            @if ($tab === 'all')
                <p class="text-sm font-medium">Още нямаш обяви.</p>
                <p class="hint mx-auto mt-2 max-w-md">
                    Избираш модела от каталога и спецификациите се попълват сами —
                    отнема около две минути.
                </p>
                <a href="{{ route('listing.create') }}" wire:navigate class="btn-primary mt-4 inline-block">
                    Публикувай обява
                </a>
            @else
                {{-- A filter that matched nothing, not an empty account. Saying
                     "you have no listings" here would be wrong and confusing to
                     someone looking at a tab. --}}
                <p class="text-sm text-ink-muted">Няма обяви в този раздел.</p>
                <button type="button" wire:click="$set('tab', 'all')" class="btn-ghost btn-sm mt-3">
                    Виж всички
                </button>
            @endif
        </div>
    @endif

    <div class="mt-6 space-y-3">
        @foreach ($listings as $listing)
            @php
                $bumpAt = $service->bumpAvailableAt($listing);

                // The loaded relation, already filtered to running by
                // Boosted::eagerLoad() in the component — counted, not queried.
                $runningBoosts = $listing->boosts->count();

                $editable = in_array($listing->status, [
                    \App\Enums\ListingStatus::Draft,
                    \App\Enums\ListingStatus::PendingReview,
                    \App\Enums\ListingStatus::Active,
                    \App\Enums\ListingStatus::Expired,
                ], true);
            @endphp

            <div class="card-pad" wire:key="listing-{{ $listing->id }}">
                <div class="flex gap-4">
                    <a href="{{ route('listing', $listing) }}" wire:navigate class="shrink-0">
                        @if ($img = $listing->coverImage())
                            <img src="{{ $img->thumbUrl() }}" alt=""
                                 class="h-20 w-20 rounded-lg border border-line object-cover">
                        @else
                            <div class="grid h-20 w-20 place-items-center rounded-lg border border-line
                                        bg-surface-alt text-[11px] text-ink-faint">без снимка</div>
                        @endif
                    </a>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('listing', $listing) }}" wire:navigate
                               class="link truncate font-semibold">{{ $listing->title }}</a>

                            <span @class([
                                'badge-good'    => $listing->status === \App\Enums\ListingStatus::Active,
                                'badge-warn'    => in_array($listing->status, [\App\Enums\ListingStatus::PendingReview, \App\Enums\ListingStatus::Reserved], true),
                                'badge-bad'     => $listing->status === \App\Enums\ListingStatus::Removed,
                                'badge-neutral' => ! in_array($listing->status, [
                                    \App\Enums\ListingStatus::Active,
                                    \App\Enums\ListingStatus::PendingReview,
                                    \App\Enums\ListingStatus::Reserved,
                                    \App\Enums\ListingStatus::Removed,
                                ], true),
                            ])>{{ $listing->status->label() }}</span>
                        </div>

                        <p class="mt-1 price text-lg">{{ $listing->formattedPrice() }}</p>

                        <p class="mt-1 flex flex-wrap gap-x-3 text-xs text-ink-muted">
                            <span class="font-mono tabular">{{ $listing->view_count }} преглеждания</span>
                            @if ($listing->pending_offers_count)
                                <a href="{{ route('offers') }}" wire:navigate class="text-accent">
                                    {{ $listing->pending_offers_count }} чакащи оферти
                                </a>
                            @endif
                            @if ($listing->expires_at && $listing->status === \App\Enums\ListingStatus::Active)
                                <span>изтича {{ $listing->expires_at->diffForHumans() }}</span>
                            @endif
                        </p>
                    </div>
                </div>

                @if ($confirmingDelete === $listing->id)
                    <div class="mt-4 rounded-lg border border-line bg-bad-soft p-3">
                        <p class="text-sm font-medium text-bad">Да изтрия ли обявата?</p>
                        <p class="mt-1 text-xs leading-relaxed text-bad">
                            Няма връщане назад. Чакащите оферти по нея отпадат.
                            Приключилите сделки и оценките остават.
                        </p>
                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="delete({{ $listing->id }})" class="btn-primary btn-sm">
                                Да, изтрий
                            </button>
                            <button type="button" wire:click="cancelDelete" class="btn-ghost btn-sm">Откажи</button>
                        </div>
                    </div>
                @else
                    <div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-3">
                        @if ($editable)
                            <a href="{{ route('listing.edit', $listing) }}" wire:navigate class="btn-secondary btn-sm">
                                Редактирай
                            </a>
                        @endif

                        @if ($listing->status === \App\Enums\ListingStatus::Active)
                            <button type="button" wire:click="bump({{ $listing->id }})"
                                    class="btn-ghost btn-sm" @disabled($bumpAt)
                                    title="{{ $bumpAt ? 'Отново '.$bumpAt->diffForHumans() : 'Вдига обявата в началото' }}">
                                Вдигни
                            </button>

                            {{-- AFTER the free bump, never before it. The free
                                 control is the one a seller should reach
                                 first, and a paid button sitting to its left
                                 would be the site quietly steering them past
                                 something they already have. --}}
                            <button type="button" wire:click="toggleStats({{ $listing->id }})"
                                    class="btn-ghost btn-sm">
                                Как се движи
                            </button>

                            <button type="button" wire:click="openBoost({{ $listing->id }})"
                                    class="btn-ghost btn-sm">
                                Промотирай
                                @if ($runningBoosts)
                                    <span class="badge-good ml-1">{{ $runningBoosts }}</span>
                                @endif
                            </button>
                        @endif

                        @if (in_array($listing->status, [
                            \App\Enums\ListingStatus::Active,
                            \App\Enums\ListingStatus::PendingReview,
                            \App\Enums\ListingStatus::Expired,
                        ], true))
                            <button type="button" wire:click="markSold({{ $listing->id }})" class="btn-ghost btn-sm">
                                Отбележи като продадена
                            </button>
                        @endif

                        @if (in_array($listing->status, [
                            \App\Enums\ListingStatus::Sold,
                            \App\Enums\ListingStatus::Expired,
                        ], true))
                            <button type="button" wire:click="relist({{ $listing->id }})" class="btn-ghost btn-sm">
                                Публикувай отново
                            </button>
                        @endif

                        {{-- Not the same as "публикувай отново".

                             Relist brings THIS ad back; this starts a second
                             one from it, for the next identical kit. A dealer
                             with three of the same RAM was filling four steps
                             three times.

                             Offered on every status except Removed - a listing
                             a moderator took down is not a template, and the
                             component refuses it anyway. --}}
                        @if ($listing->status !== \App\Enums\ListingStatus::Removed)
                            <a href="{{ route('listing.duplicate', $listing) }}" wire:navigate
                               class="btn-ghost btn-sm"
                               title="Отваря нова обява с попълнени данни от тази">
                                Дублирай
                            </a>
                        @endif

                        <button type="button" wire:click="confirmDelete({{ $listing->id }})"
                                class="btn-ghost btn-sm text-bad hover:text-bad">
                            Изтрий
                        </button>
                    </div>

                    {{-- The stats BEFORE the boost ladder, deliberately. A
                         seller should read why the listing is not moving before
                         they are shown something to buy — and quite often the
                         answer is „the price", which no boost fixes. --}}
                    @if ($showingStats === $listing->id && $insight)
                        @include('partials.listing-insight', [
                            'listing' => $listing,
                            'insight' => $insight,
                        ])
                    @endif

                    @if ($boosting === $listing->id)
                        @include('partials.boost-ladder', [
                            'listing' => $listing,
                            'boosts'  => $boosts,
                            'balance' => $balance,
                        ])
                    @endif

                    {{-- Said plainly, because a seller who marks a live deal as
                         sold here would be skipping the confirmation the buyer
                         is waiting on. --}}
                    @if ($listing->status === \App\Enums\ListingStatus::Reserved)
                        <p class="hint">
                            Запазена по приета оферта — приключи я от
                            <a href="{{ route('deals') }}" wire:navigate class="link">Сделки</a>.
                        </p>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-6">{{ $listings->links() }}</div>
</div>
