{{-- Connect Telegram for alerts. See the component for the three states.
     The poll runs only while a link is showing: a page that polled forever
     would be a request every three seconds from every open tab. --}}
<div>
    @if ($configured)
        @if ($connected)
            <p class="flex items-start gap-2 text-xs text-ink-muted">
                <span class="mt-px text-accent">✓</span>
                <span>
                    @if ($enabled)
                        Telegram е свързан — известията идват там.
                    @else
                        Telegram е свързан, но известията там са изключени.
                    @endif
                    Настройките са в
                    <a href="{{ route('profile.edit') }}" wire:navigate class="link">профила</a>.
                </span>
            </p>
        @elseif ($url)
            <div @class(['card-pad' => ! $bare, 'rounded-md border border-line p-3' => $bare]) wire:poll.3s>
                <p class="text-sm font-medium">Отвори Telegram и натисни Start</p>
                <a href="{{ $url }}" target="_blank" rel="noopener" class="btn-primary mt-3 block w-full text-center">
                    Отвори Telegram
                </a>
                <p class="hint mt-2">
                    След като натиснеш Start, тук ще се покаже, че е свързан. Връзката важи 15 минути.
                </p>
            </div>
        @else
            <div @class(['card-pad' => ! $bare, 'rounded-md border border-line p-3' => $bare])>
                <p class="text-sm font-medium">Получавай известията в Telegram</p>
                <p class="hint mt-1">
                    Безплатно и веднага — обявите по запазените ти търсения идват в чата ти,
                    без да чакаш имейл. Не е нужен телефонен номер.
                </p>
                <button type="button" wire:click="start" class="btn-secondary mt-3 w-full"
                        wire:loading.attr="disabled" wire:target="start">
                    Свържи Telegram
                </button>
                @if ($error)
                    <p class="error mt-2">{{ $error }}</p>
                @endif
            </div>
        @endif
    @endif
</div>
