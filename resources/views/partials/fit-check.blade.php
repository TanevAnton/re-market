{{-- „Пасва ли на твоя компютър?" - the panel on a model or a listing.

     $part   the catalogue model (null for a free-text listing: draws nothing)
     $class  extra classes on the card

     Draws nothing for a category with nothing to check. Mismatches are shown on
     purpose, unlike the one-sided badges elsewhere: the whole value of this
     panel is the line that says „не влиза". See App\Support\MyPc. --}}
@php
    $fit = \App\Support\MyPc::check($part ?? null);
@endphp

@if ($fit['applies'])
    <section class="card-pad {{ $class ?? '' }}" aria-labelledby="fit-heading">
        <h2 id="fit-heading" class="label">Пасва ли на твоя компютър?</h2>

        @if ($fit['rows'])
            <ul class="mt-3 space-y-2">
                @foreach ($fit['rows'] as $row)
                    <li class="flex items-start gap-2 text-sm">
                        @if ($row['status'] === \App\Support\MyPc::OK)
                            <span class="mt-0.5 shrink-0 font-bold text-good" aria-label="Пасва">&#10003;</span>
                        @else
                            <span class="mt-0.5 shrink-0 font-bold text-bad" aria-label="Не пасва">&#10005;</span>
                        @endif
                        <span>{{ $row['text'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($fit['missing'])
            <p class="hint mt-3">
                За да сверим и останалото, добави: {{ implode(', ', array_map('mb_strtolower', $fit['missing'])) }}.
            </p>
        @elseif (! $fit['rows'])
            <p class="hint mt-3">Този модел няма достатъчно данни в каталога за проверка.</p>
        @endif

        <a href="{{ route('my-pc') }}" wire:navigate class="link mt-3 inline-block text-sm">
            {{ $fit['has_profile'] ? 'Промени компютъра' : 'Кажи ни какво имаш' }} →
        </a>

        @if ($fit['rows'])
            <p class="hint mt-2">
                Сверено по каталога и по това, което си записал. Измери кутията и провери конекторите преди да купиш.
            </p>
        @endif
    </section>
@endif
