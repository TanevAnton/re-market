<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0a0c" media="(prefers-color-scheme: dark)">
    <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>

    {{-- Runs before paint. Without it the page flashes light before the class
         lands, which looks broken every single load in dark mode. --}}
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('theme');
                var dark = saved ? saved === 'dark'
                    : window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) { /* private mode - fall through to light */ }
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
            @endauth
        </nav>

        <div class="flex-1"></div>

        <button type="button"
                onclick="window.__toggleTheme()"
                aria-label="Смени темата"
                class="btn-ghost btn-sm h-8 w-8 px-0!">
            <svg class="h-4 w-4 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.7" stroke-linecap="round">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/>
            </svg>
            <svg class="hidden h-4 w-4 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
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
    </div>
</footer>

<script>
    window.__toggleTheme = function () {
        var dark = document.documentElement.classList.toggle('dark');
        try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch (e) {}
    };
</script>

@livewireScripts
</body>
</html>
