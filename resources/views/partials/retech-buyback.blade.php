{{-- The offer to sell a graphics card straight to the shop. Expects
     $placement (one of RetechOffer::PLACEMENTS) and $variant; takes an
     optional $class.

     THREE SHAPES, ONE SET OF WORDS. The offer sits in four places - a card
     on the price check page, a large banner on the home page, and a slimmer
     banner both over the graphics cards and at the top of the listing form -
     so the shape is chosen by the caller. What is said is not: every shape
     names the shop, makes the same single claim, and says whose shop it is.

     THE DISCLOSURE IS THE POINT OF HAVING ONE FILE. The shop and this site are
     the same company. A visitor is entitled to know that the „other option"
     they are being shown belongs to whoever is showing it, and the sentence
     saying so is here so that a fifth placement cannot be added without it.

     THE ONE CLAIM is that the shop buys used graphics cards directly. Nothing
     about price, speed or how it works: those are the shop's to state on its
     own page, and a promise made here is one that goes stale without anybody
     on this side noticing.

     THE BANNERS SAY IT IN TWO WORDS. On both banners the disclosure is a
     small tag, „наш магазин", with the full sentence behind it as its tooltip.
     A banner that spends two of its three lines on a company name is a banner
     nobody reads, and „ours" is the whole of what a visitor needs from it at
     a glance. The card has the room, and keeps the sentence in full.

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

    @elseif ($variant === 'hero')
        {{-- The home page banner. The larger of the two, because here it is
             not sitting above somebody's search results - it is one of the
             few things on the page that is an offer rather than a list.

             TWO HALVES OF ONE SENTENCE. The question is the reader's own
             thought, in the ordinary colour; the answer is in the accent,
             because the answer is the part that is ours to say. On a phone
             each takes a line of its own, which is where the sentence breaks
             when it is spoken anyway.

             Same glow and grid as the home page band and the banner below it,
             plus the corner ticks the category tiles show on hover - held
             here, since this is the one tile on the page that is always
             pointing at something. --}}
        <div class="relative isolate overflow-hidden rounded-2xl border border-accent/35 bg-surface {{ $class }}"
             data-retech-offer="{{ $placement }}"
             style="--glow: color-mix(in oklab, var(--accent) 38%, transparent);
                    --grid: color-mix(in oklab, var(--accent) 11%, transparent);">
            <span class="pointer-events-none absolute inset-0 -z-10"
                  style="background: linear-gradient(100deg, var(--accent-soft), transparent 64%);"></span>
            <span class="pointer-events-none absolute -top-24 -left-10 -z-10 h-64 w-96 opacity-50"
                  style="background: radial-gradient(closest-side, var(--glow), transparent);"></span>
            <span class="pointer-events-none absolute inset-y-0 right-0 -z-10 w-3/5"
                  style="background-image: linear-gradient(var(--grid) 1px, transparent 1px),
                                           linear-gradient(90deg, var(--grid) 1px, transparent 1px);
                         background-size: 30px 30px;
                         -webkit-mask-image: linear-gradient(90deg, transparent, #000);
                                 mask-image: linear-gradient(90deg, transparent, #000);"></span>

            <span class="pointer-events-none absolute left-[9px] top-[9px] h-3 w-3 border-l-2 border-t-2 border-accent"></span>
            <span class="pointer-events-none absolute right-[9px] bottom-[9px] h-3 w-3 border-r-2 border-b-2 border-accent"></span>

            <div class="flex flex-wrap items-center gap-x-6 gap-y-5 px-5 py-6 sm:px-8 sm:py-7">
                <span class="grid h-14 w-14 shrink-0 place-items-center rounded-xl border border-accent/35 bg-canvas/70 text-accent sm:h-16 sm:w-16"
                      style="box-shadow: 0 0 34px -6px var(--glow);">
                    @include('partials.category-icon', ['category' => 'gpu', 'class' => 'h-8 w-8 sm:h-9 sm:w-9'])
                </span>

                <div class="min-w-[220px] flex-1">
                    <p class="text-[21px] font-extrabold leading-[1.2] tracking-tight sm:text-[25px]">
                        <span class="block lg:inline">Не ти се чака купувач?</span>
                        <span class="block text-accent lg:inline">Продай на {{ $shop }}</span>
                    </p>

                    <span title="{{ $disclosure }}"
                          class="mt-2.5 inline-block cursor-help whitespace-nowrap rounded-full border border-line-strong px-2 py-0.5
                                 font-mono text-[10px] uppercase tracking-[0.12em] text-ink-faint">наш магазин</span>
                </div>

                <a href="{{ $url }}" target="_blank" rel="noopener"
                   class="btn-primary shrink-0 px-6 py-3 text-[15px]" style="box-shadow: 0 0 30px -8px var(--glow);">
                    Към {{ $shop }}
                    <span aria-hidden="true">↗</span>
                    <span class="sr-only">(отваря се в нов раздел)</span>
                </a>
            </div>
        </div>

    @else
        {{-- The slim banner: over the graphics cards, and at the top of the
             listing form.

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
