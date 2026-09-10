<div class="mx-auto max-w-6xl">

    <nav class="text-sm text-ink-muted">
        <a href="{{ route('home') }}" wire:navigate class="hover:text-accent">Начало</a>
        <span class="mx-1.5 text-ink-faint">/</span>
        <a href="{{ route('browse', ['kat' => $part->category]) }}" wire:navigate class="hover:text-accent">
            {{ \App\Support\SpecFilter::categoryLabel($part->category) }}
        </a>
        <span class="mx-1.5 text-ink-faint">/</span>
        <span class="text-ink">{{ $part->fullName() }}</span>
    </nav>

    {{-- The H1 is the model name and nothing else. It is the phrase people
         type, and dressing it up with marketing words moves the page away from
         the query it exists to answer. --}}
    <header class="mt-4">
        <h1 class="text-3xl font-bold tracking-tight">{{ $part->fullName() }}</h1>

        <p class="mt-2 text-sm text-ink-muted">
            {{ $part->manufacturer }}
            @if ($part->launch_year)
                <span class="mx-1 text-ink-faint">·</span> представен {{ $part->launch_year }}
            @endif
            @if ($part->msrp_cents)
                <span class="mx-1 text-ink-faint">·</span>
                стартова цена {{ number_format($part->msrp_cents / 100, 0, ',', ' ') }} €
            @endif
        </p>
    </header>

    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">

        {{-- ------------------------------------------------------- main --}}
        <div>
            {{-- The price band is the reason this page exists. It goes first,
                 above the listings, because it is the thing a listing cannot
                 tell you: whether the price you are looking at is normal. --}}
            @if ($band)
                <section class="card-pad">
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <h2 class="label">Пазарна цена втора употреба</h2>
                        <span class="font-mono text-[11px] text-ink-faint">
                            обновено {{ $band['at']->diffForHumans() }}
                        </span>
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-4">
                        <div>
                            <p class="font-mono text-xs uppercase tracking-wide text-ink-faint">ниска</p>
                            <p class="mt-1 font-mono text-xl font-semibold tabular">
                                {{ number_format($band['p25'] / 100, 0, ',', ' ') }} €
                            </p>
                        </div>
                        <div>
                            <p class="font-mono text-xs uppercase tracking-wide text-accent">средна</p>
                            <p class="price mt-1 text-3xl leading-none">
                                {{ number_format($band['median'] / 100, 0, ',', ' ') }} €
                            </p>
                        </div>
                        <div>
                            <p class="font-mono text-xs uppercase tracking-wide text-ink-faint">висока</p>
                            <p class="mt-1 font-mono text-xl font-semibold tabular">
                                {{ number_format($band['p75'] / 100, 0, ',', ' ') }} €
                            </p>
                        </div>
                    </div>

                    {{-- Said plainly rather than buried. These are asking
                         prices, not sale prices, and a reader who assumes
                         otherwise will negotiate from the wrong number. --}}
                    <p class="hint mt-4">
                        Изчислено от {{ $liveCount }} активни обяви в момента.
                        Това са <strong>исканите</strong> цени, а не цените на сключените сделки —
                        реалната цена обикновено е малко по-ниска.
                    </p>
                </section>
            @endif

            {{-- Listings ------------------------------------------------- --}}
            <section class="{{ $band ? 'mt-6' : '' }}">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 class="text-xl font-bold tracking-tight">
                        Обяви за {{ $part->model }}
                        <span class="ml-1 font-mono text-sm font-normal text-ink-faint">
                            {{ $listings->count() }}
                        </span>
                    </h2>

                    @if ($listings->isNotEmpty())
                        <a href="{{ route('browse', ['q' => $part->model, 'kat' => $part->category]) }}"
                           wire:navigate class="link text-sm">Търси с филтри →</a>
                    @endif
                </div>

                @if ($listings->isNotEmpty())
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($listings as $listing)
                            @include('partials.listing-card', ['listing' => $listing])
                        @endforeach
                    </div>
                @else
                    {{-- The empty state is the page's most important moment.
                         A visitor who arrived from Google and finds nothing
                         will leave and not return unless we give them a reason
                         to - and "tell me when one appears" is worth more to
                         this marketplace than the sale we could not make. --}}
                    <div class="card-pad mt-4">
                        <p class="text-center text-sm font-medium">
                            В момента няма активни обяви за този модел.
                        </p>
                        <p class="hint mx-auto mt-2 max-w-md text-center">
                            Пазарът за втора употреба се движи бързо — обяви за популярни модели
                            се появяват по няколко пъти седмично.
                        </p>

                        {{-- The alert lives HERE when the page is empty and in
                             the sidebar otherwise: one instance either way, and
                             it sits where the visitor is actually looking. --}}
                        <div class="mx-auto mt-4 max-w-sm">
                            @livewire('saved-searches.part-alert', ['part' => $part], key('alert-'.$part->id))
                        </div>
                    </div>
                @endif
            </section>

            {{-- Specs ---------------------------------------------------- --}}
            @if ($rows)
                <section class="card-pad mt-6">
                    <h2 class="label">Спецификации</h2>
                    <dl class="mt-3 grid gap-x-10 sm:grid-cols-2">
                        @foreach ($rows as $row)
                            <div class="spec-row">
                                <dt class="spec-key">{{ $row['label'] }}</dt>
                                <dd class="spec-value">
                                    {{ $row['value'] }}{{ $row['unit'] ? ' '.$row['unit'] : '' }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif
        </div>

        {{-- ---------------------------------------------------- sidebar --}}
        <aside class="space-y-4 lg:sticky lg:top-20 lg:self-start">

            @if ($listings->isNotEmpty())
                @livewire('saved-searches.part-alert', ['part' => $part], key('alert-'.$part->id))
            @endif

            <div class="card-pad">
                <h2 class="label">Продаваш такъв?</h2>
                <p class="hint mt-2">
                    Обявата се публикува срещу този модел, така че се появява на тази
                    страница и във всяко търсене за него.
                </p>
                <a href="{{ route('listing.create') }}" wire:navigate
                   class="btn-secondary mt-3 block w-full text-center">
                    Публикувай обява
                </a>
            </div>

            @if ($related->isNotEmpty())
                <div class="card-pad">
                    <h2 class="label">Подобни модели</h2>
                    <ul class="mt-3 space-y-1">
                        @foreach ($related as $other)
                            <li>
                                <a href="{{ route('part', $other) }}" wire:navigate
                                   class="flex items-baseline justify-between gap-2 rounded-md px-2 py-1.5
                                          text-sm transition hover:bg-surface-alt hover:text-accent">
                                    <span class="min-w-0 truncate">{{ $other->fullName() }}</span>
                                    <span class="shrink-0 font-mono text-[11px] tabular text-ink-faint">
                                        {{ $other->live_count }}
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </aside>
    </div>
</div>
