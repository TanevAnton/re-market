{{-- „Какво да провериш" — the editorial layer OLX will never have.

     Two tiers on purpose. The urgent handful sits open, next to the advice
     about paying at the courier, because that is the moment it has to be read.
     The rest folds away: a wall of twenty equally-weighted warnings is a wall
     nobody reads, and it would push the price and the seller off the screen.

     Expects: $items (from App\Support\Checklist), optional $heading, optional
     $intro. --}}
@php
    $critical = array_values(array_filter($items, fn ($i) => $i['critical']));
    $rest     = array_values(array_filter($items, fn ($i) => ! $i['critical']));
@endphp

@if ($items)
    <section class="card-pad">
        <h2 class="text-sm font-semibold tracking-tight">
            {{ $heading ?? 'Какво да провериш, преди да платиш' }}
        </h2>

        @if ($intro ?? false)
            <p class="hint">{{ $intro }}</p>
        @endif

        @if ($critical)
            <ul class="mt-3 space-y-2">
                @foreach ($critical as $item)
                    <li class="flex gap-2.5 text-sm leading-relaxed">
                        {{-- A dot rather than an icon set: it reads at any size
                             and needs no second colour to mean "this one". --}}
                        <span aria-hidden="true"
                              @class([
                                  'mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full',
                                  'bg-warn' => $item['gap'],
                                  'bg-accent' => ! $item['gap'],
                              ])></span>
                        <span>
                            {{ $item['text'] }}
                            @if ($item['gap'])
                                {{-- Said out loud, because "the seller did not
                                     answer this" is information the buyer
                                     cannot get any other way. --}}
                                <span class="badge-warn ml-1 align-middle">без отговор</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($rest)
            <details class="group mt-3">
                <summary class="cursor-pointer text-xs text-ink-muted transition hover:text-ink">
                    Още {{ count($rest) }} за проверка
                    <span class="font-mono transition-transform group-open:rotate-180 inline-block">⌄</span>
                </summary>

                <ul class="mt-2 space-y-2">
                    @foreach ($rest as $item)
                        <li class="flex gap-2.5 text-sm leading-relaxed text-ink-muted">
                            <span aria-hidden="true"
                                  class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-line-strong"></span>
                            <span>{{ $item['text'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        <p class="hint mt-3">
            Общи съвети за категорията, не оценка на тази обява.
        </p>
    </section>
@endif
