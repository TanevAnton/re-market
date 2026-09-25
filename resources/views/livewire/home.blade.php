{{-- The layout wraps main in max-w-7xl px-4 py-6. Cancelling that here lets
     the hero run edge to edge inside the container while every section below
     keeps the site's normal measure. --}}
<div class="-mx-4 -mt-6">

    {{-- ── Hero ──────────────────────────────────────────────────────────────
         Short, because nobody reads a marketplace's mission statement. One
         line saying what this is, one saying what is different, and then the
         search box - which is what most people came to use. --}}
    <section class="relative overflow-hidden border-b border-line">
        {{-- A single soft emerald wash behind the fold. Cheap, and it stops the
             top of the page reading as a flat black rectangle. --}}
        <div class="pointer-events-none absolute inset-x-0 -top-40 h-80 opacity-[0.18]"
             style="background: radial-gradient(60% 100% at 50% 100%, var(--accent), transparent 70%);"></div>

        <div class="relative mx-auto max-w-4xl px-4 py-14 text-center sm:py-20">
            <p class="font-mono text-xs uppercase tracking-[0.2em] text-accent">
                Хардуер втора употреба
            </p>

            <h1 class="mx-auto mt-4 max-w-3xl text-3xl font-extrabold leading-[1.15] tracking-tight sm:text-5xl">
                Компютърни части с
                <span class="text-accent">истински спецификации</span>
                и без пазарлък в чата
            </h1>

            <p class="mx-auto mt-5 max-w-2xl text-base leading-relaxed text-ink-muted">
                Всяка обява е свързана с конкретен модел, така че филтрираш по чипсет, VRAM
                и дължина, а не по описание. Цената е една, а преговорът минава през оферта.
            </p>

            {{-- Straight to browse with ?q= - the same parameter the filters
                 already read from the URL, so search and filtering are one
                 screen rather than two. --}}
            <form method="GET" action="{{ route('browse') }}" class="mx-auto mt-8 flex max-w-xl gap-2">
                <input type="search" name="q" autocomplete="off"
                       placeholder="RTX 4070, ртх 4070, 7800X3D…"
                       class="flex-1 !py-3 text-base"
                       aria-label="Търсене на компоненти">
                <button type="submit" class="btn-primary shrink-0 px-6">Търси</button>
            </form>

            <div class="mt-4 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-xs text-ink-faint">
                <span>Популярно:</span>
                @foreach (['RTX 4070', 'RX 7800 XT', 'Ryzen 7 5800X3D', 'DDR5 32GB'] as $suggestion)
                    <a href="{{ route('browse', ['q' => $suggestion]) }}" wire:navigate
                       class="rounded-full border border-line px-2.5 py-1 transition hover:border-accent hover:text-accent">
                        {{ $suggestion }}
                    </a>
                @endforeach
            </div>

            {{-- Three numbers. The middle one is the only one that cannot be
                 inflated without real transactions. --}}
            <dl class="mx-auto mt-10 grid max-w-lg grid-cols-3 gap-4 border-t border-line pt-6">
                <div>
                    <dt class="text-[11px] uppercase tracking-wider text-ink-faint">Активни обяви</dt>
                    <dd class="mt-1 font-mono text-xl font-bold tabular">{{ $stats['listings'] }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider text-ink-faint">Сделки</dt>
                    <dd class="mt-1 font-mono text-xl font-bold tabular text-accent">{{ $stats['deals'] }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider text-ink-faint">Продавачи</dt>
                    <dd class="mt-1 font-mono text-xl font-bold tabular">{{ $stats['sellers'] }}</dd>
                </div>
            </dl>
        </div>
    </section>

    <div class="mx-auto max-w-7xl px-4">

        {{-- ── Categories ────────────────────────────────────────────────────
             The primary navigation of the whole site, and the thing a first
             visitor scans to decide whether this place has what they want. --}}
        <section class="py-12">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-xl font-bold tracking-tight">Категории</h2>
                <a href="{{ route('browse') }}" wire:navigate class="link text-sm">Всички обяви →</a>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                @foreach ($categories as $category)
                    <a href="{{ route('browse', ['kat' => $category['key']]) }}" wire:navigate
                       class="card-interactive group flex items-center gap-3 p-3">
                        {{-- The icon inherits `currentColor` from this span, which is
                             the whole reason it is drawn rather than loaded: the
                             hover state already flips the text colour, so the
                             drawing flips with it and there is no second asset. --}}
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-surface-alt
                                     text-ink-muted transition-colors
                                     group-hover:bg-accent group-hover:text-[var(--accent-ink)]">
                            @include('partials.category-icon', [
                                'category' => $category['key'],
                                'class'    => 'h-5 w-5',
                            ])
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold">{{ $category['label'] }}</span>
                            <span class="block font-mono text-[11px] tabular text-ink-faint">
                                {{ $category['count'] }}
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>

        {{-- ── The two doors ─────────────────────────────────────────────────
             Directly under the categories, because both answer „what can I
             actually do here" and the category grid only answers „what is
             here".

             The build guide is the one thing on this site a general classifieds
             board cannot copy, and until now it existed only as small links at
             the bottom of listing pages — where it answers a question the
             visitor did not arrive with. Apple is the same problem in the other
             direction: three categories that most people look for by brand
             first, reachable only by knowing to scroll the grid. --}}
        <section class="grid gap-4 border-t border-line py-12 sm:grid-cols-2">

            <a href="{{ route('build') }}" wire:navigate
               class="card-interactive group flex flex-col p-6">
                <span class="font-mono text-[11px] font-semibold uppercase tracking-[0.18em] text-accent">
                    Само тук
                </span>
                <span class="mt-2 text-xl font-bold tracking-tight transition-colors group-hover:text-accent">
                    Сглоби компютър от втора употреба
                </span>
                <span class="mt-2 text-sm leading-relaxed text-ink-muted">
                    Обявите тук знаят сокет, вата и размери — затова сайтът може да
                    ти каже кои части си пасват. Виж цели машини, сглобени от обяви,
                    които са налични в момента.
                </span>
                <span class="link mt-4 text-sm">Виж машините →</span>
            </a>

            <a href="{{ route('apple') }}" wire:navigate
               class="card-interactive group flex flex-col p-6">
                <span class="font-mono text-[11px] font-semibold uppercase tracking-[0.18em] text-accent">
                    Специален раздел
                </span>
                <span class="mt-2 text-xl font-bold tracking-tight transition-colors group-hover:text-accent">
                    Apple втора употреба
                </span>
                <span class="mt-2 text-sm leading-relaxed text-ink-muted">
                    iPhone, iPad и MacBook — с паметта и конфигурацията в каталога,
                    и с въпросите, които решават дали устройството изобщо ще
                    проработи при теб.
                </span>

                <span class="mt-4 flex items-center gap-3 text-ink-faint">
                    @include('partials.category-icon', ['category' => 'iphone', 'class' => 'h-6 w-6'])
                    @include('partials.category-icon', ['category' => 'ipad', 'class' => 'h-6 w-6'])
                    @include('partials.category-icon', ['category' => 'macbook', 'class' => 'h-6 w-6'])
                    <span class="link ml-auto text-sm">Към раздела →</span>
                </span>
            </a>
        </section>

        {{-- ── Newest ────────────────────────────────────────────────────────
             The reason a returning visitor opens the site at all. --}}
        @if ($newest->isNotEmpty())
            <section class="border-t border-line py-12">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="text-xl font-bold tracking-tight">Най-нови обяви</h2>
                    <a href="{{ route('browse') }}" wire:navigate class="link text-sm">Виж всички →</a>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($newest as $listing)
                        @include('partials.listing-card', ['listing' => $listing])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── How it works ──────────────────────────────────────────────────
             Not marketing copy: these four are the actual rules the code
             enforces, and they are the entire reason to use this instead of
             OLX. If any of them stops being true, this section is a lie. --}}
        <section class="border-t border-line py-12">
            <h2 class="text-xl font-bold tracking-tight">Защо не е като другите обяви</h2>

            <div class="mt-5 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <div class="card-pad">
                    <p class="font-mono text-xs uppercase tracking-wider text-accent">01</p>
                    <h3 class="mt-2 text-sm font-bold">Една цена, една оферта</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-muted">
                        Няма „последна цена?“. Купувачът праща оферта, продавачът приема,
                        отказва или праща насрещна — веднъж.
                    </p>
                </div>

                <div class="card-pad">
                    <p class="font-mono text-xs uppercase tracking-wider text-accent">02</p>
                    <h3 class="mt-2 text-sm font-bold">Филтри по истински спецификации</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-muted">
                        Видеокарта под 300 мм с 12 GB и без 12VHPWR — три клика.
                        В обява на свободен текст е невъзможно.
                    </p>
                </div>

                <div class="card-pad">
                    <p class="font-mono text-xs uppercase tracking-wider text-accent">03</p>
                    <h3 class="mt-2 text-sm font-bold">Потвърден телефон, един профил</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-muted">
                        Един номер — един профил. Номерът не се съхранява в четим вид,
                        а блокирането има цена.
                    </p>
                </div>

                <div class="card-pad">
                    <p class="font-mono text-xs uppercase tracking-wider text-accent">04</p>
                    <h3 class="mt-2 text-sm font-bold">Преглед и тест преди плащане</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-muted">
                        Пускаш картата в офиса на куриера и чак тогава плащаш.
                        Продавач, който отказва, е отбелязан на обявата.
                    </p>
                </div>
            </div>
        </section>

        {{-- ── Paid positions ────────────────────────────────────────────────
             The homepage's one paid slot, and the most valuable placement on
             the site: one audience, shared by every category.

             LABELLED, SEPARATED, AND DISCLOSED — the same three rules as the
             browse block. „Платени позиции" over it rather than „Препоръчани",
             a border between it and everything else, and a sentence saying
             plainly that the order is random among sellers who paid. The
             Omnibus Directive requires the consumer be told that payment
             decided what they are looking at; on the front page of the site
             that requirement and simple honesty happen to say the same thing.

             Shares its slot with „Най-разглеждани" below: paid when anybody
             bought, most-viewed when nobody did. See Home::mostViewed(). --}}
        @if ($promoted->isNotEmpty())
            <section class="border-t border-line py-12">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="text-xl font-bold tracking-tight">Платени позиции</h2>
                    <a href="{{ route('browse') }}" wire:navigate class="link text-sm">
                        Виж всички обяви →
                    </a>
                </div>

                <p class="hint mt-1 max-w-2xl">
                    Тези обяви са платени от продавачите си. Редът между тях е
                    случаен, а всички останали обяви на сайта се подреждат без
                    заплащане.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($promoted as $listing)
                        @include('partials.listing-card', ['listing' => $listing])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── Most viewed ───────────────────────────────────────────────────
             "Most viewed", not "featured" - there is nothing curated here and
             saying otherwise on the front page would be a small lie. Rendered
             only when the paid block above is empty; they share one slot. --}}
        @if ($mostViewed->isNotEmpty())
            <section class="border-t border-line py-12">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="text-xl font-bold tracking-tight">Най-разглеждани</h2>
                    <a href="{{ route('browse', ['sort' => 'views']) }}" wire:navigate class="link text-sm">
                        Виж всички →
                    </a>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($mostViewed as $listing)
                        @include('partials.listing-card', ['listing' => $listing])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── Popular models ────────────────────────────────────────────────
             The seed of the SEO play: each chip is a query that becomes a part
             landing page later. --}}
        @if ($popularParts)
            <section class="border-t border-line py-12">
                <h2 class="text-xl font-bold tracking-tight">Търсени модели</h2>

                <div class="mt-5 flex flex-wrap gap-2">
                    @foreach ($popularParts as $part)
                        <a href="{{ route('part', $part) }}" wire:navigate
                           class="inline-flex items-center gap-2 rounded-full border border-line bg-surface
                                  px-3 py-1.5 text-sm transition hover:border-accent hover:text-accent">
                            {{ $part->fullName() }}
                            <span class="font-mono text-[11px] tabular text-ink-faint">{{ $part->live_count }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── Sell ──────────────────────────────────────────────────────────
             Supply is worth more than demand for the first year, so the seller
             CTA gets the closing slot rather than being a link in the nav. --}}
        <section class="border-t border-line py-12">
            <div class="card-pad flex flex-col items-start gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl font-bold tracking-tight">Имаш част за продан?</h2>
                    <p class="mt-2 max-w-xl text-sm leading-relaxed text-ink-muted">
                        Избираш модела от каталога и спецификациите се попълват сами.
                        Публикуването е безплатно и отнема няколко минути.
                    </p>
                </div>

                @auth
                    <a href="{{ route('listing.create') }}" wire:navigate class="btn-primary shrink-0">
                        Публикувай обява
                    </a>
                @else
                    <a href="{{ route('register') }}" wire:navigate class="btn-primary shrink-0">
                        Създай профил
                    </a>
                @endauth
            </div>
        </section>
    </div>
</div>
