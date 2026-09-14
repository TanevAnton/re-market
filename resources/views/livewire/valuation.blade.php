<div class="mx-auto max-w-3xl">

    {{-- The H1 is the query, almost verbatim. Somebody types „колко струва
         видеокартата ми" into Google; a page headed „Оценка на хардуер" is
         answering a different question. --}}
    <header>
        <h1 class="text-3xl font-bold tracking-tight">
            @if ($part)
                Колко струва {{ $part->fullName() }} втора употреба
            @else
                Колко струва техниката ми?
            @endif
        </h1>

        <p class="mt-3 text-sm leading-relaxed text-ink-muted">
            Виж на какви цени се предлагат същите части в България в момента —
            и обявите, от които е сметнато. Без регистрация.
        </p>
    </header>

    {{-- ------------------------------------------------------------ search --}}
    <section class="card-pad mt-6">
        <label for="valuation-q" class="label">Кой модел продаваш?</label>

        <div class="mt-2 flex gap-2">
            <input id="valuation-q" type="search" wire:model.live.debounce.400ms="q"
                   autocomplete="off"
                   placeholder="RTX 4070, 5700X3D, B550 Tomahawk, 990 PRO…">

            @if ($part || $q !== '')
                <button type="button" wire:click="clear" class="btn-secondary shrink-0">
                    Изчисти
                </button>
            @endif
        </div>

        <p class="hint">
            Търси по модел, съкращение или както се пише на кирилица —
            „томахоук" намира същото като „tomahawk".
        </p>

        @if ($results->isNotEmpty())
            <ul class="mt-4 divide-y divide-line border-t border-line">
                @foreach ($results as $option)
                    <li>
                        <button type="button" wire:click="choose('{{ $option->slug }}')"
                                class="flex w-full items-baseline justify-between gap-3 px-2 py-2.5
                                       text-left text-sm transition hover:bg-surface-alt hover:text-accent">
                            <span class="min-w-0 truncate font-medium">{{ $option->fullName() }}</span>
                            <span class="shrink-0 font-mono text-[11px] uppercase tracking-wide text-ink-faint">
                                {{ \App\Support\SpecFilter::categoryLabel($option->category) }}
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif ($q !== '' && mb_strlen(trim($q)) >= 2 && ! $part)
            {{-- An honest miss. The catalogue does not cover every SKU ever
                 made, and pretending otherwise sends somebody away thinking
                 the site is empty rather than that this one model is. --}}
            <div class="mt-4 rounded-lg border border-line bg-surface-alt p-4">
                <p class="text-sm font-medium">Няма такъв модел в каталога.</p>
                <p class="hint">
                    Провери изписването или потърси по-кратко — „4070" вместо
                    „GeForce RTX 4070 Gaming OC". Можеш да публикуваш обява и без
                    модел от каталога.
                </p>
            </div>
        @endif
    </section>

    @if ($part)

        {{-- ------------------------------------------------------ the band --}}
        @if ($guidance)
            <section class="card-pad mt-6">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 class="label">На каква цена да я пуснеш</h2>
                    <span class="font-mono text-[11px] text-ink-faint">
                        обновено {{ $guidance['at']->diffForHumans() }}
                    </span>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div>
                        <p class="font-mono text-xs uppercase tracking-wide text-ink-faint">ще тръгне бързо</p>
                        <p class="mt-1 font-mono text-xl font-semibold tabular">{{ $this->money($guidance['quick']) }}</p>
                    </div>
                    <div>
                        <p class="font-mono text-xs uppercase tracking-wide text-accent">обичайна цена</p>
                        <p class="price mt-1 text-3xl leading-none">{{ $this->money($guidance['typical']) }}</p>
                    </div>
                    <div>
                        <p class="font-mono text-xs uppercase tracking-wide text-ink-faint">ако имаш търпение</p>
                        <p class="mt-1 font-mono text-xl font-semibold tabular">{{ $this->money($guidance['patient']) }}</p>
                    </div>
                </div>

                {{-- Said plainly, because a seller who takes these for sale
                     prices will negotiate down from a number that was already
                     optimistic. And because we do not know the condition of
                     the thing in their hands - only the page can be honest
                     about that, the number cannot. --}}
                <p class="hint mt-4">
                    Сметнато от {{ $live }} активни обяви за този модел.
                    Това са <strong>исканите</strong> цени, не сключените сделки —
                    реалната цена обикновено е малко по-ниска. Състоянието, кутията,
                    гаранцията и градът местят цената в двете посоки.
                </p>
            </section>
        @else
            <section class="card-pad mt-6">
                <h2 class="label">Още няма достатъчно данни</h2>
                <p class="mt-2 text-sm leading-relaxed text-ink-muted">
                    За {{ $part->fullName() }} в момента няма достатъчно активни обяви,
                    за да сметнем честен диапазон. Да измислим число би било по-лошо от
                    това да си мълчим.
                </p>
                <p class="hint">
                    Виж страницата на модела — там се пази историята на цените и се
                    появява диапазон веднага щом има от какво да се сметне.
                </p>
            </section>
        @endif

        {{-- ------------------------------------------------- the evidence --}}
        @if ($asks->isNotEmpty())
            <section class="card-pad mt-6">
                <h2 class="label">Обявите зад числото</h2>
                <p class="hint">Активни в момента, най-новите отгоре.</p>

                <ul class="mt-3 divide-y divide-line border-t border-line">
                    @foreach ($asks as $ask)
                        <li>
                            <a href="{{ route('listing', $ask) }}" wire:navigate
                               class="flex items-baseline justify-between gap-3 px-1 py-2.5
                                      text-sm transition hover:text-accent">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{ $ask->title }}</span>
                                    <span class="text-xs text-ink-faint">
                                        {{ $ask->city?->name() }}
                                        @if ($ask->published_at)
                                            <span class="mx-1">·</span>{{ $ask->published_at->diffForHumans() }}
                                        @endif
                                    </span>
                                </span>
                                <span class="shrink-0 font-mono font-semibold tabular">
                                    {{ $ask->formattedPrice() }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <a href="{{ route('part', $part) }}" wire:navigate class="link mt-3 inline-block text-sm">
                    Всички обяви и спецификации за {{ $part->model }} →
                </a>
            </section>
        @endif

        {{-- --------------------------------------------------- the ask --}}
        {{-- The whole point of the page. Somebody who just looked up what
             their card is worth is three seconds away from listing it, and
             this is the only moment they will ever be this close. --}}
        <section class="card-pad mt-6 border-accent/40">
            <h2 class="text-lg font-bold tracking-tight">Продай я тук</h2>
            <p class="mt-2 text-sm leading-relaxed text-ink-muted">
                Обявата е безплатна и се закача за {{ $part->fullName() }}, така че
                излиза на страницата на модела и във всяко търсене за него.
                Купувачите правят оферти в сайта — без телефон в обявата и без
                „последна цена?" в коментарите.
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                @auth
                    <a href="{{ route('listing.create') }}" wire:navigate class="btn-primary">
                        Публикувай обява
                    </a>
                @endauth

                {{-- @guest rather than @else inside @auth: both compile, but
                     the codebase already uses this pair and a reader should
                     not have to know which Blade conditionals accept @else. --}}
                @guest
                    <a href="{{ route('register') }}" wire:navigate class="btn-primary">
                        Регистрирай се и публикувай
                    </a>
                    <a href="{{ route('login') }}" wire:navigate class="btn-ghost">
                        Вече имам профил
                    </a>
                @endguest
            </div>
        </section>

        {{-- What a buyer will hold the item up against. Shown to the SELLER
             on purpose: these are the questions they are about to be asked,
             and a listing that answers them up front sells faster. --}}
        <div class="mt-6">
            @include('partials.checklist', [
                'items'   => \App\Support\Checklist::forCategory($part->category),
                'heading' => 'Какво ще проверят купувачите',
                'intro'   => 'Отговори на това в обявата и спестяваш половината въпроси.',
            ])
        </div>

    @else

        {{-- ------------------------------------------- nothing chosen yet --}}
        <section class="mt-6">
            <h2 class="label">Или тръгни от категория</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($categories as $category)
                    <a href="{{ route('browse', ['kat' => $category['key']]) }}" wire:navigate
                       class="rounded-full border border-line px-3 py-1.5 text-sm transition
                              hover:border-accent hover:text-accent">
                        {{ $category['label'] }}
                    </a>
                @endforeach
            </div>
        </section>

        <section class="card-pad mt-6">
            <h2 class="label">Как се смята</h2>
            <p class="mt-2 text-sm leading-relaxed text-ink-muted">
                Взимаме всички активни обяви за точния модел и показваме долната
                четвъртина, средата и горната четвъртина. Нищо не е ръчно
                нагласяно и нищо не е прогноза — това са цените, които хората
                искат днес.
            </p>
            <p class="hint">
                Диапазон се показва само когато обявите стигат за него.
                За рядък модел страницата ще каже, че не знае.
            </p>
        </section>

    @endif
</div>
