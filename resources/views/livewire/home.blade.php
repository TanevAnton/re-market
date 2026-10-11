{{-- The layout wraps main in max-w-7xl px-4 py-6. Cancelling that here lets the
     hero and the trust band run the full width of the container while every
     other section keeps the site's normal measure. --}}
<div class="-mx-4 -mt-6">

    {{-- ── Hero ──────────────────────────────────────────────────────────────
         Search first, and almost nothing else. Somebody arriving on a
         marketplace has a thing in mind; the fastest route from here to that
         thing is the box, not a paragraph about what we believe in. --}}
    <section class="relative overflow-hidden">
        {{-- One soft emerald wash behind the fold, so the top of the page is
             not a flat black rectangle. --}}
        <div class="pointer-events-none absolute inset-x-0 -top-48 h-96 opacity-[0.16]"
             style="background: radial-gradient(60% 100% at 50% 100%, var(--accent), transparent 70%);"></div>

        <div class="relative mx-auto max-w-4xl px-4 pt-16 pb-14 text-center sm:pt-24 sm:pb-20">
            <p class="font-mono text-xs uppercase tracking-[0.2em] text-accent">
                Хардуер втора употреба
            </p>

            <h1 class="mx-auto mt-5 max-w-[21ch] text-4xl font-extrabold leading-[1.08] tracking-tight sm:text-6xl">
                Компютърни части с
                <span class="text-accent">истински спецификации</span>
            </h1>

            <p class="mx-auto mt-6 max-w-xl text-base leading-relaxed text-ink-muted sm:text-lg">
                Всяка обява е вързана за конкретен модел — филтрираш по чипсет, VRAM и
                дължина, а не по описание. Цената е една, пазарлъкът минава през оферта.
            </p>

            {{-- Straight to browse with ?q= — the same parameter the filters
                 already read from the URL, so search and filtering are one
                 screen rather than two. --}}
            <form method="GET" action="{{ route('browse') }}"
                  class="mx-auto mt-9 flex max-w-2xl items-center gap-2 rounded-2xl border border-line bg-surface p-2"
                  style="box-shadow: var(--shadow-card);">
                {{-- No `!` on any of these. The base input style lives in
                     @layer base and every utility here is in @layer utilities,
                     which wins on layer order in Tailwind 4 — an important
                     modifier would only hide that fact. --}}
                <input type="search" name="q" autocomplete="off"
                       placeholder="RTX 4070, ртх 4070, 7800X3D…"
                       class="min-w-0 flex-1 border-0 bg-transparent px-4 py-3 text-base
                              focus:border-0 focus:ring-0"
                       aria-label="Търсене на компоненти">
                <button type="submit" class="btn-primary shrink-0 px-7 py-3">Търси</button>
            </form>

            <div class="mt-5 flex flex-wrap items-center justify-center gap-2 text-xs">
                <span class="text-ink-faint">Популярно:</span>
                @foreach (['RTX 4070', 'RX 7800 XT', 'Ryzen 7 5800X3D', 'DDR5 32GB'] as $suggestion)
                    <a href="{{ route('browse', ['q' => $suggestion]) }}" wire:navigate
                       class="rounded-full border border-line px-3 py-1.5 text-ink-muted transition
                              hover:border-accent hover:text-accent">
                        {{ $suggestion }}
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <div class="mx-auto max-w-7xl px-4">

        {{-- ── Categories ────────────────────────────────────────────────────
             The primary navigation of the whole site, and the first thing a
             visitor scans to decide whether this place has what they want. Big
             icons on purpose: shape is faster to scan than a word, and these
             are the only nineteen links that matter on the page.

             „Други" is pulled out of the grid and laid across the bottom. It is
             the catch-all, it is nobody's destination, and left in the grid it
             sat alone on a second row looking like an orphan. --}}
        @php
            $tiles = collect($categories);
            $other = $tiles->firstWhere('key', 'other');
            $tiles = $tiles->reject(fn ($c) => $c['key'] === 'other');
        @endphp

        <section class="py-16 sm:py-20">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">Категории</h2>
                    <p class="mt-2 text-sm text-ink-muted">Започни оттук — или търси директно горе.</p>
                </div>
                <a href="{{ route('browse') }}" wire:navigate class="link text-sm">Всички обяви →</a>
            </div>

            <div class="mt-9 grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-5 lg:gap-4 xl:grid-cols-6">
                @foreach ($tiles as $category)
                    <a href="{{ route('browse', ['kat' => $category['key']]) }}" wire:navigate
                       class="card-interactive group flex flex-col items-center gap-4 px-3 pt-7 pb-6 text-center">
                        {{-- The icon inherits `currentColor` from this span, which is
                             the whole reason it is drawn rather than loaded: the
                             hover state flips the text colour and the drawing flips
                             with it, with no second asset. --}}
                        <span class="grid h-16 w-16 place-items-center rounded-2xl bg-surface-alt text-ink-muted
                                     transition-colors group-hover:bg-accent group-hover:text-[var(--accent-ink)]
                                     sm:h-20 sm:w-20">
                            @include('partials.category-icon', [
                                'category' => $category['key'],
                                'class'    => 'h-8 w-8 sm:h-9 sm:w-9',
                            ])
                        </span>

                        <span class="min-w-0">
                            <span class="block text-[13px] font-semibold leading-snug sm:text-sm">
                                {{ $category['label'] }}
                            </span>
                            <span class="mt-1 block font-mono text-[11px] tabular text-ink-faint">
                                {{ $category['count'] }}
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>

            @if ($other)
                <a href="{{ route('browse', ['kat' => $other['key']]) }}" wire:navigate
                   class="card-interactive group mt-3 flex items-center justify-center gap-4 p-5 lg:mt-4">
                    <span class="grid h-14 w-14 shrink-0 place-items-center rounded-xl bg-surface-alt text-ink-muted
                                 transition-colors group-hover:bg-accent group-hover:text-[var(--accent-ink)]">
                        @include('partials.category-icon', ['category' => $other['key'], 'class' => 'h-7 w-7'])
                    </span>
                    <span>
                        <span class="block text-sm font-semibold">{{ $other['label'] }}</span>
                        <span class="mt-1 block text-[13px] text-ink-faint">
                            Всичко останало, което не влиза в горните
                        </span>
                    </span>
                </a>
            @endif
        </section>
    </div>

    {{-- ── Why this is not OLX ───────────────────────────────────────────────
         Not marketing copy: these four are the actual rules the code enforces,
         and they are the entire reason to use this instead of a general
         classifieds board. If any of them stops being true, this section is a
         lie and it comes out.

         Full-bleed band on its own surface, so it reads as the site speaking
         rather than as four more cards in the stack. --}}
    <section class="border-y border-line bg-surface">
        <div class="mx-auto max-w-7xl px-4">
            <div class="py-12 sm:py-14">
                <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">Защо не е като другите обяви</h2>
            </div>

            <div class="grid border-t border-line sm:grid-cols-2">
                @php
                    $promises = [
                        ['shield-check', 'Преглед и тест',
                         'Отваряш и пробваш вещта в офиса на куриера, преди да платиш. Продавач, който отказва, е отбелязан на обявата.'],
                        ['filters', 'Филтри по истински спецификации',
                         'Видеокарта под 300 мм, с 12 GB и без 12VHPWR — три клика. В обява на свободен текст е невъзможно.'],
                        ['phone-check', 'Един профил на номер',
                         'Всеки профил е потвърден с телефон, а номерът не се пази в четим вид. Ботовете не минават.'],
                        ['handshake', 'Една цена, една оферта',
                         'Няма „последна цена?“. Купувачът праща оферта, продавачът приема, отказва или праща насрещна — веднъж.'],
                    ];
                @endphp

                @foreach ($promises as $i => [$icon, $heading, $body])
                    <div @class([
                        'flex items-start gap-5 px-4 py-9 sm:gap-6 sm:px-8 sm:py-11',
                        'sm:border-r sm:border-line'   => $i % 2 === 0,
                        'border-b border-line'            => $i < count($promises) - 2,
                        'border-b border-line sm:border-b-0' => $i === count($promises) - 2,
                    ])>
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-accent-soft text-accent">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="h-6 w-6">
                                @switch($icon)
                                    @case('shield-check')
                                        <path d="M12 3l8 3v6c0 4.4-3.2 7.9-8 9-4.8-1.1-8-4.6-8-9V6l8-3z"/>
                                        <path d="m9 12 2 2 4-4"/>
                                        @break
                                    @case('filters')
                                        <path d="M4 7h16M7 12h10M10 17h4"/>
                                        @break
                                    @case('phone-check')
                                        <rect x="7" y="2.5" width="10" height="19" rx="2.5"/>
                                        <path d="M10.5 18.5h3"/>
                                        <path d="m9.5 9 1.8 1.8L15 7"/>
                                        @break
                                    @default
                                        <path d="M3 12.5 7 9l3 2.5"/>
                                        <path d="M10 11.5h5.5a2 2 0 0 1 0 4H13"/>
                                        <path d="M21 11.5 17 15l-3-2.5"/>
                                @endswitch
                            </svg>
                        </span>

                        <div>
                            <h3 class="text-base font-semibold tracking-tight sm:text-[17px]">{{ $heading }}</h3>
                            <p class="mt-2 max-w-[44ch] text-sm leading-relaxed text-ink-muted sm:text-[15px]">
                                {{ $body }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <div class="mx-auto max-w-7xl px-4">

        {{-- ── Sell ──────────────────────────────────────────────────────────
             ABOVE the listings, not below them. Supply is worth more than
             demand for the first year, and a visitor who scrolls past an
             eight-item grid has already decided this place is empty. The ask
             comes while they are still reading. --}}
        <section class="py-16 sm:py-20">
            <div class="card grid gap-10 p-7 sm:p-12 lg:grid-cols-2 lg:gap-16">
                <div class="self-center">
                    <h2 class="max-w-[16ch] text-2xl font-bold leading-tight tracking-tight sm:text-3xl">
                        Имаш видеокарта, която събира прах?
                    </h2>
                    <p class="mt-4 max-w-[42ch] text-[15px] leading-relaxed text-ink-muted sm:text-base">
                        Повечето хора, които смениха картата си миналата година, още пазят
                        старата в чекмедже. Тя струва пари и някой я търси точно сега.
                    </p>

                    @auth
                        <a href="{{ route('listing.create') }}" wire:navigate class="btn-primary mt-7 px-6 py-3">
                            Пусни обява — безплатно
                        </a>
                    @else
                        <a href="{{ route('register') }}" wire:navigate class="btn-primary mt-7 px-6 py-3">
                            Пусни обява — безплатно
                        </a>
                    @endauth
                </div>

                <ol class="flex flex-col gap-7 self-center">
                    @foreach ([
                        ['Снимай с телефона', 'Три снимки стигат. Качваш ги директно от телефона.'],
                        ['Избираш модела', 'Пишеш „RTX 3070“ и спецификациите се попълват сами.'],
                        ['Купувачът плаща при получаване', 'Парите минават през куриера. Платформата не ги докосва.'],
                    ] as $i => [$step, $detail])
                        <li class="flex items-start gap-4">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-accent-soft
                                         font-mono text-sm font-semibold text-accent">{{ $i + 1 }}</span>
                            <div>
                                <h3 class="text-[15px] font-semibold">{{ $step }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-ink-muted">{{ $detail }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- ── Paid positions ────────────────────────────────────────────────
             The homepage's one paid slot, and the most valuable placement on
             the site: one audience, shared by every category.

             LABELLED, SEPARATED, AND DISCLOSED — „Платени позиции" over it
             rather than „Препоръчани", a border between it and everything else,
             and a sentence saying plainly that the order is random among
             sellers who paid. The Omnibus Directive requires the consumer be
             told that payment decided what they are looking at; on the front
             page that requirement and plain honesty say the same thing.

             Shares its slot with „Най-разглеждани" below: paid when anybody
             bought, most-viewed when nobody did. See Home::mostViewed(). --}}
        @if ($promoted->isNotEmpty())
            <section class="border-t border-line py-16 sm:py-20">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">Платени позиции</h2>
                        <p class="mt-2 max-w-2xl text-sm text-ink-muted">
                            Тези обяви са платени от продавачите си. Редът между тях е случаен,
                            а всички останали обяви на сайта се подреждат без заплащане.
                        </p>
                    </div>
                    <a href="{{ route('browse') }}" wire:navigate class="link text-sm">Виж всички обяви →</a>
                </div>

                <div class="mt-9 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($promoted as $listing)
                        @include('partials.listing-card', ['listing' => $listing])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── Most viewed ───────────────────────────────────────────────────
             „Most viewed", not „featured" — there is nothing curated here and
             saying otherwise on the front page would be a small lie. Rendered
             only while the paid block above is empty; they share one slot. --}}
        @if ($mostViewed->isNotEmpty())
            <section class="border-t border-line py-16 sm:py-20">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">Най-разглеждани</h2>
                    <a href="{{ route('browse', ['sort' => 'views']) }}" wire:navigate class="link text-sm">
                        Виж всички →
                    </a>
                </div>

                <div class="mt-9 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($mostViewed as $listing)
                        @include('partials.listing-card', ['listing' => $listing])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── Newest ────────────────────────────────────────────────────────
             The reason a returning visitor opens the site at all — and, while
             the catalogue is this young, the section a first visitor uses to
             decide whether anybody is here. So it says so plainly when it is
             empty rather than disappearing: a missing section reads as a broken
             site, while an honest empty one reads as a new one. --}}
        <section class="border-t border-line py-16 sm:py-20">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">Най-нови обяви</h2>
                    @if ($newest->isNotEmpty())
                        <p class="mt-2 text-sm text-ink-muted">Най-новото, качено от продавачи в България.</p>
                    @endif
                </div>
                @if ($newest->isNotEmpty())
                    <a href="{{ route('browse') }}" wire:navigate class="link text-sm">Виж всички →</a>
                @endif
            </div>

            @if ($newest->isNotEmpty())
                <div class="mt-9 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($newest as $listing)
                        @include('partials.listing-card', ['listing' => $listing])
                    @endforeach
                </div>
            @else
                <div class="mt-9 rounded-2xl border border-dashed border-line-strong px-6 py-14 text-center">
                    <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-surface-alt text-ink-faint">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="h-8 w-8">
                            <rect x="3" y="7" width="18" height="13" rx="2"/>
                            <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                    </span>
                    <h3 class="mt-5 text-lg font-semibold tracking-tight">Тук още няма обяви</h3>
                    <p class="mx-auto mt-3 max-w-[46ch] text-[15px] text-ink-muted">
                        {{-- The name comes from config, never from the view: BrandNameTest
                             renames the site and asserts no page says the old one. --}}
                        {{ config('app.name') }} тръгва сега. Първите обяви са на път — а ако имаш какво да продадеш,
                        твоята може да е първата, която някой види.
                    </p>
                    <a href="{{ auth()->check() ? route('listing.create') : route('register') }}" wire:navigate
                       class="btn-primary mt-6 px-6 py-3">Пусни първата обява</a>
                </div>
            @endif
        </section>

        {{-- ── Popular models ────────────────────────────────────────────────
             Each chip points at the catalogue page for the model, not at a
             search for its name. That is the whole difference: a search for
             „RTX 4070" is empty the week nobody is selling one, while the model
             page still carries the specs, the price band and somewhere to leave
             an alert — and the home page linking to it is how the catalogue
             gets crawled at all. --}}
        @if ($popularParts->isNotEmpty())
            <section class="border-t border-line py-16 sm:py-20">
                <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">Търсени модели</h2>

                <div class="mt-7 flex flex-wrap gap-2.5">
                    @foreach ($popularParts as $part)
                        <a href="{{ route('part', $part) }}" wire:navigate
                           class="inline-flex items-center gap-2 rounded-full border border-line bg-surface
                                  px-4 py-2 text-sm transition hover:border-accent hover:text-accent">
                            {{ $part->fullName() }}
                            <span class="font-mono text-[11px] tabular text-ink-faint">{{ $part->live_count }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── The two doors ─────────────────────────────────────────────────
             Both answer „what else can I do here". The build guide is the one
             thing on this site a general classifieds board cannot copy, and
             Apple is three categories most people look for by brand first. --}}
        <section class="grid gap-5 border-t border-line py-16 sm:grid-cols-2 sm:py-20">

            <a href="{{ route('build') }}" wire:navigate
               class="card-interactive group flex flex-col p-7 sm:p-9">
                <span class="font-mono text-[11px] font-semibold uppercase tracking-[0.18em] text-accent">
                    Само тук
                </span>
                <span class="mt-3 text-xl font-bold tracking-tight transition-colors group-hover:text-accent sm:text-2xl">
                    Сглоби компютър от втора употреба
                </span>
                <span class="mt-3 text-[15px] leading-relaxed text-ink-muted">
                    Обявите тук знаят сокет, вата и размери — затова сайтът може да ти каже
                    кои части си пасват. Виж цели машини, сглобени от обяви, налични в момента.
                </span>
                <span class="link mt-5 text-sm">Виж машините →</span>
            </a>

            <a href="{{ route('apple') }}" wire:navigate
               class="card-interactive group flex flex-col p-7 sm:p-9">
                <span class="font-mono text-[11px] font-semibold uppercase tracking-[0.18em] text-accent">
                    Специален раздел
                </span>
                <span class="mt-3 text-xl font-bold tracking-tight transition-colors group-hover:text-accent sm:text-2xl">
                    Apple втора употреба
                </span>
                <span class="mt-3 text-[15px] leading-relaxed text-ink-muted">
                    iPhone, iPad и MacBook — с паметта и конфигурацията в каталога, и с
                    въпросите, които решават дали устройството изобщо ще проработи при теб.
                </span>

                <span class="mt-5 flex items-center gap-4 text-ink-faint">
                    @include('partials.category-icon', ['category' => 'iphone', 'class' => 'h-7 w-7'])
                    @include('partials.category-icon', ['category' => 'ipad', 'class' => 'h-7 w-7'])
                    @include('partials.category-icon', ['category' => 'macbook', 'class' => 'h-7 w-7'])
                    <span class="link ml-auto text-sm">Към раздела →</span>
                </span>
            </a>
        </section>
    </div>
</div>
