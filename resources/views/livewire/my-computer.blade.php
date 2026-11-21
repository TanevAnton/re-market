{{-- „Моят компютър". See App\Support\MyPc for what is checked and why only that. --}}
<div class="mx-auto max-w-2xl">

    <h1 class="text-2xl font-bold tracking-tight">Моят компютър</h1>

    <p class="hint mt-2 max-w-xl">
        Кажи ни какво вече има в компютъра ти и на страницата на всяка видеокарта, процесор,
        платка, памет, захранване, кутия или охладител ще пише дали пасва — или защо не.
        Нищо не е задължително: всяко поле включва по още един ред в проверката.
    </p>

    <form wire:submit="save" class="mt-6 space-y-6">

        @foreach ($groups as $group => $heading)
            <fieldset class="card-pad">
                <legend class="label px-1">{{ $heading }}</legend>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    @foreach ($fields as $key => $field)
                        @continue($field['group'] !== $group)

                        <div>
                            <label class="text-sm font-medium" for="pc-{{ $key }}">{{ $field['label'] }}</label>

                            @if ($field['type'] === 'select')
                                <select id="pc-{{ $key }}" wire:model="pc.{{ $key }}" class="mt-1.5">
                                    <option value="">—</option>
                                    @foreach ($field['options'] as $option)
                                        <option value="{{ $option }}">{{ $option }}</option>
                                    @endforeach
                                </select>
                            @else
                                <div class="relative mt-1.5">
                                    <input id="pc-{{ $key }}" type="number" inputmode="numeric"
                                           min="{{ $field['min'] }}" max="{{ $field['max'] }}"
                                           wire:model="pc.{{ $key }}" class="pr-12">
                                    <span class="pointer-events-none absolute inset-y-0 right-3 grid place-items-center
                                                 font-mono text-xs text-ink-faint">{{ $field['unit'] }}</span>
                                </div>
                            @endif

                            @isset($field['help'])
                                <p class="hint">{{ $field['help'] }}</p>
                            @endisset
                        </div>
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="btn-primary">Запази</button>

            <button type="button" wire:click="clear" class="btn-ghost">Изчисти всичко</button>

            @if ($saved)
                <span class="text-sm text-good" role="status">Запазено.</span>
            @endif
        </div>

        <p class="hint">
            @auth
                Пази се в профила ти, за да е на всяко устройство, и е видимо само за теб.
            @else
                Пази се в тази сесия. С профил остава на всяко устройство.
            @endauth
            Проверката сверява само това, което е записано в каталога и което си ни казал —
            измери кутията и провери конекторите, преди да купиш.
        </p>
    </form>
</div>
