{{-- The landing page for a verification link opened somewhere other than the
     browser that registered — which, now that the link works from anywhere, is
     most of them: the phone the mail app opens on, a work laptop, a browser
     that was closed in between.

     It deliberately does NOT sign anybody in. See VerifyEmail for why. So the
     one job here is to say plainly that the thing worked, and to point at the
     two places the person might want to go next: back to the tab they came
     from, which still has their session, or in from here. --}}

@php
    $copy = match ($state) {
        'done' => [
            'tone'  => 'good',
            'title' => 'Имейлът е потвърден',
            'body'  => 'Адресът е потвърден и профилът ти е отключен. Ако си се
                        регистрирал на друго устройство, просто се върни в онзи
                        прозорец — вече всичко работи.',
        ],
        'already' => [
            'tone'  => 'good',
            'title' => 'Този имейл вече е потвърден',
            'body'  => 'Няма какво повече да правиш тук. Може да влезеш в профила
                        си от бутона отдолу.',
        ],
        'expired' => [
            'tone'  => 'warn',
            'title' => 'Линкът е изтекъл',
            'body'  => 'Линковете за потвърждение важат ограничено време, за да не
                        могат да се използват по-късно от някой друг. Влез в профила
                        си и ще ти изпратим нов — отнема секунди.',
        ],
    };
@endphp

<x-layouts.app :title="$copy['title']">
    <div class="mx-auto max-w-md py-10 text-center sm:py-16">

        <span @class([
            'mx-auto grid h-16 w-16 place-items-center rounded-2xl',
            'bg-good-soft text-good' => $copy['tone'] === 'good',
            'bg-warn-soft text-warn' => $copy['tone'] === 'warn',
        ])>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="h-8 w-8">
                @if ($copy['tone'] === 'good')
                    <path d="M4 6.5h16v11H4z"/>
                    <path d="m4.5 7 7.5 5.5L19.5 7"/>
                    <circle cx="18.5" cy="17.5" r="4.5" fill="var(--good-soft)"/>
                    <path d="m16.6 17.6 1.5 1.5 2.8-2.8"/>
                @else
                    <circle cx="12" cy="12" r="9"/>
                    <path d="M12 7.5v5"/>
                    <circle cx="12" cy="16.2" r=".6" fill="currentColor"/>
                @endif
            </svg>
        </span>

        <h1 class="mt-6 text-2xl font-bold tracking-tight">{{ $copy['title'] }}</h1>

        <p class="mx-auto mt-3 max-w-[44ch] text-[15px] leading-relaxed text-ink-muted">
            {{ $copy['body'] }}
        </p>

        <div class="mt-8 flex flex-col items-center gap-3">
            @auth
                {{-- Signed in as somebody else on this device. Verifying an
                     address is no reason to touch that session, so this is a
                     link out, not a switch. --}}
                <a href="{{ route('browse') }}" wire:navigate class="btn-primary px-6 py-3">
                    Към обявите
                </a>
            @else
                <a href="{{ route('login') }}" wire:navigate class="btn-primary px-6 py-3">
                    Влез в профила си
                </a>
                <a href="{{ route('browse') }}" wire:navigate class="link text-sm">
                    или разгледай обявите без профил →
                </a>
            @endauth
        </div>
    </div>
</x-layouts.app>
