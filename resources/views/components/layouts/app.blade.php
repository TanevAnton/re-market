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

    {{-- A staging or LAN deployment must not be indexed. It would compete with
         the real site for the same content the day that launches. --}}
    @unless (config('remarket.seo.indexable', false))
        <meta name="robots" content="noindex, nofollow">
    @endunless

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
    <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3.5">

        <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent font-mono text-[11px]
                         font-bold text-[var(--accent-ink)]">RM</span>
            <span class="hidden text-[15px] font-extrabold tracking-tight sm:inline">{{ config('app.name') }}</span>
        </a>

        <nav class="ml-2 hidden items-center gap-1 md:flex">
            <a href="{{ route('browse') }}" wire:navigate class="btn-ghost btn-sm">Обяви</a>
            @auth
                {{-- The count is the whole reason a seller comes back to the
                     site. Auto-declined lowballs are excluded, so this number
                     only ever means "someone is waiting on you". --}}
                @php
                    // Everything waiting on THIS user - offers they received as
                    // a seller AND counters they were sent as a buyer. Counting
                    // only the seller side left a countered buyer with no signal
                    // anywhere on the site that it was their move.
                    $pendingOffers = \App\Models\Offer::awaitingResponseFrom(auth()->id())->count();
                @endphp
                <a href="{{ route('offers') }}" wire:navigate class="btn-ghost btn-sm">
                    Оферти
                    @if ($pendingOffers)
                        <span class="badge-accent ml-1 font-mono">{{ $pendingOffers }}</span>
                    @endif
                </a>

                @php
                    $openDeals = \App\Models\Deal::where('status', \App\Enums\DealStatus::Open)
                        ->where(fn ($q) => $q->where('buyer_id', auth()->id())
                                             ->orWhere('seller_id', auth()->id()))
                        ->count();
                @endphp
                <a href="{{ route('deals') }}" wire:navigate class="btn-ghost btn-sm">
                    Сделки
                    @if ($openDeals)
                        <span class="badge-accent ml-1 font-mono">{{ $openDeals }}</span>
                    @endif
                </a>

                @php
                    // One query, not one per thread - this badge renders on
                    // every page in the site.
                    $unread = \App\Models\Thread::unreadTotalFor(auth()->id());
                @endphp
                <a href="{{ route('messages') }}" wire:navigate class="btn-ghost btn-sm">
                    Съобщения
                    @if ($unread)
                        <span class="badge-accent ml-1 font-mono">{{ $unread }}</span>
                    @endif
                </a>

                {{-- Discoverability is the whole point: a seller who cannot
                     find their own listings cannot fix a price. --}}
                <a href="{{ route('listings.mine') }}" wire:navigate class="btn-ghost btn-sm">Моите обяви</a>

                {{-- The buyer's side of "come back later". Everything else in
                     this nav belongs to selling; without this a buyer has no
                     reason to return until they need something again. --}}
                <a href="{{ route('favorites') }}" wire:navigate class="btn-ghost btn-sm">Запазени</a>

                @if (auth()->user()->is_admin)
                    {{-- A queue nobody can see the size of is a queue nobody
                         works. Sellers are sitting invisible until it is. --}}
                    @php $queued = \App\Models\ModerationItem::queue()->count(); @endphp
                    <a href="{{ route('moderation') }}" wire:navigate class="btn-ghost btn-sm">
                        Модерация
                        @if ($queued)
                            <span class="badge-accent ml-1 font-mono">{{ $queued }}</span>
                        @endif
                    </a>
                @endif
            @endauth
        </nav>

        <div class="flex-1"></div>

        {{-- Three states, not two. "Follow the system" has to be reachable
             again after someone has picked a fixed theme, or the only way back
             is clearing site data. The icon shows which state is active - the
             CSS rules live in app.css and key off <html data-theme>. --}}
        <button type="button"
                onclick="window.__cycleTheme()"
                aria-label="Смени темата"
                title="Тема: системна / светла / тъмна"
                class="btn-ghost btn-sm h-8 w-8 px-0!">
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
            <a href="{{ route('listing.create') }}" wire:navigate class="btn-primary btn-sm">
                Публикувай
            </a>

            <div class="flex items-center gap-1">
                <a href="{{ route('profile', auth()->user()->username) }}" wire:navigate
                   class="btn-ghost btn-sm hidden sm:inline-flex">
                    {{ auth()->user()->username }}
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn-ghost btn-sm">Изход</button>
                </form>
            </div>
        @else
            <a href="{{ route('login') }}" wire:navigate class="btn-ghost btn-sm">Вход</a>
            <a href="{{ route('register') }}" wire:navigate class="btn-primary btn-sm">Регистрация</a>
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
