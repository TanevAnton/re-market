<div class="mx-auto max-w-2xl">

    <h1 class="text-2xl font-bold tracking-tight">Сигнал за отнета вещ</h1>
    <p class="mt-1 text-sm text-ink-muted">
        Ако вещ с определен сериен номер или IMEI ти е отнета, запиши номера при
        нас. Ако се появи в обява тук, обявата отива за проверка от човек.
    </p>

    {{-- Said before the form, not after it. A form that lets somebody believe
         the platform verifies police reports is a form that will be used to
         lie about a competitor. --}}
    <div class="card-pad mt-6">
        <p class="text-sm font-semibold">Какво правим и какво не</p>
        <ul class="mt-2 space-y-2 text-sm text-ink-muted">
            <li>
                <strong class="text-ink">Не проверяваме полицейски регистри.</strong>
                Такъв публичен регистър няма. Записваме твърдението ти и номера
                на сигнала, който си подал — нищо повече.
            </li>
            <li>
                <strong class="text-ink">Не установяваме кражба и не обвиняваме продавач.</strong>
                Обява със съвпадащ номер се сваля временно и я преглежда човек,
                а продавачът получава мотиви и право да възрази.
            </li>
            <li>
                <strong class="text-ink">Номерът на сигнала до полицията е задължителен.</strong>
                Това е единственото, което пази функцията да не се използва
                срещу конкуренти. Неверен сигнал може да ти носи отговорност.
            </li>
        </ul>
    </div>

    @if ($reference)
        <div class="card-pad mt-4 border-good/40">
            <p class="text-sm font-semibold text-good">Сигналът е записан.</p>
            <p class="mt-1 text-sm">Номер: <strong class="font-mono">{{ $reference }}</strong></p>
            <p class="hint mt-2">Пази го — по него можем да намерим сигнала.</p>
        </div>
    @endif

    <form wire:submit="submit" class="card-pad mt-4 space-y-4">
        <div>
            <span class="label">Вид</span>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($kinds as $key => $label)
                    <button type="button" wire:click="$set('kind', '{{ $key }}')"
                            @class([
                                'rounded-md border px-3 py-1.5 text-sm font-medium transition',
                                'border-accent text-accent' => $kind === $key,
                                'border-line text-ink-muted hover:text-ink' => $kind !== $key,
                            ])>{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div>
            <label class="label" for="value">Номер на вещта</label>
            <input id="value" type="text" wire:model="value" class="mt-1 font-mono">
            <p class="hint">Пази се хеширан — не съхраняваме самия номер.</p>
            @error('value') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="policeRef">Номер на сигнала в полицията</label>
            <input id="policeRef" type="text" wire:model="policeRef" class="mt-1 font-mono">
            <p class="hint">Както е на документа, който ти е издаден.</p>
            @error('policeRef') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="detail">Какво е станало</label>
            <textarea id="detail" wire:model="detail" rows="5" class="mt-1"
                      placeholder="Кога и къде, каква е вещта, как е отнета."></textarea>
            @error('detail') <p class="error">{{ $message }}</p> @enderror
        </div>

        @guest
            <div>
                <label class="label" for="email">Имейл за отговор</label>
                <input id="email" type="email" wire:model="email" class="mt-1">
                <p class="hint">Не е нужен профил.</p>
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
        @endguest

        <x-turnstile />
        @error('turnstile') <p class="error">{{ $message }}</p> @enderror

        <div class="border-t border-line pt-4">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Подай сигнал</button>
        </div>
    </form>
</div>
