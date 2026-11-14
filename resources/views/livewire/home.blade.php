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
         would be cut in a straight line on a wide monitor.

         THE CLIPPING IS ON THE BACKDROP, NOT ON THE BAND. It used to be on the
         section itself, which was fine until the search grew a list that drops
         below it - a clipped band cuts that list off at its own bottom edge.
         The band is also lifted one layer so the list lies over the categories
         underneath; it stays below the sticky header, which is one layer up. --}}
    <section class="relative z-20">
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="absolute inset-0"
                 style="background-image: linear-gradient(var(--grid) 1px, transparent 1px),
                                          linear-gradient(90deg, var(--grid) 1px, transparent 1px);
                        background-size: 44px 44px;
                        -webkit-mask-image: radial-gradient(30% 130% at 32% 0%, #000 0%, transparent 100%);
                                mask-image: radial-gradient(30% 130% at 32% 0%, #000 0%, transparent 100%);"></div>
            <div class="absolute left-[6%] -top-36 h-72 w-[46%] opacity-[0.28]"
                 style="background: radial-gradient(closest-side, var(--glow), transparent);"></div>
        </div>

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

            {{-- The search field, its suggestions and the shortcuts under it
                 are a component of their own - see HomeSearch for why. It is
                 still a plain form to the browse page underneath. --}}
            <livewire:home-search />
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
                       class="group relative flex min-h-[116px] flex-col justify-between gap-3 bg-canvas p-3.5
                              transition-colors hover:bg-surface lg:min-h-[136px] lg:p-[18px]">

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
                            'class'    => 'h-[34px] w-[34px] text-ink-muted transition duration-200 lg:h-[42px] lg:w-[42px] '
                                         .'group-hover:-translate-y-px group-hover:text-accent '
                                         .'group-hover:[filter:drop-shadow(0_0_7px_var(--glow))]',
                        ])

                        {{-- A number only where there is something to count.
                             Every category is still here - an empty one says
                             what the site is for - but nineteen zeros in a
                             grid say the site is dead, which is a different
                             and louder message. With the zeros gone, the
                             accent marks exactly the tiles that have stock. --}}
                        @if ($category['count'] > 0)
                            <span data-tile-count="{{ $category['count'] }}"
                                  class="absolute right-2.5 top-2.5 font-mono text-[11px] tabular text-accent
                                         lg:right-3.5 lg:top-3.5">{{ $category['count'] }}</span>
                        @endif

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
                            'class'    => 'h-[28px] w-[28px] shrink-0 text-ink-muted transition group-hover:text-accent',
                        ])
                        <span class="text-[13px] font-semibold">
                            {{ $other['label'] }}
                            <span class="hidden font-normal text-ink-faint sm:inline">
                                — всичко останало, което не влиза в горните
                            </span>
                        </span>
                        @if ($other['count'] > 0)
                            <span data-tile-count="{{ $other['count'] }}"
                                  class="ml-auto font-mono text-[11px] tabular text-accent">{{ $other['count'] }}</span>
                        @endif
                    </a>
                @endif
            </div>
        </section>

        {{-- ── Sell, and ask ─────────────────────────────────────────────────
             One strip, and ABOVE the listings rather than below them. Supply is
             worth more than demand for the first year, and a visitor who has
             scrolled past a short grid has already decided the place is empty.

             TWO HALVES NOW, because the page used to have an answer for only
             one of the two people who arrive at a thin marketplace. The seller
             got a button. The buyer who did not find the card got nothing, and
             left - which is the worst outcome available, since „Търся" turns
             exactly that visit into a notification for the next seller who has
             one. The selling half stays first and stays the filled button; the
             asking half is the quiet one.

             Same construction as the category panel: one bordered box, and the
             divider is its own background showing through a 1px gap, so it is
             a horizontal rule when the halves stack and a vertical one when
             they sit side by side, with no breakpoint-specific border. --}}
        <section class="pb-12 sm:pb-14">
            <div class="grid gap-px overflow-hidden rounded-xl border border-line border-l-2 border-l-accent bg-line
                        lg:grid-cols-[1.35fr_1fr]">
                <div class="flex flex-wrap items-center justify-between gap-x-5 gap-y-4 bg-surface py-4 pr-4 pl-5">
                    <div class="min-w-[220px] flex-1">
                        <p class="text-[14.5px] font-semibold">Имаш видеокарта, която събира прах?</p>
                        <p class="mt-0.5 text-[12.5px] text-ink-muted">
                            Снимаш с телефона, избираш модела и спецификациите се попълват сами.
                            Купувачът плаща при получаване.
                        </p>
                    </div>

                    <a href="{{ auth()->check() ? route('listing.create') : route('register') }}" wire:navigate
                       class="btn-primary shrink-0">Пусни обява — безплатно</a>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-x-5 gap-y-4 bg-surface py-4 pr-4 pl-5">
                    <div class="min-w-[200px] flex-1">
                        <p class="text-[14.5px] font-semibold">Не намираш каквото търсиш?</p>
                        <p class="mt-0.5 text-[12.5px] text-ink-muted">
                            Кажи модела и до каква цена. Продавачите, които имат такава част,
                            получават известие.
                        </p>
                    </div>

                    <a href="{{ auth()->check() ? route('wanted.create') : route('register') }}" wire:navigate
                       class="btn-secondary shrink-0">Пусни „Търся“</a>
                </div>
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

        {{-- ── What buyers are asking for ────────────────────────────────────
             Demand, shown to the people who can meet it. Somebody with a card
             in a drawer has no reason to post it to an empty-looking site; a
             named person offering a named price for that exact card is a
             reason. It is also real content on a page that is short of it -
             every line here was written by a buyer, none of it by us.

             Renders nothing when nobody is asking. The strip above already
             makes the invitation; a heading over an empty row would only
             repeat it more quietly.

             Deliberately not called „Търсени": that word is already the
             heading of the popular-models row further down, and two sections
             a scroll apart with nearly the same name read as one mistake. --}}
        @if ($wanted->isNotEmpty())
            <section class="pb-12 sm:pb-14">
                <div class="mb-5 flex items-baseline gap-3.5">
                    <h2 class="whitespace-nowrap text-[17px] font-bold tracking-tight">
                        <span class="mr-1.5 font-mono font-medium text-accent">//</span>Купувачи търсят
                    </h2>
                    <span class="h-px flex-1 self-center bg-line"></span>
                    <a href="{{ route('wanted') }}" wire:navigate
                       class="whitespace-nowrap text-[12.5px] text-ink-muted transition hover:text-accent">Всички търсения →</a>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($wanted as $ad)
                        @include('partials.wanted-card', ['ad' => $ad])
                    @endforeach
                </div>
            </section>
        @endif

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
