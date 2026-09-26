{{-- The serial / IMEI field, shared by the wizard and the edit screen.

     Expects nothing: reads $identifierKind, $identifier and (optionally)
     $identifierMask off the component. Renders nothing at all when there is no
     pepper configured — a field that silently stores an unmatchable hash would
     be worse than no field. --}}
@if (\App\Support\ItemIdentifier::enabled())
    <div class="rounded-lg border border-line bg-surface-alt p-3">
        <p class="text-sm font-medium">Сериен номер / IMEI <span class="text-ink-faint">(по желание)</span></p>

        @if ($identifierMask ?? null)
            <p class="hint mt-1 text-accent">Записан: {{ $identifierMask }}</p>
        @endif

        <div class="mt-2 flex flex-wrap gap-2">
            @foreach (\App\Support\ItemIdentifier::kinds() as $key => $label)
                <button type="button" wire:click="$set('identifierKind', '{{ $key }}')"
                        @class([
                            'rounded-md border px-2.5 py-1 text-xs font-medium transition',
                            'border-accent text-accent' => $identifierKind === $key,
                            'border-line text-ink-muted hover:text-ink' => $identifierKind !== $key,
                        ])>{{ $label }}</button>
            @endforeach
        </div>

        <input type="text" wire:model="identifier" class="mt-2 font-mono"
               placeholder="{{ $identifierKind === 'imei' ? '15 цифри — *#06# на телефона' : 'както е изписан на устройството' }}">

        {{-- Both halves said plainly. A seller handing over a serial deserves
             to know it is not stored in clear, and to know what it buys them. --}}
        <p class="hint mt-2">
            Пази се хеширан — не съхраняваме самия номер и не го показваме на
            никого. Купувач може само да провери номер, който вече има от теб.
        </p>
        <p class="hint">
            Ако някой подаде сигнал, че вещ с този номер му е отнета, обявата
            отива за проверка от човек.
        </p>

        @error('identifier') <p class="error">{{ $message }}</p> @enderror
    </div>
@endif
