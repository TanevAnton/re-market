{{-- The phone's navigation: a bar along the bottom edge, where a thumb is.

     The header's menus are right for a desktop and a long reach on a phone -
     the two things a visitor does most (look for something, put something up
     for sale) were both at the top of a tall screen. Five places, one of them
     the action the whole site exists for, raised in the middle.

     Hidden from md up, where the header has room for all of it. The spacer is
     the same height as the bar so the footer is never covered by it.

     NO COUNT BADGES. A dot, not a number, for unread messages: the header's
     badges are counted by tests that scan for them (HeaderTest), and a second
     set of numbers a few centimetres from the first is two sources for one
     fact. The dot says „there is something"; the header's badge and the inbox
     say how much.

     Active page by route name, with the current one in the accent colour and
     marked for screen readers. `$unread` is the header's own figure - it is
     computed once per page in the layout, so this adds no query. --}}

<div class="h-16 md:hidden" aria-hidden="true"></div>

<nav class="fixed inset-x-0 bottom-0 z-40 border-t border-line-strong bg-surface/95 backdrop-blur md:hidden"
     style="padding-bottom: env(safe-area-inset-bottom)"
     aria-label="Основна навигация">
    <ul class="mx-auto grid h-16 max-w-md grid-cols-5 items-center">

        <li>
            <a href="{{ route('home') }}" wire:navigate
               @if (request()->routeIs('home')) aria-current="page" @endif
               class="flex flex-col items-center gap-1 py-1.5 text-[10.5px] font-medium {{ request()->routeIs('home') ? 'text-accent' : 'text-ink-muted' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true" class="h-[22px] w-[22px]">
                    <path d="m3 10.5 9-7 9 7"/>
                    <path d="M5 9.5V20h14V9.5"/>
                    <path d="M10 20v-5h4v5"/>
                </svg>
                Начало
            </a>
        </li>

        <li>
            <a href="{{ route('browse') }}" wire:navigate
               @if (request()->routeIs('browse')) aria-current="page" @endif
               class="flex flex-col items-center gap-1 py-1.5 text-[10.5px] font-medium {{ request()->routeIs('browse') ? 'text-accent' : 'text-ink-muted' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true" class="h-[22px] w-[22px]">
                    <circle cx="11" cy="11" r="6.5"/>
                    <path d="m16 16 4.5 4.5"/>
                </svg>
                Обяви
            </a>
        </li>

        {{-- The action. Raised and filled, because it is the one thing on this
             bar that is not navigation. Guests are sent to make an account,
             as every sell button on the site does. --}}
        <li class="flex justify-center">
            <a href="{{ auth()->check() ? route('listing.create') : route('register') }}" wire:navigate
               class="-mt-5 flex flex-col items-center gap-1 text-[10.5px] font-semibold text-ink">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-accent text-[var(--accent-ink)]
                             shadow-[0_6px_18px_-4px_var(--accent)]">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                         aria-hidden="true" class="h-6 w-6">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                </span>
                Продай
            </a>
        </li>

        @auth
            <li>
                <a href="{{ route('messages') }}" wire:navigate
                   @if (request()->routeIs('messages')) aria-current="page" @endif
                   class="relative flex flex-col items-center gap-1 py-1.5 text-[10.5px] font-medium {{ request()->routeIs('messages') ? 'text-accent' : 'text-ink-muted' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true" class="h-[22px] w-[22px]">
                        <path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12Z"/>
                    </svg>
                    Съобщения
                    @if (($unread ?? 0) > 0)
                        <span class="absolute right-[calc(50%-18px)] top-1.5 h-2 w-2 rounded-full bg-accent ring-2 ring-surface"
                              title="Има непрочетени съобщения"></span>
                    @endif
                </a>
            </li>

            <li>
                <a href="{{ route('profile', auth()->user()->username) }}" wire:navigate
                   @if (request()->routeIs('profile')) aria-current="page" @endif
                   class="flex flex-col items-center gap-1 py-1.5 text-[10.5px] font-medium {{ request()->routeIs('profile') ? 'text-accent' : 'text-ink-muted' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true" class="h-[22px] w-[22px]">
                        <circle cx="12" cy="8.5" r="3.5"/>
                        <path d="M5 20c.8-3.6 3.4-5.5 7-5.5s6.2 1.9 7 5.5"/>
                    </svg>
                    Профил
                </a>
            </li>
        @endauth

        @guest
            <li>
                <a href="{{ route('wanted') }}" wire:navigate
                   @if (request()->routeIs('wanted')) aria-current="page" @endif
                   class="flex flex-col items-center gap-1 py-1.5 text-[10.5px] font-medium {{ request()->routeIs('wanted') ? 'text-accent' : 'text-ink-muted' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true" class="h-[22px] w-[22px]">
                        <path d="M12 21s-7-4.6-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 5.4-7 10-7 10Z"/>
                    </svg>
                    Търсения
                </a>
            </li>

            <li>
                <a href="{{ route('login') }}" wire:navigate
                   @if (request()->routeIs('login')) aria-current="page" @endif
                   class="flex flex-col items-center gap-1 py-1.5 text-[10.5px] font-medium {{ request()->routeIs('login') ? 'text-accent' : 'text-ink-muted' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true" class="h-[22px] w-[22px]">
                        <circle cx="12" cy="8.5" r="3.5"/>
                        <path d="M5 20c.8-3.6 3.4-5.5 7-5.5s6.2 1.9 7 5.5"/>
                    </svg>
                    Вход
                </a>
            </li>
        @endguest
    </ul>
</nav>
