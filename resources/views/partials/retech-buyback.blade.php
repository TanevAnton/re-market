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

     THE TWO LOUD SHAPES SAY IT IN TWO WORDS. On the banner and the home page
     row the disclosure is a small tag, „наш магазин", with the full sentence
     behind it as its tooltip. A banner that spends two of its three lines on
     a company name is a banner nobody reads, and „ours" is the whole of what
     a visitor needs from it at a glance. The card and the form sentence have
     the room, and keep the sentence in full.

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
        <div class="col-span-full flex flex-wrap items-center gap-x-3 gap-y-1.5 bg-surface py-3 pr-4 pl-5 text-[13px] text-ink-muted {{ $class }}"
             data-retech-offer="{{ $placement }}">
            <span>Не ти се чака купувач?</span>

            <a href="{{ $url }}" target="_blank" rel="noopener"
               class="font-semibold text-ink transition hover:text-accent">Продай я директно на {{ $shop }}
                <span aria-hidden="true">↗</span><span class="sr-only">(отваря се в нов раздел)</span></a>

            <span title="{{ $disclosure }}"
                  class="cursor-help whitespace-nowrap rounded-full border border-line-strong px-2 py-0.5 font-mono
                         text-[10px] uppercase tracking-[0.12em] text-ink-faint">наш магазин</span>
        </div>

    @else
        {{-- The banner over the graphics cards.

             A headline, one short line under it, one button. It is read mostly
             by people who came to buy, so it has about a second to be
             understood by the few who came to see what theirs is going for -
             and three sentences in grey was not doing that.

             The look is borrowed from the home page band rather than invented:
             the same accent glow and the same faint grid, both mixed from the
             accent so they follow the theme. Both layers sit behind the text
             and fade out before they reach it. --}}
        <div class="relative isolate overflow-hidden rounded-xl border border-accent/30 bg-surface {{ $class }}"
             data-retech-offer="{{ $placement }}"
             style="--glow: color-mix(in oklab, var(--accent) 32%, transparent);
                    --grid: color-mix(in oklab, var(--accent) 10%, transparent);">
            <span class="pointer-events-none absolute inset-0 -z-10"
                  style="background: linear-gradient(90deg, var(--accent-soft), transparent 60%);"></span>
            <span class="pointer-events-none absolute inset-y-0 right-0 -z-10 w-1/2"
                  style="background-image: linear-gradient(var(--grid) 1px, transparent 1px),
                                           linear-gradient(90deg, var(--grid) 1px, transparent 1px);
                         background-size: 26px 26px;
                         -webkit-mask-image: linear-gradient(90deg, transparent, #000);
                                 mask-image: linear-gradient(90deg, transparent, #000);"></span>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3.5 sm:px-5">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg border border-accent/30 bg-canvas/70 text-accent"
                      style="box-shadow: 0 0 24px -6px var(--glow);">
                    @include('partials.category-icon', ['category' => 'gpu', 'class' => 'h-6 w-6'])
                </span>

                <span class="min-w-[190px] flex-1">
                    <span class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                        <span class="text-[15.5px] font-bold leading-tight tracking-tight">Продай видеокартата си директно</span>

                        <span title="{{ $disclosure }}"
                              class="cursor-help whitespace-nowrap rounded-full border border-line-strong px-2 py-0.5 font-mono
                                     text-[10px] uppercase tracking-[0.12em] text-ink-faint">наш магазин</span>
                    </span>

                    {{-- Wide screens only. On a phone it wraps to two lines
                         and doubles the height of a banner that sits above
                         the listings; the headline and the button already say
                         what and where. --}}
                    <span class="mt-1 hidden text-[12.5px] text-ink-muted sm:block">
                        {{ $shop }} я изкупува — без да чакаш купувач.
                    </span>
                </span>

                <a href="{{ $url }}" target="_blank" rel="noopener" class="btn-primary btn-sm shrink-0">
                    Към {{ $shop }}
                    <span aria-hidden="true">↗</span>
                    <span class="sr-only">(отваря се в нов раздел)</span>
                </a>
            </div>
        </div>
    @endif
@endif
