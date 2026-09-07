<div class="mx-auto max-w-md">
    <h1 class="text-2xl font-semibold tracking-tight">Потвърди телефона си</h1>
    <p class="mt-1 text-sm text-ink-muted">
        Един профил на номер. Така спираме ботовете и купувачите виждат с кого си имат работа.
        Номерът ти не се показва публично.
    </p>

    {{-- The free path, and the better one: no code is sent, so there is nothing
         to intercept or to be talked into reading out to someone. --}}
    @if ($this->telegramAvailable() && ! $sent)
        <div class="card-pad mt-6" @if ($telegramUrl) wire:poll.3s="checkTelegram" @endif>
            @if (! $telegramUrl)
                <p class="text-sm font-medium">С Telegram — веднага и без код</p>
                <p class="mt-1 text-xs leading-relaxed text-ink-muted">
                    Отваряш бота, натискаш „Сподели номера си" и си готов.
                    Telegram вече е потвърдил номера ти.
                </p>
                <button type="button" wire:click="startTelegram" class="btn-primary mt-3 w-full"
                        wire:loading.attr="disabled" wire:target="startTelegram">
                    <span wire:loading.remove wire:target="startTelegram">Потвърди с Telegram</span>
                    <span wire:loading wire:target="startTelegram">Момент…</span>
                </button>
                @error('telegram') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
            @else
                <p class="text-sm font-medium">Отвори Telegram и натисни Start</p>
                <a href="{{ $telegramUrl }}" target="_blank" rel="noopener"
                   class="btn-primary mt-3 block w-full text-center">
                    Отвори бота
                </a>
                <p class="mt-2 break-all text-center font-mono text-[11px] text-ink-faint">
                    {{ $telegramUrl }}
                </p>
                <p class="mt-3 flex items-center justify-center gap-2 text-xs text-ink-muted">
                    <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-accent"></span>
                    Чакаме потвърждение…
                </p>
            @endif
        </div>

        <p class="mt-4 text-center text-xs text-ink-faint">или с код на телефона</p>
    @endif

    @if (! $sent)
        <form wire:submit="sendCode" class="mt-6 space-y-4">
            <div>
                <label for="phone" class="label">Мобилен номер</label>
                <input id="phone" type="tel" wire:model="phone" placeholder="0888 123 456"
                       autocomplete="tel" autofocus>
                @error('phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="hint">Пробваме Telegram, после Viber, накрая SMS.</p>
            </div>

            <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sendCode">Изпрати код</span>
                <span wire:loading wire:target="sendCode">Изпращаме…</span>
            </button>
        </form>
    @else
        <div class="mt-6 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">{{ $status }}</div>

        <form wire:submit="confirm" class="mt-4 space-y-4">
            <div>
                <label for="code" class="label">Код от 6 цифри</label>
                <input id="code" type="text" inputmode="numeric" maxlength="6" wire:model="code"
                       autocomplete="one-time-code" autofocus
                       class="text-center text-2xl tracking-[0.4em]">
                @error('code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
                Потвърди
            </button>

            <button type="button" wire:click="startOver" class="btn-ghost w-full">
                Друг номер / изпрати пак
            </button>
        </form>
    @endif
</div>
