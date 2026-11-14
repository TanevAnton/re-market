{{-- The offer to sell a graphics card straight to the shop. Expects
     $placement (one of RetechOffer::PLACEMENTS) and $variant; takes an
     optional $class.

     FOUR SHAPES, ONE SET OF WORDS. The offer sits in four places that have
     nothing in common visually - a card on the price check page, a sentence in
     the listing form, a row in the home page strip, a banner over the graphics
     cards - so the shape is chosen by the caller. What is said is not: every
     shape names the shop, makes the same single claim, and ends with the same
     disclosure.

     THE DISCLOSURE IS THE POINT OF HAVING ONE FILE. The shop and this site are
     the same company. A visitor is entitled to know that the „other option"
     they are being shown belongs to whoever is showing it, and the sentence
     saying so is here so that a fifth placement cannot be added without it.

     THE ONE CLAIM is that the shop buys used graphics cards directly. Nothing
     about price, speed or how it works: those are the shop's to state on its
     own page, and a promise made here is one that goes stale without anybody
     on this side noticing.

     Renders nothing at all while the offer has no address - see RetechOffer. --}}
@php
    $offer   = \App\Support\RetechOffer::class;
    $url     = $offer::url($placement);
    $shop    = $offer::name();
    $variant = $variant ?? 'card';
    $class   = $class ?? '';

    $disclosure = $shop.' и '.config('app.name').' са на една и съща фирма — '.config('legal.entity.name').'.';
@endphp

@if ($url)
    @if ($variant === 'card')
        <section class="card-pad {{ $class }}" data-retech-offer="{{ $placement }}">
            <p class="font-mono text-[10.5px] uppercase tracking-[0.18em] text-ink-faint">Услуга на {{ $shop }}</p>

            <h2 class="mt-2 text-lg font-bold tracking-tight">Не ти се чака купувач?</h2>

            <p class="mt-2 text-sm leading-relaxed text-ink-muted">
                {{ $shop }} изкупува видеокарти втора употреба директно.
                Цената и условията са на техния сайт.
            </p>

            <div class="mt-4">
                <a href="{{ $url }}" target="_blank" rel="noopener" class="btn-secondary">
                    Продай на {{ $shop }}
                    <span aria-hidden="true">↗</span>
                    <span class="sr-only">(отваря се в нов раздел)</span>
                </a>
            </div>

            <p class="hint mt-3">{{ $disclosure }}</p>
        </section>

    @elseif ($variant === 'line')
        {{-- Inside a form somebody is already filling in, so a sentence and
             not a box: it must not look like the next field. --}}
        <p class="hint {{ $class }}" data-retech-offer="{{ $placement }}">
            Не ти се чака купувач?
            <a href="{{ $url }}" target="_blank" rel="noopener" class="link">{{ $shop }} изкупува видеокарти директно
                <span aria-hidden="true">↗</span><span class="sr-only">(отваря се в нов раздел)</span></a>.
            {{ $disclosure }}
        </p>

    @elseif ($variant === 'row')
        {{-- A full-width row at the foot of the home page strip. Quieter than
             the two halves above it on purpose: listing here and asking here
             are what the site is for, and this is the alternative to both. --}}
        <div class="col-span-full flex flex-wrap items-baseline gap-x-3 gap-y-1 bg-surface py-3 pr-4 pl-5 text-[12.5px] text-ink-muted {{ $class }}"
             data-retech-offer="{{ $placement }}">
            <span class="font-mono text-[10.5px] uppercase tracking-[0.14em] text-ink-faint">Услуга на {{ $shop }}</span>

            <span>
                Не ти се чака купувач?
                <a href="{{ $url }}" target="_blank" rel="noopener"
                   class="font-medium text-ink transition hover:text-accent">{{ $shop }} изкупува видеокарти директно
                    <span aria-hidden="true">↗</span><span class="sr-only">(отваря се в нов раздел)</span></a>
            </span>

            <span class="text-ink-faint">{{ $disclosure }}</span>
        </div>

    @else
        {{-- The banner over the graphics cards. One line on a wide screen. It
             is read mostly by people who came to buy, so it asks the question
             that sorts out the few who did not. --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-line bg-surface px-4 py-3 text-[13px] {{ $class }}"
             data-retech-offer="{{ $placement }}">
            <span class="font-mono text-[10.5px] uppercase tracking-[0.14em] text-ink-faint">Услуга на {{ $shop }}</span>

            <span class="min-w-[200px] flex-1 text-ink-muted">
                <span class="font-medium text-ink">Продаваш видеокарта?</span>
                {{ $shop }} я изкупува директно.
                <span class="text-ink-faint">{{ $disclosure }}</span>
            </span>

            <a href="{{ $url }}" target="_blank" rel="noopener" class="btn-secondary btn-sm shrink-0">
                Към {{ $shop }}
                <span aria-hidden="true">↗</span>
                <span class="sr-only">(отваря се в нов раздел)</span>
            </a>
        </div>
    @endif
@endif
