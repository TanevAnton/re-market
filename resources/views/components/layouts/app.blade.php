@php
    /*
     * The theme is decided on the SERVER, from a cookie, so the very first byte
     * of HTML already carries it. It used to live in localStorage, which cannot
     * be read until the page is running - by which point the server has already
     * committed to a guess, and any disagreement shows up as a flip on refresh.
     *
     * Three states, and DARK IS THE DEFAULT - no cookie means dark, not
     * "whatever the operating system says". That is a deliberate product
     * choice rather than a technical one: the palette is designed dark first,
     * and a first-time visitor should see the site as it was meant to look.
     * "Follow the system" stays reachable as the third position on the toggle,
     * so nobody is trapped in a theme they did not pick.
     */
    $theme = in_array($cookie = request()->cookie('theme'), ['dark', 'light', 'system'], true)
        ? $cookie
        : 'dark';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="h-full{{ $theme === 'dark' ? ' dark' : '' }}"
      data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>

    {{-- Per-page SEO.
         --------------------------------------------------------------------
         Every one of these is optional and every one of them is absent by
         default, because a wrong canonical or a boilerplate description
         repeated on ten thousand pages does more damage than none at all.
         Pages that have something specific to say (the part landing pages,
         listings) pass it; the rest stay quiet.

         The description is truncated rather than trusted: Google cuts around
         160 characters and a sentence chopped mid-word in the results looks
         like a broken site. --}}
    @isset($description)
        <meta name="description" content="{{ Str::limit(strip_tags($description), 155) }}">
    @endisset

    {{-- Filters produce a combinatorial number of URLs for the same listings.
         Without a canonical, every one of them competes with the others and
         none of them ranks. --}}
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">

    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:locale" content="bg_BG">
    <meta property="og:title" content="{{ $title ?? config('app.name') }}">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    @isset($description)
        <meta property="og:description" content="{{ Str::limit(strip_tags($description), 200) }}">
    @endisset

    {{-- summary_large_image only when there is an image to fill it. Declaring
         it without one renders as a broken card rather than a small one. --}}
    @isset($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta name="twitter:card" content="summary">
    @endisset

    {{-- Structured data, passed in already encoded by whoever knows what the
         page is. Kept out of the shared layout on purpose: a Product schema
         guessed from a page that is not a product is a manual penalty. --}}
    @isset($jsonLd)
        <script type="application/ld+json">{!! $jsonLd !!}</script>
    @endisset

    {{-- Two separate reasons to stay out of the index.

         The deployment: a staging or LAN copy must never be indexed, or it
         competes with the real site for the same content the day that launches.

         The page: filters multiply into an unbounded number of URLs showing
         the same listings, and a crawler that spends its budget on
         "?kat=gpu&ot=200&do=400&grad=sofia" is not spending it on the pages
         worth ranking. Those pages still work and still carry a canonical
         pointing at the version that should rank. --}}
    @if (! config('remarket.seo.indexable', false))
        {{-- nofollow too: nothing on a copy of the site should be crawled
             onward from it, including its links back to the real one. --}}
        <meta name="robots" content="noindex, nofollow">
    @elseif ($noindex ?? false)
        {{-- follow, deliberately: the page should not rank, but the listings
             it links to should still be discovered through it. --}}
        <meta name="robots" content="noindex, follow">
    @endif

    {{-- The page background, before any stylesheet has loaded. In dev, Vite
         injects the CSS with JavaScript, so without this the first paint is a
         bare rectangle no matter what class <html> carries. Dark first, to
         match the default. --}}
    <style>html{background:#0a0d0c}html[data-theme="light"]{background:#fbfcfb}</style>

    <script>
        (function () {
            var root = document.documentElement;
            var mq   = window.matchMedia('(prefers-color-scheme: dark)');

            function apply() {
                var mode = root.dataset.theme;
                root.classList.toggle('dark', mode === 'dark' || (mode === 'system' && mq.matches));
            }

            // Only "system" needs resolving here: an explicit choice was already
            // rendered onto <html> above, before this script and before paint.
            if (root.dataset.theme === 'system') {
                apply();
            }

            // Someone flipping their OS theme while the tab is open, or a laptop
            // crossing sunset, should not need a refresh.
            mq.addEventListener('change', apply);

            window.__cycleTheme = function () {
                root.dataset.theme = ({
                    dark:   'light',
                    light:  'system',
                    system: 'dark',
                })[root.dataset.theme] ?? 'light';

                apply();

                // A cookie, not localStorage: the server has to be able to
                // read it. "system" is now stored explicitly rather than being
                // the absence of a cookie, because the absence means dark.
                document.cookie = 'theme=' + root.dataset.theme
                    + '; path=/; max-age=31536000; samesite=lax';
            };
        })();
    </script>

    {{-- Vite::fonts() is NOT emitted by @vite - it is a separate call that
         renders the preload links and inlines the @font-face rules. Without
         it the font files are built and self-hosted and then never referenced
         by anything, which is exactly what was happening. --}}
    {{ Vite::fonts() }}

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex min-h-full flex-col">

<header class="site-header">
    {{-- ONE bar, three jobs, in this order left to right: what the site is,
         what is waiting on you, what you came to do.

         It used to carry eleven items for a signed-in user and thirteen for an
         admin, all as equals, and the labels wrapped mid-phrase. Uppercase
         Cyrillic with letter-spacing is roughly a third wider than the sentence
         case these were written in, so the bar ran out of room long before the
         window did.

         Everything that belongs to ONE PERSON now lives in the account menu.
         That is also what finally makes this work on a phone: the old header
         hid its whole nav below `md`, so a phone got no way to reach Обяви at
         all - the menu carries those entries at small widths instead. --}}
    <div class="mx-auto flex max-w-7xl items-center gap-2 px-4 py-3.5">

        <a href="{{ route('home') }}" wire:navigate class="flex shrink-0 items-center gap-2">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent font-mono text-[11px]
                         font-bold text-[var(--accent-ink)]">RM</span>
            <span class="hidden text-[15px] font-extrabold tracking-tight sm:inline">{{ config('app.name') }}</span>
        </a>

        @auth
            {{-- Every count the bar and the menu need, in one place and one
                 pass. Four separate blocks used to compute these inline, which
                 made it impossible to see that the menu needed a roll-up of
                 the ones it hides. --}}
            @php
                // Everything waiting on THIS user - offers they received as a
                // seller AND counters they were sent as a buyer. Counting only
                // the seller side left a countered buyer with no signal
                // anywhere on the site that it was their move.
                $pendingOffers = \App\Models\Offer::awaitingResponseFrom(auth()->id())->count();

                $openDeals = \App\Models\Deal::where('status', \App\Enums\DealStatus::Open)
                    ->where(fn ($q) => $q->where('buyer_id', auth()->id())
                                         ->orWhere('seller_id', auth()->id()))
                    ->count();

                // One query, not one per thread - this renders on every page.
                $unread = \App\Models\Thread::unreadTotalFor(auth()->id());

                $isAdmin      = auth()->user()->is_admin;
                $queued       = $isAdmin ? \App\Models\ModerationItem::queue()->count() : 0;
                $uncatalogued = $isAdmin ? \App\Models\Listing::awaitingCatalogue()->count() : 0;

                /*
                 * What the closed menu owes the user.
                 *
                 * The sum of the ACCENT badges hidden inside it, and nothing
                 * else: those mean somebody is waiting. The catalogue queue is
                 * work that is available rather than work that is late, which
                 * is why it wears a neutral badge inside and stays out of this
                 * number. A roll-up that counts things nobody is waiting on is
                 * a dot that never goes away, and a dot that never goes away
                 * stops being read.
                 */
                $menuBadge = $openDeals + $queued;
            @endphp
        @endauth

        {{-- Guests have no account menu, so below `lg` the two site links need a
             home of their own or a phone cannot reach the listings at all.

             It sits HERE, immediately after the logo, for two reasons. It is
             where a hamburger belongs and where a thumb goes looking for it —
             and it is on the left, which is the side its panel opens toward.
             Parked next to Вход instead, the trigger ended up mid-bar and the
             panel, anchored to its right edge, hung off the left of the screen. --}}
        @guest
            <div class="relative shrink-0 lg:hidden"
                 x-data="{ open: false }"
                 x-on:keydown.escape.window="open = false">
                <button type="button" x-on:click="open = ! open"
                        x-bind:aria-expanded="open ? 'true' : 'false'"
                        aria-haspopup="true" aria-label="Меню"
                        class="btn-ghost btn-sm h-8 w-8 px-0!">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M4 7h16M4 12h16M4 17h16"/>
                    </svg>
                </button>

                {{-- left-0, not right-0: a panel opens toward the side that has
                     room, and this trigger is against the left edge. The
                     account menu below is the mirror image and keeps right-0
                     because it is the last thing in the bar. --}}
                <div x-show="open" x-cloak x-transition
                     x-on:click.outside="open = false"
                     class="card absolute left-0 z-40 mt-2 w-52 max-w-[calc(100vw-2rem)] p-1.5">
                    <a href="{{ route('browse') }}" wire:navigate class="menu-item">Обяви</a>
                    <a href="{{ route('valuation') }}" wire:navigate class="menu-item">Колко струва?</a>
                </div>
            </div>
        @endguest

        {{-- What the site IS. Two items, and they stay two. --}}
        <nav class="ml-1 hidden shrink-0 items-center gap-1 lg:flex">
            <a href="{{ route('browse') }}" wire:navigate class="btn-ghost btn-sm">Обяви</a>

            {{-- Aimed at the half of the site that is short: people with
                 hardware to sell. The one nav item that speaks to somebody who
                 has not decided to sell yet. --}}
            <a href="{{ route('valuation') }}" wire:navigate class="btn-ghost btn-sm">Колко струва?</a>
        </nav>

        <div class="flex-1"></div>

        @auth
            {{-- The two signals that mean somebody is waiting on YOU, and the
                 only personal items still in the bar. Deals are one rung down:
                 an open deal already sent a notification and has a 72-hour
                 window, so it does not need to be on screen every second - it
                 keeps its badge inside the menu and inside the roll-up. --}}
            <nav class="hidden shrink-0 items-center gap-1 md:flex">
                <a href="{{ route('offers') }}" wire:navigate class="btn-ghost btn-sm">
                    Оферти
                    @if ($pendingOffers)
                        <span class="badge-accent ml-1 font-mono">{{ $pendingOffers }}</span>
                    @endif
                </a>

                <a href="{{ route('messages') }}" wire:navigate class="btn-ghost btn-sm">
                    Съобщения
                    @if ($unread)
                        <span class="badge-accent ml-1 font-mono">{{ $unread }}</span>
                    @endif
                </a>
            </nav>
        @endauth

        {{-- Three states, not two. "Follow the system" has to be reachable
             again after someone has picked a fixed theme, or the only way back
             is clearing site data. The icon shows which state is active - the
             CSS rules live in app.css and key off <html data-theme>. --}}
        <button type="button"
                onclick="window.__cycleTheme()"
                aria-label="Смени темата"
                title="Тема: системна / светла / тъмна"
                class="btn-ghost btn-sm h-8 w-8 shrink-0 px-0!">
            {{-- system: half-filled --}}
            <svg class="theme-icon theme-icon-system h-4 w-4" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.7">
                <circle cx="12" cy="12" r="8"/>
                <path d="M12 4a8 8 0 0 1 0 16z" fill="currentColor" stroke="none"/>
            </svg>
            {{-- light: sun --}}
            <svg class="theme-icon theme-icon-light h-4 w-4" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/>
            </svg>
            {{-- dark: moon --}}
            <svg class="theme-icon theme-icon-dark h-4 w-4" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>
            </svg>
        </button>

        @auth
            {{-- The accent appears on exactly one control per screen, and in
                 the header it is this: the site is short of supply, not demand. --}}
            <a href="{{ route('listing.create') }}" wire:navigate class="btn-primary btn-sm shrink-0">
                Публикувай
            </a>

            {{-- ------------------------------------------- account menu --}}
            <div class="relative shrink-0"
                 x-data="{ open: false }"
                 x-on:keydown.escape.window="open = false">

                <button type="button"
                        x-on:click="open = ! open"
                        x-bind:aria-expanded="open ? 'true' : 'false'"
                        aria-haspopup="true"
                        class="btn-ghost btn-sm">
                    <span class="hidden sm:inline">{{ auth()->user()->username }}</span>

                    {{-- An initial below `sm`, NOT a second hamburger. A guest
                         gets a hamburger on the left of the bar for the site
                         menu; this one is on the right and is an account. Two
                         identical glyphs in two places, meaning two different
                         things, is the part that reads as broken. --}}
                    <span aria-hidden="true"
                          class="grid h-5 w-5 place-items-center rounded-md bg-surface-alt
                                 font-mono text-[11px] font-bold text-ink sm:hidden">
                        {{ mb_strtoupper(mb_substr(auth()->user()->username, 0, 1)) }}
                    </span>
                    <span class="sr-only sm:hidden">Профил и меню</span>

                    @if ($menuBadge)
                        <span class="badge-accent ml-1 font-mono">{{ $menuBadge }}</span>
                    @endif

                    {{-- Below `sm` the trigger is already an icon; a chevron
                         next to a hamburger is two symbols for one idea. --}}
                    <svg class="hidden h-3 w-3 opacity-60 sm:block" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true">
                        <path d="m6 9 6 6 6-6"/>
                    </svg>
                </button>

                {{-- The items below deliberately do NOT use .btn-ghost: inside
                     .site-header that class is uppercased, bolded and tracked
                     out, which is right for a bar and wrong for a list. --}}
                <div x-show="open" x-cloak x-transition
                     x-on:click.outside="open = false"
                     class="card absolute right-0 z-40 mt-2 w-60 max-w-[calc(100vw-2rem)] p-1.5">

                    {{-- Only at the widths where the bar is not already showing
                         these. The divider hides with them, or it is a line
                         under nothing. --}}
                    <a href="{{ route('browse') }}" wire:navigate class="menu-item lg:hidden">Обяви</a>
                    <a href="{{ route('valuation') }}" wire:navigate class="menu-item lg:hidden">Колко струва?</a>
                    <hr class="my-1.5 lg:hidden">

                    <a href="{{ route('offers') }}" wire:navigate class="menu-item md:hidden">
                        <span>Оферти</span>
                        @if ($pendingOffers)
                            <span class="badge-accent font-mono">{{ $pendingOffers }}</span>
                        @endif
                    </a>
                    <a href="{{ route('messages') }}" wire:navigate class="menu-item md:hidden">
                        <span>Съобщения</span>
                        @if ($unread)
                            <span class="badge-accent font-mono">{{ $unread }}</span>
                        @endif
                    </a>
                    <hr class="my-1.5 md:hidden">

                    <a href="{{ route('deals') }}" wire:navigate class="menu-item">
                        <span>Сделки</span>
                        @if ($openDeals)
                            <span class="badge-accent font-mono">{{ $openDeals }}</span>
                        @endif
                    </a>

                    {{-- Discoverability is the whole point: a seller who cannot
                         find their own listings cannot fix a price. --}}
                    <a href="{{ route('listings.mine') }}" wire:navigate class="menu-item">Моите обяви</a>

                    {{-- The buyer's side of "come back later". Everything else
                         here belongs to selling; without these a buyer has no
                         reason to return until they need something again. --}}
                    <a href="{{ route('favorites') }}" wire:navigate class="menu-item">Запазени</a>

                    {{-- Neither of these was reachable from the header at all
                         before - the bar was simultaneously overcrowded and
                         missing two of its own pages. --}}
                    <a href="{{ route('searches') }}" wire:navigate class="menu-item">Запазени търсения</a>

                    <hr class="my-1.5">

                    <a href="{{ route('profile', auth()->user()->username) }}" wire:navigate class="menu-item">
                        Моят профил
                    </a>
                    <a href="{{ route('profile.edit') }}" wire:navigate class="menu-item">Настройки</a>

                    @if ($isAdmin)
                        <hr class="my-1.5">

                        {{-- A queue nobody can see the size of is a queue nobody
                             works. Sellers sit invisible until it is worked. --}}
                        <a href="{{ route('moderation') }}" wire:navigate class="menu-item">
                            <span>Модерация</span>
                            @if ($queued)
                                <span class="badge-accent font-mono">{{ $queued }}</span>
                            @endif
                        </a>

                        {{-- Neutral, not accent: this is work that is available
                             rather than work that is late. Nobody is waiting on
                             it, and it stays out of the roll-up for that reason. --}}
                        <a href="{{ route('catalogue') }}" wire:navigate class="menu-item">
                            <span>Каталог</span>
                            @if ($uncatalogued)
                                <span class="badge-neutral font-mono">{{ $uncatalogued }}</span>
                            @endif
                        </a>
                    @endif

                    <hr class="my-1.5">

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="menu-item w-full">Изход</button>
                    </form>
                </div>
            </div>
        @else
            <a href="{{ route('login') }}" wire:navigate class="btn-ghost btn-sm shrink-0">Вход</a>
            <a href="{{ route('register') }}" wire:navigate class="btn-primary btn-sm shrink-0">Регистрация</a>
        @endauth
    </div>
</header>

<main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
    @if (session('status'))
        <div class="mb-4 rounded-md border border-line bg-good-soft px-4 py-2.5 text-sm text-good">
            {{ session('status') }}
        </div>
    @endif

    {{ $slot }}
</main>

<footer class="mt-12 border-t border-line bg-surface">
    <div class="mx-auto max-w-7xl px-4 py-8 text-sm text-ink-muted">
        <a href="{{ route('home') }}" wire:navigate class="font-medium text-ink hover:text-accent">{{ config('app.name') }}</a>
        <p class="mt-1">Пазар за компютърни компоненти и гейминг техника.</p>
        <p class="mt-3 text-xs text-ink-faint">
            Сделките се уговарят пряко между потребителите. Платформата не обработва плащания
            и не е страна по договора.
        </p>

        {{-- Reachable from every page: the DSA contact points are only
             "published" if someone can actually find them. --}}
        <nav class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs">
            <a href="{{ route('browse') }}" wire:navigate class="text-ink-muted hover:text-ink">Всички обяви</a>
            <a href="{{ route('valuation') }}" wire:navigate class="text-ink-muted hover:text-ink">Колко струва техниката ми</a>
        </nav>

        <nav class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
            <a href="{{ route('legal.terms') }}" wire:navigate class="text-ink-muted hover:text-ink">Общи условия</a>
            <a href="{{ route('legal.privacy') }}" wire:navigate class="text-ink-muted hover:text-ink">Поверителност</a>
            <a href="{{ route('legal.cookies') }}" wire:navigate class="text-ink-muted hover:text-ink">Бисквитки</a>
            <a href="{{ route('legal.notice') }}" wire:navigate class="text-ink-muted hover:text-ink">Сигнали</a>
            <a href="{{ route('legal.contacts') }}" wire:navigate class="text-ink-muted hover:text-ink">Контакти</a>
        </nav>

        <p class="mt-4 text-xs text-ink-faint">
            {{ config('legal.entity.name') }}@if (config('legal.entity.eik')), ЕИК {{ config('legal.entity.eik') }}@endif
        </p>
    </div>
</footer>

@stack('scripts')

@livewireScripts
</body>
</html>
