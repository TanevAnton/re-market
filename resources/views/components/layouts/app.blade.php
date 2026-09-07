@php
    /*
     * The theme is decided on the SERVER, from a cookie, so the very first byte
     * of HTML already carries it. It used to live in localStorage, which cannot
     * be read until the page is running - by which point the server has already
     * committed to a guess, and any disagreement shows up as a flip on refresh.
     *
     * Three states. Null means "follow the operating system", which is not the
     * same as light and has to stay tellable apart from it.
     */
    $theme = in_array($cookie = request()->cookie('theme'), ['dark', 'light'], true)
        ? $cookie
        : 'system';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="h-full{{ $theme === 'dark' ? ' dark' : '' }}"
      data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>

    {{-- The page background, before any stylesheet has loaded. In dev, Vite
         injects the CSS with JavaScript, so without this the first paint is a
         white rectangle no matter what class <html> carries. --}}
    <style>html{background:#fff}html.dark{background:#0a0a0c}</style>

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
                    system: 'light',
                    light:  'dark',
                    dark:   'system',
                })[root.dataset.theme] ?? 'light';

                apply();

                // A cookie, not localStorage: the server has to be able to read
                // it. Deleting it is how "follow the system" is expressed.
                document.cookie = root.dataset.theme === 'system'
                    ? 'theme=; path=/; max-age=0; samesite=lax'
                    : 'theme=' + root.dataset.theme + '; path=/; max-age=31536000; samesite=lax';
            };
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex min-h-full flex-col">

<header class="sticky top-0 z-30 border-b border-line bg-canvas/85 backdrop-blur">
    <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3">

        <a href="{{ route('browse') }}" wire:navigate class="flex items-center gap-2">
            <span class="grid h-7 w-7 place-items-center rounded bg-accent font-mono text-[11px]
                         font-bold text-[var(--accent-ink)]">RM</span>
            <span class="hidden font-semibold tracking-tight sm:inline">{{ config('app.name') }}</span>
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
        <p class="font-medium text-ink">{{ config('app.name') }}</p>
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
