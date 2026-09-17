{{-- No raw PHP in this file, in either form. See the note at the top of
     browse-listings.blade.php for why the two forms together are dangerous. --}}

<div class="mx-auto max-w-5xl">

    <header>
        <p class="font-mono text-[11px] font-semibold uppercase tracking-[0.18em] text-accent">
            Само тук
        </p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight">
            Сглоби компютър от втора употреба
        </h1>
        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-ink-muted">
            Всяка обява тук знае какъв процесор, какъв сокет и колко вата е —
            затова сайтът може да ти каже кои части си пасват. По-долу са машини,
            сглобени от обяви, които са налични в момента.
        </p>
    </header>

    @if (count($builds) === 0)
        {{-- The empty state is the page. Three greyed-out slots would say „this
             does not work"; this says „this needs listings", which is true and
             is also the thing that fixes it. --}}
        <section class="card-pad mt-8">
            <h2 class="text-lg font-bold tracking-tight">Още няма достатъчно обяви</h2>
            <p class="mt-2 max-w-xl text-sm leading-relaxed text-ink-muted">
                За да сглобим машина, трябват поне видеокарта, процесор, дънна
                платка, памет и захранване — налични едновременно. Щом се появят,
                тази страница ще ги сглоби сама.
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('browse') }}" wire:navigate class="btn-primary">Виж обявите</a>
                <a href="{{ route('valuation') }}" wire:navigate class="btn-ghost">
                    Имам части за продан
                </a>
            </div>
        </section>
    @else
        <div class="mt-8 space-y-6">
            @foreach ($builds as $build)
                <section class="card card-pad">

                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <h2 class="text-lg font-bold tracking-tight">
                            Машина около {{ $build['anchor']->part?->fullName() ?? $build['anchor']->title }}
                        </h2>

                        {{-- The number is the argument, so it gets the accent and
                             the monospace the rest of the site gives to prices. --}}
                        <p class="price text-xl leading-none tabular">
                            {{ number_format($build['total_cents'] / 100, 2, ',', ' ') }} €
                        </p>
                    </div>

                    <p class="hint mt-1">
                        @if ($build['complete'])
                            {{ $build['filled'] }} обяви, всички налични в момента.
                        @else
                            {{ $build['filled'] }} от 8 части са налични. Сумата е за тях.
                        @endif
                    </p>

                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($build['slots'] as $slot)
                            <li class="flex items-center gap-3 py-2.5">
                                <span class="shrink-0 text-ink-faint">
                                    @include('partials.category-icon', [
                                        'category' => $slot['category'],
                                        'class'    => 'h-5 w-5',
                                    ])
                                </span>

                                @if ($slot['listing'])
                                    <a href="{{ route('listing', $slot['listing']) }}" wire:navigate
                                       class="min-w-0 flex-1 truncate text-sm hover:text-accent">
                                        {{ $slot['listing']->part?->fullName() ?? $slot['listing']->title }}
                                    </a>
                                    <span class="shrink-0 font-mono text-sm tabular text-ink-muted">
                                        {{ $slot['listing']->formattedPrice() }}
                                    </span>
                                @elseif ($slot['blocked'])
                                    {{-- Not a shortage. We cannot say what fits
                                         until the part it depends on is chosen —
                                         „подходяща памет" is a claim about a дънна
                                         платка, and there is not one here. --}}
                                    <span class="min-w-0 flex-1 truncate text-sm text-ink-faint">
                                        зависи от липсващата част по-горе
                                    </span>
                                    <span class="badge-neutral shrink-0">чака</span>
                                @else
                                    {{-- An empty slot is not a gap to hide. It is a
                                         correctly filtered search that happens to
                                         have nothing in it yet, which is worth more
                                         to the visitor than a build we faked. --}}
                                    <a href="{{ $slot['url'] }}" wire:navigate
                                       class="min-w-0 flex-1 truncate text-sm text-ink-faint hover:text-accent">
                                        нищо подходящо още — виж какво търсим
                                    </a>
                                    <span class="badge-neutral shrink-0">празно</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
                        <a href="{{ route('browse', ['kat' => 'gpu']) }}" wire:navigate
                           class="btn-secondary btn-sm">Друга видеокарта</a>
                        <a href="{{ route('listing', $build['anchor']) }}" wire:navigate
                           class="btn-ghost btn-sm">Започни от тази карта</a>
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    {{-- ------------------------------------------------- how it works --}}
    <section class="mt-10">
        <h2 class="text-xl font-bold tracking-tight">Как сайтът знае кое пасва</h2>
        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-ink-muted">
            Обявите не са свободен текст. Всяка е закачена за конкретен модел, а
            моделът носи сокета, консумацията, дължината и формата си — така
            правилата по-долу се прилагат автоматично.
        </p>

        <ul class="mt-5 grid gap-3 sm:grid-cols-2">
            @foreach ($rules as $rule)
                <li class="card-pad flex items-start gap-3">
                    <span class="mt-0.5 shrink-0 text-ink-faint">
                        @include('partials.category-icon', [
                            'category' => $rule['from'],
                            'class'    => 'h-5 w-5',
                        ])
                    </span>
                    <span class="text-xs leading-relaxed text-ink-muted">{{ $rule['why'] }}</span>
                </li>
            @endforeach
        </ul>

        <p class="hint mt-4">
            Правилата са предпазни, не съвети за покупка: казват ти какво няма да
            тръгне, а не кое да купиш.
        </p>
    </section>
</div>
