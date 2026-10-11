{{-- Where the two change-of-address links land. Both are opened from a
     mailbox, so very often on a device with no session at all — see
     ChangeEmail for why neither of them signs anybody in. --}}

@php
    $copy = match ($state) {
        'confirmed' => [
            'tone'  => 'good',
            'title' => 'Новият имейл е потвърден',
            'body'  => 'Профилът ти вече е вързан за този адрес. Влизаш с него от
                        тук нататък — старият вече не работи.',
        ],
        'cancelled' => [
            'tone'  => 'good',
            'title' => 'Смяната е спряна',
            'body'  => 'Имейлът на профила остава непроменен. Ако не си поръчвал
                        тази смяна, някой знае паролата ти — смени я сега.',
        ],
        'expired' => [
            'tone'  => 'warn',
            'title' => 'Линкът е изтекъл',
            'body'  => 'Линковете за смяна на имейл важат ограничено време.
                        Влез в профила си и поискай нов.',
        ],
        default => [
            'tone'  => 'warn',
            'title' => 'Не успяхме да изпълним заявката',
            'body'  => $reason ?? 'Линкът вече не важи.',
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
                <path d="M4 6.5h16v11H4z"/>
                <path d="m4.5 7 7.5 5.5L19.5 7"/>
                @if ($copy['tone'] === 'good')
                    <circle cx="18.5" cy="17.5" r="4.5" fill="var(--good-soft)"/>
                    <path d="m16.6 17.6 1.5 1.5 2.8-2.8"/>
                @else
                    <circle cx="18.5" cy="17.5" r="4.5" fill="var(--warn-soft)"/>
                    <path d="M18.5 15.3v2.4"/>
                    <circle cx="18.5" cy="19.6" r=".55" fill="currentColor"/>
                @endif
            </svg>
        </span>

        <h1 class="mt-6 text-2xl font-bold tracking-tight">{{ $copy['title'] }}</h1>

        <p class="mx-auto mt-3 max-w-[46ch] text-[15px] leading-relaxed text-ink-muted">
            {{ $copy['body'] }}
        </p>

        <div class="mt-8 flex flex-col items-center gap-3">
            @auth
                <a href="{{ route('profile.edit') }}" wire:navigate class="btn-primary px-6 py-3">
                    Към настройките
                </a>
            @else
                <a href="{{ route('login') }}" wire:navigate class="btn-primary px-6 py-3">
                    Влез в профила си
                </a>
            @endauth

            {{-- „Change your password" is the actual instruction on both the
                 cancelled and the failed page, so it gets to be a link rather
                 than advice buried in a paragraph. --}}
            @if (in_array($state, ['cancelled', 'failed'], true))
                <a href="{{ route('password.request') }}" wire:navigate class="link text-sm">
                    Смени паролата си →
                </a>
            @endif
        </div>
    </div>
</x-layouts.app>
