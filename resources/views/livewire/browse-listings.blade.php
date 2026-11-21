{{-- Block form here, deliberately, and the parenthesised one-liner nowhere in
     this file.

     Blade lifts raw PHP blocks out BEFORE it compiles directives, using a
     regex that runs from the opening directive to the next closing one
     anywhere in the file. The one-line parenthesised form matches that opening
     half, so one of those up here plus a block further down swallows
     everything between them into a single PHP block - and every directive in
     between silently stops being compiled. That is also why this note spells
     none of it out literally: comments are processed later, so a directive
     written inside one is still picked up by that regex. --}}
@php
    $chips  = $this->activeFilters();
    $crumbs = $this->breadcrumbs();
@endphp

{{-- One root for Livewire, holding three things: the trail back up, the
     series menu in the categories that have one (graphics cards and
     processors), and the filters-and-results grid that was the whole of this
     file before it. The grid is left exactly as it was, one level in. --}}
<div>

{{-- ------------------------------------------------ the way back up ------

     Начало / Обяви / Процесори / Ryzen 5000. Every step but the last is one
     level up; the last is where you are, and is the heading of the page -
     which the listings page did not have until now.

     Each step is a real link with a real address, and an ordinary click on
     it is caught and handled in place instead. The address is for a new tab,
     a crawler and a browser with no script. The in-place version exists
     because going up a level should not cost somebody the city and the price
     they already chose - see BrowseListings::crumb().

     „Начало" is the exception: it leaves this page, so it is only a link.

     The separators are the accent slash the section headings on the home
     page open with, so the trail reads as the same family of heading rather
     than as a second kind of navigation. --}}
<nav aria-label="Път до страницата" class="mb-5 flex items-baseline gap-3.5">
    <ol class="flex min-w-0 flex-wrap items-baseline gap-x-2.5 gap-y-1">
        @foreach ($crumbs as $i => $crumb)
            <li class="flex min-w-0 items-baseline gap-x-2.5" wire:key="crumb-{{ $i }}-{{ $crumb['level'] ?? 'here' }}">
                @if ($i > 0)
                    <span aria-hidden="true" class="font-mono text-[14px] font-medium text-accent sm:text-[15px]">/</span>
                @endif

                @if ($i === count($crumbs) - 1)
                    <h1 aria-current="page" class="min-w-0 text-[19px] font-bold leading-tight tracking-tight sm:text-[21px]">
                        {{ $crumb['label'] }}
                    </h1>
                @elseif ($crumb['level'])
                    <a href="{{ $crumb['url'] }}" wire:click.prevent="crumb('{{ $crumb['level'] }}')"
                       class="whitespace-nowrap text-[14px] text-ink-muted transition hover:text-accent sm:text-[15px]">{{ $crumb['label'] }}</a>
                @else
                    <a href="{{ $crumb['url'] }}" wire:navigate
                       class="whitespace-nowrap text-[14px] text-ink-muted transition hover:text-accent sm:text-[15px]">{{ $crumb['label'] }}</a>
                @endif
            </li>
        @endforeach
    </ol>

    <span class="h-px min-w-4 flex-1 self-center bg-line"></span>

    @if ($seriesMenu)
        <span class="hidden whitespace-nowrap text-[12.5px] text-ink-faint sm:inline">избери серия, после модел</span>
    @endif
</nav>

@if ($seriesMenu)
    {{-- ------------------------------------------- parts by series ------

         The same panel as the category menu on the home page - hairlines,
         a count in the corner, ticks on hover - so the step from „Видеокарти"
         to „RTX 30" feels like the same gesture as the one before it.

         Nothing in this block knows which category it is drawing. The title,
         the tiles, the models and the label on the last tile all arrive in
         the menu data, so graphics cards and processors are one piece of
         markup and a third category would be no new markup at all.

         Buttons, not links: a series is a filter on this page rather than a
         page of its own, and it is noindex for the reason given in
         BrowseListings::meta(). The address bar still follows, so the result
         can be shared.

         On a phone it is ONE swipeable row. Twelve tiles in a grid would be
         four rows of menu before the first listing, on the screen where most
         of the traffic is and where the listing is what was come for. --}}
    <div class="mb-6">
        {{-- No heading of its own any more: the trail above ends in the name
             of the category, which is the title this block used to carry. --}}

        {{-- `relative` so a tile's offsetLeft is measured from this row, which
             is what the line below scrolls by: a link that arrives with a
             series already chosen should open with that tile in view, not
             somewhere off the right edge of a phone. On a wide screen the row
             is a grid that does not scroll and the line does nothing. --}}
        <div class="relative flex snap-x gap-px overflow-x-auto rounded-2xl border border-line bg-line
                    [scrollbar-width:none] [&::-webkit-scrollbar]:hidden
                    lg:grid lg:grid-cols-6 lg:overflow-hidden"
             x-data
             x-init="(() => { const on = $el.querySelector('[data-on]'); if (on) $el.scrollLeft = on.offsetLeft - 14; })()">

            @foreach ($seriesMenu['tiles'] as $tile)
                @php
                    $on = $seriesMenu['active'] && $seriesMenu['active']['key'] === $tile['key'];
                @endphp

                <button type="button"
                        wire:click="pickSeries('{{ $tile['key'] }}')"
                        wire:key="series-{{ $tile['key'] }}"
                        aria-pressed="{{ $on ? 'true' : 'false' }}"
                        @if ($on) data-on @endif
                        @class([
                            'group relative flex min-h-[84px] w-[132px] shrink-0 snap-start flex-col justify-between gap-3',
                            'cursor-pointer p-3 text-left transition-colors lg:min-h-[92px] lg:w-auto lg:px-4 lg:py-3.5',
                            'bg-accent-soft'             => $on,
                            'bg-canvas hover:bg-surface' => ! $on,
                        ])>

                    {{-- Corner ticks: shown on hover, and held while chosen. --}}
                    <span @class([
                        'pointer-events-none absolute left-[6px] top-[6px] h-[9px] w-[9px] border-l-[1.5px] border-t-[1.5px]',
                        'border-accent transition-opacity',
                        'opacity-100'                        => $on,
                        'opacity-0 group-hover:opacity-100'  => ! $on,
                    ])></span>
                    <span @class([
                        'pointer-events-none absolute right-[6px] bottom-[6px] h-[9px] w-[9px] border-r-[1.5px] border-b-[1.5px]',
                        'border-accent transition-opacity',
                        'opacity-100'                        => $on,
                        'opacity-0 group-hover:opacity-100'  => ! $on,
                    ])></span>

                    <span class="flex items-center gap-1.5 font-mono text-[10.5px] uppercase tracking-[0.12em] text-ink-faint">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" style="background: {{ $tile['dot'] }}"></span>
                        {{ $tile['brand'] }}
                    </span>

                    <span @class([
                        'absolute right-3 top-3 font-mono text-[11px] tabular',
                        'text-accent'    => $tile['count'] > 0,
                        'text-ink-faint' => $tile['count'] === 0,
                    ])>{{ $tile['count'] }}</span>

                    <span class="block">
                        <span @class([
                            'block font-mono text-[15.5px] font-bold leading-none tracking-tight lg:text-[17px]',
                            'text-accent'    => $on,
                            'text-ink-muted' => ! $on && $tile['count'] === 0,
                        ])>{{ $tile['label'] }}</span>
                        <span class="mt-1.5 block truncate font-mono text-[11px] leading-none text-ink-faint">{{ $tile['range'] }}</span>
                    </span>
                </button>
            @endforeach

            @for ($i = 0; $i < $seriesMenu['pad']; $i++)
                <span class="hidden bg-canvas lg:block"></span>
            @endfor

            {{-- Last in the grid, first in the swipe row: on a phone it is
                 the way back out, and the way out should not be eleven tiles
                 to the right. --}}
            <button type="button" wire:click="pickSeries('')" wire:key="series-all"
                    class="group relative order-first flex min-h-[84px] w-[132px] shrink-0 cursor-pointer snap-start flex-col
                           justify-between gap-3 bg-canvas p-3 text-left transition-colors hover:bg-surface
                           lg:order-none lg:min-h-[92px] lg:w-auto lg:px-4 lg:py-3.5">
                <span class="pointer-events-none absolute left-[6px] top-[6px] h-[9px] w-[9px] border-l-[1.5px] border-t-[1.5px]
                             border-accent opacity-0 transition-opacity group-hover:opacity-100"></span>
                <span class="pointer-events-none absolute right-[6px] bottom-[6px] h-[9px] w-[9px] border-r-[1.5px] border-b-[1.5px]
                             border-accent opacity-0 transition-opacity group-hover:opacity-100"></span>

                <span class="font-mono text-[10.5px] uppercase tracking-[0.12em] text-ink-faint">Всички</span>

                <span @class([
                    'absolute right-3 top-3 font-mono text-[11px] tabular',
                    'text-accent'    => $seriesMenu['total'] > 0,
                    'text-ink-faint' => $seriesMenu['total'] === 0,
                ])>{{ $seriesMenu['total'] }}</span>

                <span class="block text-[13.5px] font-semibold leading-tight lg:text-[14.5px]">{{ $seriesMenu['allLabel'] }}</span>
            </button>
        </div>

        {{-- The models of the chosen series. What a press does underneath
             differs by category - see BrowseListings::pickModel() - but here
             it is one call either way, and the chips below and a saved search
             agree about what was chosen without being told. --}}
        @if ($seriesMenu['active'])
            <div class="mt-3 flex items-center gap-2 overflow-x-auto pb-0.5
                        [scrollbar-width:none] [&::-webkit-scrollbar]:hidden
                        lg:flex-wrap lg:overflow-visible">
                <span class="shrink-0 font-mono text-[11.5px] text-ink-faint">модел:</span>

                @foreach ($seriesMenu['active']['models'] as $model)
                    @php
                        $picked = in_array($model['name'], $seriesMenu['chosen'], true);
                    @endphp

                    <button type="button"
                            wire:click="pickModel({{ \Illuminate\Support\Js::from($model['name']) }})"
                            wire:key="model-{{ $model['name'] }}"
                            aria-pressed="{{ $picked ? 'true' : 'false' }}"
                            @class([
                                'inline-flex shrink-0 cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5',
                                'text-[12.5px] transition-colors',
                                'border-accent bg-accent-soft text-accent' => $picked,
                                'border-line text-ink-muted hover:border-accent hover:text-accent' => ! $picked,
                                'opacity-55' => ! $picked && $model['count'] === 0,
                            ])>
                        {{ $model['name'] }}
                        <span class="font-mono text-[11px] tabular text-ink-faint">{{ $model['count'] }}</span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>
@endif

{{-- The shop buys graphics cards outright, and says so above the graphics
     cards. One slim line, under the series menu and over the listings, and
     only in the categories the offer covers - see RetechOffer. Most people
     here came to buy; the banner is for the few who came to see what theirs
     is going for. --}}
@if (\App\Support\RetechOffer::appliesTo($category))
    @include('partials.retech-buyback', [
        'placement' => 'gpu-page',
        'variant'   => 'banner',
        'class'     => 'mb-6',
    ])
@endif

<div class="grid gap-6 lg:grid-cols-[260px_1fr]"
     x-data="{ open: false }">

    {{-- ------------------------------------------------ filters ---------

         One panel with sections, not a dozen separate cards. Twelve boxes
         each with their own border and padding read as twelve unrelated
         things and turn a GPU search into a column half a screen wide and
         three screens tall.

         Collapsed on phones. Stacked above the results, the old version made
         a mobile visitor scroll past every filter in the catalogue before
         seeing a single listing - and most marketplace traffic is a phone. --}}
    <aside>
        <button type="button" x-on:click="open = ! open"
                class="btn-secondary mb-3 flex w-full items-center justify-between lg:hidden">
            <span>Филтри</span>
            @if ($chips)
                <span class="badge-accent font-mono">{{ count($chips) }}</span>
            @endif
        </button>

        <div x-bind:class="open ? 'block' : 'hidden'" class="lg:block!">
            <div class="card divide-y divide-line overflow-hidden">

                <div class="p-4">
                    <label class="label" for="q">Търсене</label>
                    <input id="q" type="search" wire:model.live.debounce.400ms="q"
                           placeholder="ртх 4090, 7800x3d…" class="mt-2">
                    <p class="hint">Работи на кирилица и латиница</p>
                </div>

                <div class="p-4">
                    <label class="label" for="cat">Категория</label>
                    <select id="cat" wire:model.live="category" class="mt-2">
                        <option value="">Всички</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c['key'] }}">{{ $c['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- The filter that was reachable only by typing ?grad= into
                     the URL. On a marketplace where half the exchanges are
                     hand-to-hand, "can I collect it myself" is one of the two
                     questions every buyer has, and it had no control at all. --}}
                <div class="p-4">
                    <label class="label" for="grad">Град</label>
                    <select id="grad" wire:model.live="city" class="mt-2">
                        <option value="">Цялата страна</option>
                        @foreach ($cities as $c)
                            <option value="{{ $c->slug }}">{{ $c->name() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="p-4">
                    <span class="label">Цена (€)</span>
                    <div class="mt-2 flex items-center gap-2">
                        <input type="number" min="0" wire:model.live.debounce.600ms="priceMin"
                               placeholder="от" aria-label="Цена от" class="font-mono">
                        <span class="text-ink-faint">–</span>
                        <input type="number" min="0" wire:model.live.debounce.600ms="priceMax"
                               placeholder="до" aria-label="Цена до" class="font-mono">
                    </div>
                </div>

                <div class="p-4">
                    <span class="label">Състояние</span>
                    <div class="mt-2 space-y-1.5">
                        @foreach ($conditions as $cond)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" value="{{ $cond->value }}" wire:model.live="condition">
                                {{ $cond->label() }}
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- Facets come from config/catalog.php. Adding a filter is a
                     config key, not a query - which is why GPUs get nine and
                     mice get two.

                     Which is also why they are folded: nine open facet lists
                     is the wall that made this column three screens tall. The
                     ones already in use open themselves, so nothing applied is
                     ever hidden. --}}
                @foreach ($facets as $key => $spec)
                    @php
                        $options = $this->facetOptions($key, $spec);
                        $inUse   = ! empty($specs[$key]);
                    @endphp

                    <details class="group" @if ($inUse) open @endif>
                        <summary class="flex cursor-pointer items-center justify-between gap-2 p-4
                                        transition hover:bg-surface-alt">
                            <span class="label">
                                {{ $filter->label($key) }}
                                @if ($filter->unit($key))
                                    <span class="normal-case text-ink-faint">({{ $filter->unit($key) }})</span>
                                @endif
                            </span>
                            <span class="shrink-0 font-mono text-xs text-ink-faint
                                         group-open:rotate-180 transition-transform">⌄</span>
                        </summary>

                        <div class="px-4 pb-4">
                            @if ($filter->help($key))
                                <p class="hint mt-0">{{ $filter->help($key) }}</p>
                            @endif

                            @if ($spec['facet'] === 'range')
                                <div class="mt-2 flex items-center gap-2">
                                    <input type="number" wire:model.live.debounce.600ms="specs.{{ $key }}.min"
                                           placeholder="от" aria-label="{{ $filter->label($key) }} от"
                                           class="font-mono">
                                    <span class="text-ink-faint">–</span>
                                    <input type="number" wire:model.live.debounce.600ms="specs.{{ $key }}.max"
                                           placeholder="до" aria-label="{{ $filter->label($key) }} до"
                                           class="font-mono">
                                </div>
                            @elseif ($spec['facet'] === 'bool')
                                <label class="mt-2 flex items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model.live="specs.{{ $key }}">
                                    Само с {{ mb_strtolower($filter->label($key)) }}
                                </label>
                            @else
                                <div class="mt-2 max-h-44 space-y-1.5 overflow-y-auto pr-1">
                                    @forelse ($options as $opt)
                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="checkbox" value="{{ $opt }}"
                                                   wire:model.live="specs.{{ $key }}">
                                            <span class="font-mono text-[13px]">{{ $opt }}</span>
                                            @if ($filter->unit($key))
                                                <span class="text-ink-faint">{{ $filter->unit($key) }}</span>
                                            @endif
                                        </label>
                                    @empty
                                        <p class="text-xs text-ink-faint">няма данни</p>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    </aside>

    {{-- ------------------------------------------------ results --}}
    <section>

        {{-- What is actually applied, and where it gets removed.

             The sidebar was the only record of this, so after five facets a
             visitor looking at four listings had to scroll a column of boxes
             to work out why. Removing a filter is what they want at that
             moment, so that is what these do. --}}
        @if ($chips)
            <div class="mb-4 flex flex-wrap items-center gap-2">
                @foreach ($chips as $chip)
                    <button type="button"
                            wire:click="clearFilter('{{ $chip['key'] }}'{{ $chip['spec'] ? ", '".$chip['spec']."'" : '' }})"
                            wire:key="chip-{{ $chip['key'] }}-{{ $chip['spec'] ?? $loop->index }}"
                            class="inline-flex items-center gap-1.5 rounded-full border border-line
                                   bg-surface-alt py-1 pl-3 pr-2 text-xs transition
                                   hover:border-bad/50 hover:text-bad">
                        {{ $chip['label'] }}
                        <span aria-hidden="true" class="text-sm leading-none">×</span>
                        <span class="sr-only">премахни филтъра</span>
                    </button>
                @endforeach

                @if (count($chips) > 1)
                    {{-- Not "изчисти всички": clearFilters deliberately keeps
                         the category, because the category is which page you
                         are on rather than a filter on it. Saying "all" and
                         leaving one behind is a small lie the user catches. --}}
                    <button type="button" wire:click="clearFilters"
                            class="text-xs text-ink-muted underline transition hover:text-ink">
                        изчисти филтрите
                    </button>
                @endif
            </div>
        @endif

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <p class="text-sm text-ink-muted">
                <strong class="font-mono text-ink tabular">{{ number_format($listings->total(), 0, ',', ' ') }}</strong>
                обяви
            </p>

            <div class="flex-1"></div>

            @if ($savingSearch)
                <div class="w-full order-last mt-3 card-pad">
                    <label class="label" for="search-name">Име на търсенето</label>
                    <div class="mt-1 flex flex-wrap items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <input id="search-name" type="text" wire:model="searchName"
                                   wire:keydown.enter="saveSearch" class="w-full">
                            @error('searchName') <p class="error mt-1">{{ $message }}</p> @enderror
                        </div>
                        <button type="button" wire:click="saveSearch" class="btn-primary">Запази</button>
                        <button type="button" wire:click="cancelSaveSearch" class="btn-ghost">Откажи</button>
                    </div>
                    <p class="hint mt-2">
                        Ще получаваш известие при нова обява, която отговаря на тези филтри.
                        Спираш ги по всяко време от „Запазени търсения“.
                    </p>
                </div>
            @endif

            <div x-data="{ shown: false }" x-on:search-saved.window="shown = true; setTimeout(() => shown = false, 4000)"
                 x-show="shown" x-cloak x-transition class="order-last w-full">
                <p class="mt-3 rounded-md bg-good-soft px-3 py-2 text-xs text-good">
                    Търсенето е запазено.
                    <a href="{{ route('searches') }}" wire:navigate class="underline">Виж запазените търсения</a>
                </p>
            </div>

            {{-- Only offered once there is something worth saving. "Save this
                 search" next to no filters saves "everything", which then
                 alerts on every listing posted and gets muted within a day. --}}
            @if ($this->hasFilters())
                <button type="button" wire:click="startSaveSearch" class="btn-ghost btn-sm">
                    Запази търсенето
                </button>
            @endif

            {{-- Clearing lives on the chip row now, next to what it clears. --}}

            <select wire:model.live="sort" class="w-auto py-1.5 text-sm">
                <option value="new">Най-нови</option>
                <option value="price_asc">Цена ↑</option>
                <option value="price_desc">Цена ↓</option>
                <option value="views">Най-гледани</option>
            </select>
        </div>

        {{-- A filter the visitor did not set has to say so.

             Without this line the site simply looks like it has a third of the
             listings it really has, and nothing on the page explains why - the
             city dropdown is in a panel that is collapsed on a phone, which is
             where most of this traffic is. --}}
        @if ($cityDefaulted && $city !== '')
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-md
                        border border-line bg-surface-alt px-3 py-2 text-xs">
                <span class="text-ink-muted">
                    Показваме обявите в <strong class="text-ink">{{ $this->currentCity()?->name() ?? $city }}</strong>,
                    защото това е твоят град.
                </span>
                <button type="button" wire:click="showWholeCountry" class="link shrink-0 font-medium">
                    Виж цялата страна
                </button>
            </div>
        @endif

        {{-- Slow updates show placeholders rather than a faded grid.

             `delay.short` (200 ms) keeps them off a fast response: a filter
             that answers at once only fades, as before, and nothing flashes.
             wire:target lists the things that change the RESULTS - saving a
             search or toggling a heart must not blank the grid. --}}
        <div class="relative">
            <div wire:loading.delay.short
                 wire:target="q,category,city,priceMin,priceMax,condition,specs,sort,gotoPage,nextPage,previousPage,setPage"
                 class="absolute inset-0 z-10 bg-canvas" role="status" aria-label="Зареждане на обявите">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @include('partials.skeleton-cards', ['count' => 6])
                </div>
            </div>

        <div wire:loading.class="opacity-40" class="transition-opacity">

            {{-- The paid slots.

                 ABOVE the results and visibly separated, not blended into
                 them. A paid listing mixed into the organic grid is the
                 pattern that makes people stop believing the order means
                 anything — and the Omnibus Directive is about exactly that
                 belief. Capped at two by config; see BrowseListings::pinned().

                 OUTSIDE the empty-state branch on purpose. The organic query
                 excludes whatever is pinned, so a category whose only match is
                 a paid listing leaves `$listings` empty — and with this block
                 nested in the @else the visitor was shown „няма обяви" while a
                 listing that matched sat one query away, unrendered. --}}
            @if ($pinned->isNotEmpty())
                <div class="mb-6">
                    <p class="hint mb-2">Платени позиции</p>

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($pinned as $listing)
                            @include('partials.listing-card', ['listing' => $listing])
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($listings->isEmpty() && $pinned->isEmpty())
                {{-- The most valuable empty state on the site.

                     Someone who filtered down to nothing knows exactly what
                     they want and cannot have it today - which is the profile
                     of the best buyer on a used marketplace, and the one most
                     likely to leave and not come back. "Try broader criteria"
                     hands them nothing; a standing alert keeps them. --}}
                @php
                    $emptyFor = $this->emptyFor();
                @endphp

                <div class="card border-dashed p-12 text-center">
                    {{-- Somebody who pressed one tile is told about that
                         tile, not about „these filters". --}}
                    <p class="font-medium">
                        @if ($emptyFor)
                            В момента няма обяви за {{ $emptyFor }}
                        @else
                            Няма обяви по тези филтри
                        @endif
                    </p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-ink-muted">
                        Пазарът за втора употреба се движи бързо — това, което търсиш,
                        може да се появи утре.
                    </p>

                    <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                        @if ($this->hasFilters())
                            <button type="button" wire:click="startSaveSearch" class="btn-primary">
                                Извести ме при нова обява
                            </button>
                            @if ($emptyFor)
                                {{-- The other half of „it is not here yet":
                                     say you want one, and let a seller find
                                     you instead. --}}
                                <a href="{{ route('wanted.create') }}" wire:navigate class="btn-secondary">
                                    Пусни „Търся“
                                </a>
                            @endif
                            <button type="button" wire:click="clearFilters" class="btn-ghost">
                                Изчисти филтрите
                            </button>
                        @else
                            <a href="{{ route('listing.create') }}" wire:navigate class="btn-primary">
                                Публикувай първата обява
                            </a>
                        @endif
                    </div>

                    @if ($emptyFor)
                        <p class="mt-4 text-xs text-ink-faint">
                            Имаш такава за продан?
                            <a href="{{ route('listing.create') }}" wire:navigate class="link">Пусни обява</a>
                        </p>
                    @endif
                </div>
            @else
                @if ($listings->isNotEmpty())
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($listings as $listing)
                            @include('partials.listing-card', ['listing' => $listing])
                        @endforeach
                    </div>

                    <div class="mt-6">{{ $listings->links() }}</div>
                @endif

                {{-- The ranking disclosure.

                     The Omnibus Directive requires telling a consumer the main
                     parameters that decide the order they are looking at, AND
                     that payment can influence it. Both halves are here, in
                     the words the sort control uses, at the foot of the
                     results where it answers „why is this the order" rather
                     than interrupting the search.

                     The block sentence and the badge sentence are separate and
                     each is written ONLY when that thing is actually on the
                     screen. A notice explaining a badge nobody can see is
                     noise — and, the reason it is worth the two lines of PHP,
                     it keeps the page from containing the words „Платени
                     позиции" when no paid block was drawn. Turn the cap off in
                     config and the page stops claiming to have one. --}}
                @php
                    $labelledOnScreen = $pinned->isNotEmpty()
                        || $listings->contains(fn ($l) => \App\Support\Boosted::isLabelled($l));
                @endphp

                <p class="hint mt-6 max-w-2xl border-t border-line pt-4">
                    Подредбата следва избраното от теб: <strong>{{ $this->sortLabel() }}</strong>.
                    @if ($this->sort === 'new')
                        При „Най-нови" мястото се определя от датата на издигане, а
                        продавачът може да я обнови — безплатно веднъж на денонощие
                        или срещу заплащане.
                    @else
                        При тази подредба плащане не влияе на мястото.
                    @endif
                    @if ($pinned->isNotEmpty())
                        Блокът най-горе, озаглавен „Платени позиции", е платен от продавача.
                    @endif
                    @if ($labelledOnScreen)
                        Обявите с етикет „промотирана" са платени от продавача.
                    @endif
                </p>
            @endif
        </div>
        </div>{{-- /relative: the skeleton overlay's frame --}}
    </section>
</div>

</div>
