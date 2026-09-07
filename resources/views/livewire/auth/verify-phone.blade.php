<div class="mx-auto max-w-md">
    <h1 class="text-2xl font-semibold tracking-tight">Потвърди телефона си</h1>
    <p class="mt-1 text-sm text-neutral-500">
        Един профил на номер. Така спираме ботовете и купувачите виждат с кого си имат работа.
        Номерът ти не се показва публично.
    </p>

    @if (! $sent)
        <form wire:submit="sendCode" class="mt-6 space-y-4">
            <div>
                <label for="phone" class="block text-sm font-medium">Мобилен номер</label>
                <input id="phone" type="tel" wire:model="phone" placeholder="0888 123 456"
                       autocomplete="tel" autofocus>
                @error('phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-neutral-400">
                    Пробваме Telegram, после Viber, накрая SMS.
                </p>
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white
                           transition hover:bg-neutral-700 disabled:opacity-50"
                    wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sendCode">Изпрати код</span>
                <span wire:loading wire:target="sendCode">Изпращаме…</span>
            </button>
        </form>
    @else
        <div class="mt-6 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">{{ $status }}</div>

        <form wire:submit="confirm" class="mt-4 space-y-4">
            <div>
                <label for="code" class="block text-sm font-medium">Код от 6 цифри</label>
                <input id="code" type="text" inputmode="numeric" maxlength="6" wire:model="code"
                       autocomplete="one-time-code" autofocus
                       class="text-center text-2xl tracking-[0.4em]">
                @error('code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white
                           transition hover:bg-neutral-700 disabled:opacity-50"
                    wire:loading.attr="disabled">
                Потвърди
            </button>

            <button type="button" wire:click="startOver"
                    class="w-full text-sm text-neutral-500 hover:text-neutral-900 hover:underline">
                Друг номер / изпрати пак
            </button>
        </form>
    @endif
</div>
