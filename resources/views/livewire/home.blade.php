{{-- The layout wraps main in max-w-7xl px-4 py-6. Cancelling that here lets the
     top band reach the edges of the container while every section below keeps
     the site's normal measure.

     The two custom properties are the whole "gaming" budget of this page: a
     glow and a grid line, both mixed from the accent so they follow the theme
     without a second set of colours. Everything that uses them is decoration
     on hover or behind text - take the two variables away and the page still
     reads, which is the test for whether an effect has earned its place. --}}
<div class="-mx-4 -mt-6"
     style="--glow: color-mix(in oklab, var(--accent) 35%, transparent);
            --grid: color-mix(in oklab, var(--accent) 7%, transparent);">

    {{-- ── Top band ──────────────────────────────────────────────────────────
         The title and the search, both turned down on purpose. This used to be
         a 500px hero with a 60px headline; it told a first visitor what the
         site was and then made everybody else scroll past it on every visit.
         Now it is one row, and the categories are on screen without scrolling.

         Both backdrop layers fade to nothing INSIDE the band. The band is only
         as wide as the layout container, so anything still visible at its edge
         would be cut in a straight line on a wide monitor. --}}
    <section class="relative overflow-hidden">
        <div class="pointer-events-none absolute inset-0"
             style="background-image: linear-gradient(var(--grid) 1px, transparent 1px),
                                      linear-gradient(90deg, var(--grid) 1px, transparent 1px);
                    background-size: 44px 44px;
                    -webkit-mask-image: radial-gradient(30% 130% at 32% 0%, #000 0%, transparent 100%);
                            mask-image: radial-gradient(30% 130% at 32% 0%, #000 0%, transparent 100%);"></div>
        <div class="pointer-events-none absolute left-[6%] -top-36 h-72 w-[46%] opacity-[0.28]"
             style="background: radial-gradient(closest-side, var(--glow), transparent);"></div>

        <div class="relative mx-auto flex max-w-7xl flex-col gap-6 px-4 pt-8 pb-7
                    lg:flex-row lg:items-end lg:justify-between lg:gap-10 lg:pt-11 lg:pb-10">
            <div>
                <p class="flex items-center gap-2 font-mono text-[11px] uppercase tracking-[0.2em] text-accent">
                    <span class="h-1.5 w-1.5 rounded-full bg-accent"
                          style="box-shadow: 0 0 10px 1px var(--glow);"></span>
                    Хардуер втора употреба
                </p>

                {{-- Still the h1, still the phrase the page should rank for -
                     just no longer the loudest thing on it. --}}
                <h1 class="mt-3 max-w-[24ch] text-xl font-semibold leading-snug tracking-tight text-ink-muted sm:text-2xl">
                    Компютърни части с
                    <span class="font-bold text-ink">истински спецификации</span>
                </h1>

                <p class="mt-2.5 font-mono text-[10.5px] text-ink-faint sm:text-xs">
                    преглед преди плащане
                    <span class="mx-1 text-line-strong sm:mx-1.5">/</span> една цена
                    <span class="mx-1 text-line-strong sm:mx-1.5">/</span> само хардуер
                </p>
            </div>

            {{-- Straight to browse with ?q= - the same parameter the filters
                 already read from the URL, so search and filtering are one
                 screen rather than two.

                 The slash key focuses the field from anywhere on the page, the
                 way it does in most tools this audience uses. It is ignored
                 while somebody is typing in another field. --}}
            <form method="GET" action="{{ route('browse') }}" class="w-full shrink-0 lg:w-[540px]"
                  x-data
                  x-on:keydown.window="if ($event.key === '/' && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName) && ! document.activeElement.isContentEditable) { $event.preventDefault(); $refs.q.focus() }">

                <label class="group flex h-[50px] cursor-text items-center gap-3 rounded-xl border border-line
                              bg-surface/60 pr-2 pl-4 transition
                              hover:border-line-strong
                              focus-within:border-accent focus-within:bg-surface
                              focus-within:shadow-[0_0_0_3px_var(--accent-soft),0_0_28px_-8px_var(--glow)]">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" aria-hidden="true" class="h-4 w-4 shrink-0 text-ink-faint">
                        <circle cx="11" cy="11" r="6.5"/>
                        <path d="m16 16 4.5 4.5"/>
                    </svg>

                    {{-- No important modifiers. The base input style lives in
                         the base layer and every utility here is in the
                         utilities layer, which wins on layer order. --}}
                    <input x-ref="q" type="search" name="q" autocomplete="off"
                           placeholder="Търси модел — RTX 4070, 7800X3D…"
                           aria-label="Търсене на компоненти"
                           class="peer min-w-0 flex-1 border-0 bg-transparent p-0 text-[14.5px]
                                  focus:border-0 focus:ring-0">

                    <kbd class="hidden rounded-md border border-line-strong px-2 py-0.5 font-mono text-[11px] text-ink-faint
                                group-focus-within:hidden peer-[:not(:placeholder-shown)]:hidden sm:inline">/</kbd>

                    {{-- Shown while the field is focused OR has text in it. The
                         second half is not decoration: some browsers do not
                         focus a button on click, so a button that only exists
                         while the field is focused disappears between the
                         press and the release, and the click lands on nothing. --}}
                    <button type="submit"
                            class="hidden h-8 shrink-0 rounded-lg bg-accent px-3.5 text-[12.5px] font-semibold
                                   text-[var(--accent-ink)] transition hover:brightness-110
                                   group-focus-within:block peer-[:not(:placeholder-shown)]:block">
                        Търси
                    </button>
                </label>

                <p class="mt-2.5 flex flex-wrap gap-x-3.5 gap-y-1 font-mono text-[11.5px] text-ink-faint">
                    <span>популярно:</span>
                    @foreach (['RTX 4070', 'RX 7800 XT', 'Ryzen 7 5800X3D', 'DDR5 32GB'] as $suggestion)
                        <a href="{{ route('browse', ['q' => $suggestion]) }}" wire:navigate
                           class="text-ink-muted transition hover:text-accent">{{ $suggestion }}</a>
                    @endforeach
                </p>
            </form>
        </div>

        {{-- A rule that fades at both ends rather than a border, for the same
             reason as the backdrop: a hard line would stop dead at the edge of
             the container on a wide screen. --}}
        <div class="h-px" style="background: linear-gradient(90deg, transparent, var(--line) 12%, var(--line) 88%, transparent);"></div>
    </section>

    <div class="mx-auto max-w-7xl px-4">

        {{-- ── Categories ────────────────────────────────────────────────────
             The primary navigation of the whole site. One panel with hairlines
             inside it rather than a card per category: nineteen separate boxes
             is nineteen borders and nineteen shadows competing with the icons
             they exist to frame.

             The hairlines are the panel's own background showing through a 1px
             gap, so there is no per-cell border logic to get wrong when the
             column count changes. The price of that trick is that an empty
             grid slot would show as a solid block of line colour - hence the
             filler cells below, which only render if the number of categories
             ever stops dividing evenly.

             The catch-all category is the full-width row at the bottom. It is
             nobody's destination, and as a tile it sat alone on a last row. --}}
        @php
            $tiles = collect($categories);
            $other = $tiles->firstWhere('key', 'other');
            $tiles = $tiles->reject(fn ($c) => $c['key'] === 'other')->values();

            $padNarrow = (3 - $tiles->count() % 3) % 3;   // three columns below lg
            $padWide   = (6 - $tiles->count() % 6) % 6;   // six from lg up
        @endphp

        <section class="py-12 sm:py-14">
            <div class="mb-5 flex items-baseline gap-3.5">
                <h2 class="whitespace-nowrap text-[17px] font-bold tracking-tight">
                    <span class="mr-1.5 font-mono font-medium text-accent">//</span>Категории
                </h2>
                <span class="h-px flex-1 self-center bg-line"></span>
                <a href="{{ route('browse') }}" wire:navigate
                   class="whitespace-nowrap text-[12.5px] text-ink-muted transition hover:text-accent">Всички обяви →</a>
            </div>

            <div class="grid grid-cols-3 gap-px overflow-hidden rounded-2xl border border-line bg-line lg:grid-cols-6">
                @foreach ($tiles as $category)
                    <a href="{{ route('browse', ['kat' => $category['key']]) }}" wire:navigate
                       class="group relative flex min-h-[108px] flex-col justify-between bg-canvas p-3.5
                              transition-colors hover:bg-surface lg:min-h-[124px] lg:p-[18px]">

                        {{-- Corner ticks, on hover only. Two spans rather than
                             pseudo-elements so they are ordinary utilities and
                             need nothing added to the stylesheet. --}}
                        <span class="pointer-events-none absolute left-[7px] top-[7px] h-[9px] w-[9px] border-l-[1.5px] border-t-[1.5px]
                                     border-accent opacity-0 transition-opacity group-hover:opacity-100"></span>
                        <span class="pointer-events-none absolute right-[7px] bottom-[7px] h-[9px] w-[9px] border-r-[1.5px] border-b-[1.5px]
                                     border-accent opacity-0 transition-opacity group-hover:opacity-100"></span>

                        {{-- The icon inherits currentColor, which is the whole
                             reason it is drawn rather than loaded: the hover
                             state changes the text colour and the drawing
                             follows, with no second asset. --}}
                        @include('partials.category-icon', [
                            'category' => $category['key'],
                            'class'    => 'h-[26px] w-[26px] text-ink-muted transition duration-200 lg:h-[30px] lg:w-[30px] '
                                         .'group-hover:-translate-y-px group-hover:text-accent '
                                         .'group-hover:[filter:drop-shadow(0_0_7px_var(--glow))]',
                        ])

                        <span @class([
                            'absolute right-2.5 top-2.5 font-mono text-[11px] tabular lg:right-3.5 lg:top-3.5',
                            'text-accent'    => $category['count'] > 0,
                            'text-ink-faint' => $category['count'] === 0,
                        ])>{{ $category['count'] }}</span>

                        <span class="text-[12.5px] font-semibold leading-tight lg:text-[13.5px]">
                            {{ $category['label'] }}
                        </span>
                    </a>
                @endforeach

                @for ($i = 0; $i < $padNarrow; $i++)
                    <span class="bg-canvas lg:hidden"></span>
                @endfor
                @for ($i = 0; $i < $padWide; $i++)
                    <span class="hidden bg-canvas lg:block"></span>
                @endfor

                @if ($other)
                    <a href="{{ route('browse', ['kat' => $other['key']]) }}" wire:navigate
                       class="group col-span-full flex items-center gap-3.5 bg-canvas px-3.5 py-3.5
                              transition-colors hover:bg-surface lg:px-[18px]">
                        @include('partials.category-icon', [
                            'category' => $other['key'],
                            'class'    => 'h-[22px] w-[22px] shrink-0 text-ink-muted transition group-hover:text-accent',
                        ])
                        <span class="text-[13px] font-semibold">
                            {{ $other['label'] }}
                            <span class="hidden font-normal text-ink-faint sm:inline">
                                — всичко останало, което не влиза в горните
                            </span>
                        </span>
                        <span @class([
                            'ml-auto font-mono text-[11px] tabular',
                            'text-accent'    => $other['count'] > 0,
                            'text-ink-faint' => $other['count'] === 0,
                        ])>{{ $other['count'] }}</span>
                    </a>
                @endif
            </div>
        </section>

        {{-- ── Sell ──────────────────────────────────────────────────────────
             One strip, and ABOVE the listings rather than below them. Supply is
             worth more than demand for the first year, and a visitor who has
             scrolled past a short grid has already decided the place is empty.
             It used to be a two-column card with three numbered steps; the ask
             survives as a sentence, which is all it ever needed to be. --}}
        <section class="pb-12 sm:pb-14">
            <div class="flex flex-wrap items-center justify-between gap-x-5 gap-y-4 rounded-xl border border-line
                        border-l-2 border-l-accent bg-surface py-4 pr-4 pl-5">
                <div>
                    <p class="text-[14.5px] font-semibold">Имаш видеокарта, която събира прах?</p>
                    <p class="mt-0.5 text-[12.5px] text-ink-muted">
                        Снимаш с телефона, избираш модела и спецификациите се попълват сами.
                        Купувачът плаща при получаване.
                    </p>
                </div>

                <a href="{{ auth()->check() ? route('listing.create') : route('register') }}" wire:navigate
                   class="btn-primary shrink-0">Пусни обява — безплатно</a>
            </div>
        </section>

        {{-- ── Paid positions ────────────────────────────────────────────────
             The homepage's one paid slot. LABELLED, SEPARATED, AND DISCLOSED:
             the heading says paid rather than recommended, and a sentence says
             plainly that the order is random among sellers who paid. The
             Omnibus Directive requires the consumer be told that payment
             decided what they are looking at.

             Shares its slot with the most-viewed rail below: paid when anybody
             bought, most-viewed when nobody did. See Home::mostViewed(). --}}
        @if ($promoted->isNotEmpty())
            <section class="pb-12 sm:pb-14">
                <div class="mb-2 flex items-baseline gap-3.5">
                    <h2 class="whitespace-nowrap text-[17px] font-bold tracking-tight">
                        <span class="mr-1.5 font-mono font-medium text-accent">//</span>Платени позиции
                    </h2>
                    <span class="h-px flex-1 self-center bg-line"></span>
                    <a href="{{ route('browse') }}" wire:navigate
                       class="whitespace-nowrap text-[12.5px] text-ink-muted transition hover:text-accent">Виж всички обяви →</a>
                </div>

                <p class="mb-5 max-w-2xl text-[12.5px] text-ink-faint">
                    Тези обяви са платени от продавачите си. Редът между тях е случаен,
                    а всички останали обяви на сайта се подреждат без заплащане.
                </p>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($promoted as $listing)
                        @include('partials.listing-card', ['listing' => $listing])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── Most viewed ───────────────────────────────────────────────────
             Most viewed, not featured - there is nothing curated here. Rendered
             only while the paid block above is empty; they share one slot. --}}
        @if ($mostViewed->isNotEmpty())
            <section class="pb-12 sm:pb-14">
                <div class="mb-5 flex items-baseline gap-3.5">
                    <h2 class="whitespace-nowrap text-[17px] font-bold tracking-tight">
                        <span class="mr-1.5 font-mono font-medium text-accent">//</span>Най-разглеждани
                    </h2>
                    <span class="h-px flex-1 self-center bg-line"></span>
                    <a href="{{ route('browse', ['sort' => 'views']) }}" wire:navigate
                       class="whitespace-nowrap text-[12.5px] text-ink-muted transition hover:text-accent">Виж всички →</a>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($mostViewed as $listing)
                        @include('partials.listing-card', ['listing' => $listing])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── Newest ────────────────────────────────────────────────────────
             The reason a returning visitor opens the site at all. It says so
             plainly when it is empty rather than disappearing: a missing
             section reads as a broken site, an honest empty one as a new one. --}}
        <section class="pb-12 sm:pb-14">
            <div class="mb-5 flex items-baseline gap-3.5">
                <h2 class="whitespace-nowrap text-[17px] font-bold tracking-tight">
                    <span class="mr-1.5 font-mono font-medium text-accent">//</span>Най-нови обяви
                </h2>
                <span class="h-px flex-1 self-center bg-line"></span>
                @if ($newest->isNotEmpty())
                    <a href="{{ route('browse') }}" wire:navigate
                       class="whitespace-nowrap text-[12.5px] text-ink-muted transition hover:text-accent">Виж всички →</a>
                @endif
            </div>

            @if ($newest->isNotEmpty())
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($newest as $listing)
                        @include('partials.listing-card', ['listing' => $listing])
                    @endforeach
                </div>
            @else
                <div class="flex flex-wrap items-center gap-x-6 gap-y-4 rounded-2xl border border-dashed border-line-strong px-6 py-9">
                    @include('partials.category-icon', ['category' => 'other', 'class' => 'h-9 w-9 shrink-0 text-ink-faint'])

                    <div class="min-w-[240px] flex-1">
                        <h3 class="text-[15.5px] font-semibold">Тук още няма обяви</h3>
                        {{-- The name comes from config, never from the view:
                             one test renames the site and asserts that no page
                             still says the old name. --}}
                        <p class="mt-1 max-w-[60ch] text-[13.5px] text-ink-muted">
                            {{ config('app.name') }} тръгва сега. Ако имаш какво да продадеш,
                            твоята може да е първата, която някой види.
                        </p>
                    </div>

                    <a href="{{ auth()->check() ? route('listing.create') : route('register') }}" wire:navigate
                       class="btn-primary shrink-0">Пусни първата обява</a>
                </div>
            @endif
        </section>

        {{-- ── Why this is not a general classifieds board ───────────────────
             Not marketing copy: these four are rules the code enforces. If any
             of them stops being true, the line comes out.

             The third one was rewritten on 18 Oct, when phone verification was
             switched off. It used to promise that every profile was confirmed
             by phone; what is still enforced is a confirmed mailbox before
             anybody can publish or deal. Put the old promise back the day
             remarket.verify.phone_enabled goes back to true. --}}
        <section class="pb-12 sm:pb-14">
            <div class="mb-5 flex items-baseline gap-3.5">
                <h2 class="whitespace-nowrap text-[17px] font-bold tracking-tight">
                    <span class="mr-1.5 font-mono font-medium text-accent">//</span>Защо не е като другите обяви
                </h2>
                <span class="hidden h-px flex-1 self-center bg-line sm:block"></span>
            </div>

            <div class="grid gap-y-7 border-t border-line pt-6 sm:grid-cols-2 sm:gap-x-8 lg:grid-cols-4 lg:gap-x-0">
                @foreach ([
                    ['Преглед и тест',
                     'Отваряш и пробваш вещта в офиса на куриера, преди да платиш.'],
                    ['Истински спецификации',
                     'Под 300 мм, 12 GB, без 12VHPWR — три клика, не четене на описания.'],
                    ['Профил с потвърден имейл',
                     'Без потвърден имейл не се публикува и не се сделкува.'],
                    ['Една цена, една оферта',
                     'Няма „последна цена?“. Оферта, отговор, най-много една насрещна.'],
                ] as $i => [$heading, $body])
                    <div @class([
                        'lg:pr-6',
                        'lg:border-l lg:border-line lg:pl-6' => $i > 0,
                    ])>
                        <p class="font-mono text-[11px] tracking-[0.08em] text-accent">{{ sprintf('%02d', $i + 1) }}</p>
                        <h3 class="mt-2.5 text-sm font-semibold">{{ $heading }}</h3>
                        <p class="mt-1.5 text-[13px] leading-relaxed text-ink-muted">{{ $body }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ── Popular models ────────────────────────────────────────────────
             Each chip points at the catalogue page for the model, not at a
             search for its name: a search is empty the week nobody is selling
             one, while the model page still carries the specs and the price
             band - and this page linking to it is how the catalogue gets
             crawled at all. --}}
        @if ($popularParts->isNotEmpty())
            <section class="pb-12 sm:pb-14">
                <div class="mb-5 flex items-baseline gap-3.5">
                    <h2 class="whitespace-nowrap text-[17px] font-bold tracking-tight">
                        <span class="mr-1.5 font-mono font-medium text-accent">//</span>Търсени модели
                    </h2>
                    <span class="h-px flex-1 self-center bg-line"></span>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach ($popularParts as $part)
                        <a href="{{ route('part', $part) }}" wire:navigate
                           class="inline-flex items-center gap-2 rounded-full border border-line px-3.5 py-1.5
                                  text-[13px] text-ink-muted transition hover:border-accent hover:text-accent">
                            {{ $part->fullName() }}
                            <span class="font-mono text-[11px] tabular text-ink-faint">{{ $part->live_count }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── The two doors ─────────────────────────────────────────────────
             The build guide is the one thing here a general classifieds board
             cannot copy, and Apple is three categories most people look for by
             brand first. --}}
        <section class="grid gap-4 pb-12 sm:grid-cols-2 sm:pb-14">
            <a href="{{ route('build') }}" wire:navigate
               class="group flex flex-col gap-2 rounded-2xl border border-line bg-surface p-6 transition hover:border-line-strong">
                <span class="font-mono text-[10.5px] uppercase tracking-[0.18em] text-accent">Само тук</span>
                <span class="text-[17px] font-bold tracking-tight transition-colors group-hover:text-accent">
                    Сглоби компютър от втора употреба
                </span>
                <span class="max-w-[52ch] text-[13.5px] leading-relaxed text-ink-muted">
                    Обявите знаят сокет, вата и размери — сайтът ти казва кои части си пасват.
                </span>
                <span class="mt-1.5 text-[12.5px] text-ink-muted">Виж машините →</span>
            </a>

            <a href="{{ route('apple') }}" wire:navigate
               class="group flex flex-col gap-2 rounded-2xl border border-line bg-surface p-6 transition hover:border-line-strong">
                <span class="font-mono text-[10.5px] uppercase tracking-[0.18em] text-accent">Специален раздел</span>
                <span class="text-[17px] font-bold tracking-tight transition-colors group-hover:text-accent">
                    Apple втора употреба
                </span>
                <span class="max-w-[52ch] text-[13.5px] leading-relaxed text-ink-muted">
                    iPhone, iPad и MacBook — с паметта и конфигурацията в каталога.
                </span>
                <span class="mt-1.5 text-[12.5px] text-ink-muted">Към раздела →</span>
            </a>
        </section>
    </div>
</div>
