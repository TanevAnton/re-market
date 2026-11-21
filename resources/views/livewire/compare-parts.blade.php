{{-- Compare models. See the component for the rules; this is only the table.

     Horizontal scroll rather than stacking: a comparison read column by column
     is the whole point, and stacked cards put the thing being compared on two
     different screens. The label column sticks, so the row is still readable
     when the numbers have scrolled. --}}
<div class="mx-auto max-w-5xl">

    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight">Сравни модели</h1>
        @if ($parts->count() >= 2)
            <label class="flex cursor-pointer items-center gap-2 text-sm text-ink-muted">
                <input type="checkbox" wire:model.live="onlyDifferences" class="rounded">
                Само разликите
            </label>
        @endif
    </div>

    <p class="hint mt-2 max-w-2xl">
        Спецификации, пазарна цена втора употреба и какво се продава в момента — за два или три модела
        от една категория. Адресът на страницата е самото сравнение: изпрати го на когото искаш.
    </p>

    {{-- ----------------------------------------------------------- picker --}}
    @if ($parts->count() < \App\Livewire\CompareParts::MAX)
        <div class="relative mt-5 max-w-md">
            <label class="label" for="compare-q">
                {{ $parts->isEmpty() ? 'Първи модел' : 'Добави модел' }}
            </label>
            <input id="compare-q" type="search" wire:model.live.debounce.250ms="q" autocomplete="off"
                   placeholder="напр. RTX 4070 или Ryzen 5 5600" class="mt-1 w-full">

            @if ($suggestions->isNotEmpty())
                <ul class="card absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto p-1 shadow-lg">
                    @foreach ($suggestions as $s)
                        <li wire:key="sug-{{ $s->id }}">
                            <button type="button" wire:click="add('{{ $s->slug }}')"
                                    class="block w-full rounded-md px-3 py-2 text-left text-sm transition hover:bg-surface-alt hover:text-accent">
                                {{ $s->fullName() }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif (mb_strlen(trim($q)) >= 2)
                <p class="hint mt-1">Няма такъв модел{{ $parts->isNotEmpty() ? ' в тази категория' : '' }}.</p>
            @endif
        </div>
    @endif

    {{-- ------------------------------------------------------------ table --}}
    @if ($parts->isEmpty())
        <div class="card-pad mt-6 text-center">
            <p class="text-sm font-medium">Избери модел, за да започнеш.</p>
            <p class="hint mx-auto mt-2 max-w-md">
                Търсенето намира модели от каталога. След първия, предлагаме само такива от същата категория.
            </p>
        </div>
    @else
        <div class="card mt-6 overflow-x-auto">
            <table class="w-full min-w-[34rem] border-collapse text-sm">
                <thead>
                    <tr class="border-b border-line-strong align-top">
                        <th class="sticky left-0 z-10 w-36 bg-surface p-3"></th>
                        @foreach ($parts as $part)
                            <th class="p-3 text-left font-normal" wire:key="head-{{ $part->id }}">
                                <div class="flex items-start justify-between gap-2">
                                    <a href="{{ route('part', $part) }}" wire:navigate
                                       class="text-base font-semibold leading-snug hover:text-accent">
                                        {{ $part->fullName() }}
                                    </a>
                                    <button type="button" wire:click="remove('{{ $part->slug }}')"
                                            class="shrink-0 rounded px-1.5 text-ink-faint transition hover:text-bad"
                                            title="Махни от сравнението" aria-label="Махни {{ $part->fullName() }}">
                                        ✕
                                    </button>
                                </div>

                                <p class="mt-2 font-mono text-sm tabular">
                                    @if ($market[$part->id]['median'])
                                        <span class="font-semibold text-accent">
                                            ≈ {{ number_format($market[$part->id]['median'] / 100, 0, ',', ' ') }} €
                                        </span>
                                        <span class="text-[11px] text-ink-faint">на пазара</span>
                                    @else
                                        <span class="text-[11px] text-ink-faint">няма достатъчно данни за цена</span>
                                    @endif
                                </p>

                                <a href="{{ route('part', $part) }}" wire:navigate
                                   class="link mt-1 inline-block text-xs">
                                    @if ($market[$part->id]['live'] > 0)
                                        {{ $market[$part->id]['live'] }}
                                        {{ $market[$part->id]['live'] === 1 ? 'обява' : 'обяви' }} в момента →
                                    @else
                                        Няма обяви в момента →
                                    @endif
                                </a>
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @forelse ($rows as $row)
                        @continue($onlyDifferences && ! $row['differs'])

                        <tr class="border-b border-line last:border-0">
                            <th scope="row"
                                class="sticky left-0 z-10 bg-surface p-3 text-left text-xs font-medium text-ink-muted">
                                {{ $row['label'] }}
                            </th>
                            @foreach ($row['cells'] as $cell)
                                <td @class([
                                    'p-3 font-mono tabular',
                                    'font-semibold text-accent' => $cell['best'],
                                    'text-ink-faint'            => $cell['text'] === '—',
                                ])>
                                    {{ $cell['text'] }}
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $parts->count() + 1 }}" class="p-6 text-center text-sm text-ink-muted">
                                За този модел още няма въведени спецификации.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($parts->count() === 1)
            <p class="hint mt-3">Добави още един модел, за да видиш разликите.</p>
        @elseif ($rows !== [])
            <p class="hint mt-3">
                С цвят е по-добрата стойност там, където „по-добро" не е въпрос на вкус — например повече памет
                или повече ядра. Дължина и консумация не се оцветяват: по-късата видеокарта пасва в повече
                кутии, но обикновено се охлажда по-зле.
            </p>
        @endif
    @endif
</div>
