{{-- No raw PHP in this file, in either form. See the note at the top of
     browse-listings.blade.php for why the two forms together are dangerous. --}}

<div class="mx-auto max-w-5xl">

    <header>
        <p class="font-mono text-[11px] font-semibold uppercase tracking-[0.18em] text-accent">
            Специален раздел
        </p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight">Apple втора употреба</h1>
        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-ink-muted">
            iPhone, iPad и MacBook — с проверими спецификации и с въпросите, които
            решават дали устройството изобщо ще проработи при теб.
        </p>
    </header>

    {{-- ------------------------------------------------------ the three --}}
    <div class="mt-8 grid gap-4 sm:grid-cols-3">
        @foreach ($lines as $line)
            <a href="{{ route('browse', ['kat' => $line['key']]) }}" wire:navigate
               class="card-interactive group flex flex-col p-5">
                {{-- Bigger than the home grid on purpose: three tiles have the
                     room, and phone / tablet / laptop is exactly the distinction
                     a silhouette makes instantly and a word makes slowly. --}}
                <span class="text-ink-faint transition-colors group-hover:text-accent">
                    @include('partials.category-icon', [
                        'category' => $line['key'],
                        'class'    => 'h-8 w-8',
                    ])
                </span>

                <span class="mt-4 text-lg font-bold tracking-tight transition-colors group-hover:text-accent">
                    {{ $line['label'] }}
                </span>

                <span class="mt-1 font-mono text-[11px] tabular text-ink-faint">
                    {{ $line['listings'] }}
                    {{ $line['listings'] === 1 ? 'обява' : 'обяви' }}
                    <span class="mx-1">·</span>
                    {{ $line['models'] }} модела в каталога
                </span>

                <span class="mt-3 text-xs leading-relaxed text-ink-muted">{{ $line['blurb'] }}</span>
            </a>
        @endforeach
    </div>

    {{-- The models with something behind them. Nothing at all rather than a
         list of zeroes: a catalogue page with no listings teaches a visitor the
         site is empty, which is the one lesson hardest to unteach. --}}
    @if ($busiest->isNotEmpty())
        <section class="mt-8">
            <h2 class="label">Най-търсените в момента</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($busiest as $part)
                    <a href="{{ route('part', $part) }}" wire:navigate
                       class="flex items-baseline gap-2 rounded-full border border-line px-3 py-1.5
                              text-sm transition hover:border-accent hover:text-accent">
                        {{ $part->fullName() }}
                        <span class="font-mono text-[11px] tabular text-ink-faint">
                            {{ $part->active_listings_count }}
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ------------------------------------------- why it is not just OLX --}}
    {{-- The argument for buying here rather than on a general classifieds
         board, made once, in the one place where it fits. Inside an individual
         listing there is never room for it. --}}
    <section class="card-pad mt-10">
        <h2 class="text-lg font-bold tracking-tight">Какво питаме продавача — и какво можеш да филтрираш</h2>
        <p class="mt-2 text-sm leading-relaxed text-ink-muted">
            Видеокарта не може да бъде заключена от разстояние. Телефон може, а служебен
            MacBook се записва обратно във фирмената регистрация след всяко изтриване.
            Затова обявите тук носят отговорите, а търсенето ги приема за филтър.
        </p>

        <dl class="mt-5 grid gap-x-10 sm:grid-cols-2">
            <div class="spec-row">
                <dt class="spec-key">iCloud и Find My</dt>
                <dd class="spec-value">Заключено за чужд Apple ID устройство не се отключва от никого.</dd>
            </div>
            <div class="spec-row">
                <dt class="spec-key">Здраве на батерията</dt>
                <dd class="spec-value">Филтрира се като число, не се чете като „държи добре".</dd>
            </div>
            <div class="spec-row">
                <dt class="spec-key">Оригинални части</dt>
                <dd class="spec-value">Неоригинален екран често означава и мъртъв Face ID.</dd>
            </div>
            <div class="spec-row">
                <dt class="spec-key">Цикли на батерията</dt>
                <dd class="spec-value">macOS ги отчита точно — колкото моточасовете на диск.</dd>
            </div>
            <div class="spec-row">
                <dt class="spec-key">Фирмена регистрация (MDM)</dt>
                <dd class="spec-value">Машините от ликвидации се заключват отново сами.</dd>
            </div>
            <div class="spec-row">
                <dt class="spec-key">Заключване за оператор</dt>
                <dd class="spec-value">Внесен телефон може да работи само с една мрежа.</dd>
            </div>
        </dl>

        <p class="hint mt-5">
            Продавач, който е оставил някое от тези полета празно, го вижда като
            „без отговор" в обявата си — а купувачът го вижда като въпрос, който да зададе.
        </p>
    </section>

    {{-- ---------------------------------------------------------- the ask --}}
    <section class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="card-pad">
            <h2 class="label">Продаваш Apple?</h2>
            <p class="hint mt-2">
                Виж на какви цени се предлагат същите конфигурации, преди да решиш
                цената си. Без регистрация.
            </p>
            <a href="{{ route('valuation') }}" wire:navigate class="btn-secondary mt-3 block w-full text-center">
                Колко струва
            </a>
        </div>

        <div class="card-pad">
            <h2 class="label">
                @if ($total > 0)
                    Всички Apple обяви
                @else
                    Още няма обяви тук
                @endif
            </h2>
            <p class="hint mt-2">
                @if ($total > 0)
                    {{ $total }} активни обяви в трите категории.
                @else
                    Каталогът е готов и чака първите обяви. Запази търсене и ще научиш
                    веднага щом се появи твоят модел.
                @endif
            </p>
            <a href="{{ route('browse', ['kat' => 'iphone']) }}" wire:navigate
               class="btn-primary mt-3 block w-full text-center">
                Разгледай
            </a>
        </div>
    </section>
</div>
