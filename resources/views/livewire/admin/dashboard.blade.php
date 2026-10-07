@php
    // The tallest bar in either series sets both scales, so the two charts can
    // be compared to each other rather than each being silently rescaled to its
    // own maximum — which is how a flat week and a busy one end up looking the
    // same shape.
    $peak = max(1, max(array_merge(
        array_column($series, 'listings'),
        array_column($series, 'users'),
    )));
@endphp

<div class="mx-auto max-w-4xl">

    <h1 class="text-2xl font-bold tracking-tight">Табло</h1>
    <p class="hint mt-1">
        Денят и седмицата се броят по българско време ({{ $zone }}), не по UTC.
    </p>

    @if ($hasDemoData)
        {{-- Said plainly rather than hinted at. An operator who learns to
             mentally subtract an unknown amount of demo data has stopped
             reading the screen. --}}
        <div class="card-pad mt-4 border-l-4 border-warn">
            <p class="text-sm font-semibold">Данните са демо.</p>
            <p class="hint mt-1">
                В базата още има профили от <code class="font-mono">DemoSeeder</code>, затова
                всяко число тук е измислено. Изчисти ги с
                <code class="font-mono">php artisan remarket:demo-clear</code> преди
                <code class="font-mono">SEO_INDEXABLE=true</code>.
            </p>
        </div>
    @endif

    {{-- ---------------------------------------------------------------
         Waiting on a person. First, because it is the only band on this
         screen that is a to-do list rather than a readout.
         --------------------------------------------------------------- --}}

    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-muted">Чака теб</h2>

    @if ($waiting === 0)
        <div class="card-pad mt-3">
            <p class="text-sm">Нищо не чака. Всички опашки са изчистени.</p>
        </div>
    @else
        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($attention as $item)
                @continue ($item['count'] === 0)
                <a href="{{ route($item['route']) }}" wire:navigate
                   class="card-pad flex items-center justify-between gap-3 transition hover:border-accent">
                    <span class="text-sm font-medium">{{ $item['label'] }}</span>
                    <span class="badge-accent font-mono tabular">{{ $item['count'] }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($uncatalogued)
        {{-- Neutral, outside the band above: nobody is waiting on this. --}}
        <a href="{{ route('catalogue') }}" wire:navigate
           class="card-pad mt-3 flex items-center justify-between gap-3 transition hover:border-accent">
            <span class="text-sm font-medium">Каталог — модели за добавяне</span>
            <span class="badge-neutral font-mono tabular">{{ $uncatalogued }}</span>
        </a>
    @endif

    {{-- ---------------------------------------------------------------
         What happened.
         --------------------------------------------------------------- --}}

    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-muted">Движение</h2>

    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">

        <div class="card-pad">
            <p class="hint">Обяви днес</p>
            <p class="mt-1 font-mono tabular text-3xl font-bold">{{ $listingsToday }}</p>
            <p class="hint mt-2">
                {{ $listingsWeek }} за 7 дни · {{ $activeListings }} активни общо
            </p>
        </div>

        <div class="card-pad">
            <p class="hint">Нови профили днес</p>
            <p class="mt-1 font-mono tabular text-3xl font-bold">{{ $usersToday }}</p>
            <p class="hint mt-2">
                {{ $usersWeek }} за 7 дни · {{ $users }} общо
            </p>
        </div>

        <div class="card-pad">
            <p class="hint">Сделки в процес</p>
            <p class="mt-1 font-mono tabular text-3xl font-bold">{{ $openDeals }}</p>
            <p class="hint mt-2">
                {{ $dealsWeek }} започнати · {{ $dealsDoneWeek }} завършени за 7 дни
            </p>
        </div>

    </div>

    {{-- ---------------------------------------------------------------
         Seven days. Two single-series charts rather than one with two
         scales: obyavi and profili are different units, and a shared axis
         would either squash one or lie about the other.
         --------------------------------------------------------------- --}}

    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-muted">Последните 7 дни</h2>

    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">

        @foreach ([
            ['key' => 'listings', 'title' => 'Публикувани обяви'],
            ['key' => 'users',    'title' => 'Нови профили'],
        ] as $chart)
            <div class="card-pad">
                <p class="text-sm font-medium">{{ $chart['title'] }}</p>

                <div class="mt-4 flex h-24 items-end gap-0.5" role="img"
                     aria-label="{{ $chart['title'] }} по дни за последните 7 дни">
                    @foreach ($series as $day)
                        @php($value = $day[$chart['key']])
                        <div class="flex h-full flex-1 flex-col justify-end"
                             title="{{ $day['label'] }} — {{ $value }}">
                            {{-- A zero day keeps a 2px stub so the column is
                                 still there to be counted. An absent bar reads
                                 as missing data rather than as nothing
                                 happening, and those are different facts. --}}
                            <div class="rounded-t bg-accent"
                                 style="height: {{ $value > 0 ? max(6, round($value / $peak * 96)) : 2 }}px"></div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-2 flex gap-0.5">
                    @foreach ($series as $day)
                        <span class="flex-1 text-center text-[10px] text-ink-muted">{{ $day['label'] }}</span>
                    @endforeach
                </div>

                {{-- The same series as text, so it is readable without colour,
                     without hover, and by a screen reader. --}}
                <ul class="sr-only">
                    @foreach ($series as $day)
                        <li>{{ $day['label'] }}: {{ $day[$chart['key']] }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach

    </div>
</div>
