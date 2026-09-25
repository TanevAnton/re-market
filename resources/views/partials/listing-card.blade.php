{{-- Shared by browse, profile, home and the part pages. One card definition,
     one place to change. --}}
@php
    // Read once per card. Boosted:: reads the LOADED relation, so a grid that
    // eager-loads pays one query for the page rather than two per card.
    $boostHighlighted = \App\Support\Boosted::isHighlighted($listing);
    $boostLabelled    = \App\Support\Boosted::isLabelled($listing);
@endphp

{{-- The highlight is a RING, not a fill. A filled card would out-shout the
     photograph, which is the thing a buyer is actually scanning, and a grid
     where the paid cards are the loudest is the grid this site exists not to
     be. A ring says „look here" without taking anything away from the item. --}}
<article @class([
    'card-interactive group relative flex flex-col overflow-hidden',
    'ring-2 ring-accent/70' => $boostHighlighted,
])>

    {{-- Outside the <a>, because a button nested in a link is invalid HTML and
         behaves differently in every browser. $withFavorite defaults to true so
         every existing include gets it; the saved-listings page passes false
         and positions its own, to avoid two hearts on one card. --}}
    @auth
        @if (($withFavorite ?? true) && $listing->status->isPubliclyVisible())
            <div class="absolute right-2 top-2 z-10">
                @livewire('favorites.favorite-button',
                    ['listing' => $listing, 'compact' => true],
                    key('fav-'.$listing->id))
            </div>
        @endif
    @endauth

    <a href="{{ route('listing', $listing) }}" wire:navigate class="flex flex-1 flex-col">

        {{-- The photograph is what people actually scan. It gets the room. --}}
        <div class="relative aspect-[4/3] overflow-hidden bg-surface-alt">
            @if ($img = $listing->coverImage())
                <img src="{{ $img->thumbUrl() }}" alt="{{ $listing->title }}" loading="lazy"
                     class="h-full w-full object-cover transition-transform duration-300
                            group-hover:scale-[1.03]">
            @else
                <div class="grid h-full place-items-center text-xs text-ink-faint">без снимка</div>
            @endif

            {{-- Over the image rather than under it: the two things a buyer
                 filters on hardest, without spending a row of the card. --}}
            <div class="absolute left-2 top-2 flex flex-wrap gap-1">
                {{-- Required, not decorative. The Omnibus Directive makes a
                     consumer's ability to tell that money changed hands a
                     legal obligation, so this badge is as load-bearing as the
                     price. It reads „Промотирана" for every running boost —
                     including the highlight, which does not move the listing
                     — because arguing that distinction to a regulator is not
                     worth the two pixels it saves. --}}
                @if ($boostLabelled)
                    <span class="badge-accent backdrop-blur">промотирана</span>
                @endif

                {{-- Before the mining badge: a card that cannot be unlocked is
                     worth less than a card that was mined on, and a buyer
                     scanning a grid of iPhones should not have to open one to
                     find out. --}}
                @if (\App\Support\AppleLock::isLocked($listing))
                    <span class="badge-warn backdrop-blur">заключен за Apple ID</span>
                @endif

                @if ($listing->mining_use === \App\Enums\MiningUse::Yes)
                    <span class="badge-warn backdrop-blur">копала {{ $listing->mining_months }} мес.</span>
                @endif
                @if ($listing->accepts_inspect_test)
                    <span class="badge-good backdrop-blur">преглед и тест</span>
                @endif
            </div>
        </div>

        <div class="flex flex-1 flex-col p-4">
            <h3 class="line-clamp-2 text-sm font-semibold leading-snug transition-colors
                       group-hover:text-accent">
                {{ $listing->title }}
            </h3>

            @if ($listing->part)
                <p class="mt-1.5 font-mono text-[11px] leading-relaxed text-ink-muted">
                    @foreach (array_slice($listing->part->specs, 0, 3) as $v)
                        <span class="whitespace-nowrap">{{ \App\Models\Part::specLabel($v) }}</span>@if (! $loop->last) <span class="text-ink-faint">·</span> @endif
                    @endforeach
                </p>
            @endif

            <div class="mt-2.5">
                <span class="badge-neutral">{{ $listing->condition->label() }}</span>
            </div>

            {{-- The price is the loudest thing on the card, and the only place
                 the accent colour appears in the grid - which is what makes a
                 wall of twelve cards scannable by price. --}}
            <div class="mt-auto flex items-end justify-between gap-2 pt-4">
                <div>
                    <p class="price text-xl leading-none">{{ $listing->formattedPrice() }}</p>

                    {{-- Directly under the price, because it is a statement
                         ABOUT that number rather than a property of the item.
                         Only ever appears when the listing is cheap - see
                         Listing::priceAdvantage() for why there is no badge
                         in the other direction. --}}
                    @if ($advantage = $listing->priceAdvantage())
                        <p class="mt-1 text-[11px] font-semibold text-good">
                            {{ $advantage }}% под средното за модела
                        </p>
                    @endif

                    <p class="mt-1.5 text-xs text-ink-muted">
                        {{ $listing->city?->name() }}
                    </p>
                </div>

                @if ($listing->offers_enabled)
                    <span class="shrink-0 rounded-md border border-line px-1.5 py-0.5 text-[10px]
                                 font-medium uppercase tracking-wide text-ink-faint">
                        оферти
                    </span>
                @endif
            </div>
        </div>
    </a>
</article>
