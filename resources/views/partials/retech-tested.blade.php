{{-- „Тествано от RE-Tech".

     $listing  the listing
     $variant  'chip' (a grid card) or 'panel' (the listing page)

     RE-Tech and this site are one company, so the claim never appears without
     saying so. On a card there is no room for the sentence, so the chip carries
     it as its tooltip and the page it leads to prints it in full; the panel
     prints it. A badge that vouched for a listing on the operator's own word
     without saying whose word it was would be the quiet conflict of interest
     this site's buyback offer is careful not to be.

     Draws nothing unless the listing has been marked, so it is safe to include
     unconditionally. --}}
@if ($listing->isRetechTested())
    @if (($variant ?? 'chip') === 'chip')
        <span class="badge-good backdrop-blur" title="{{ \App\Support\RetechOffer::disclosure() }}">
            &#10003; Тествано от {{ \App\Support\RetechOffer::name() }}
        </span>
    @else
        <section class="card-pad mt-6 border-good/30" aria-labelledby="retech-tested-heading">
            <h2 id="retech-tested-heading" class="flex items-center gap-2 text-sm font-semibold text-good">
                <span aria-hidden="true">&#10003;</span>
                Тествано от {{ \App\Support\RetechOffer::name() }}
                <span class="font-mono text-xs font-normal text-ink-faint">
                    {{ $listing->retech_tested_at->format('d.m.Y') }}
                </span>
            </h2>

            @if ($listing->retech_test_note)
                <p class="mt-2 text-sm">{{ $listing->retech_test_note }}</p>
            @endif

            <p class="hint mt-2">
                Означава, че служител на {{ \App\Support\RetechOffer::name() }} е включил и изпробвал
                точно този екземпляр на посочената дата. Не е гаранция и не заменя огледа при получаване.
                {{ \App\Support\RetechOffer::disclosure() }}
            </p>
        </section>
    @endif
@endif
