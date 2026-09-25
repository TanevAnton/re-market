{{-- The three things a seller can buy for one listing.

     Expects: $listing, $boosts (BoostService), $balance (cents).

     THE PRICES ARE NEXT TO WHAT THEY DO, and the refusals are sentences
     rather than greyed-out buttons. A seller who cannot buy a tier has a
     reason for it — not active, already running, free right now, balance
     short — and every one of those reasons is something they can act on, so
     saying it is worth more than hiding the button.

     The ladder is three rungs and it is deliberately not a „good / better /
     best" column of the same thing in three sizes: each tier buys a different
     kind of attention, so a seller can buy one and still want another. See
     App\Enums\BoostTier for why that matters. --}}
@php
    $availability = $boosts->availability($listing);
@endphp

<div class="mt-4 rounded-lg border border-line bg-surface-alt p-3">

    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="text-sm font-semibold">Плати за повече видимост</p>

        <p class="text-xs text-ink-muted">
            Кредит:
            <a href="{{ route('credit') }}" wire:navigate class="link font-mono tabular">
                {{ number_format($balance / 100, 2, ',', ' ') }} €
            </a>
        </p>
    </div>

    <div class="mt-3 space-y-2">
        @foreach (\App\Enums\BoostTier::ladder() as $tier)
            @php
                $refusal = $availability[$tier->value] ?? null;
                $running = \App\Support\Boosted::of($listing, $tier);
                $price   = $tier->priceCents();
                $short   = $refusal === null && $balance < $price;
            @endphp

            <div class="flex flex-wrap items-start justify-between gap-3 rounded-md border border-line
                        bg-surface px-3 py-2.5">
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                        {{ $tier->label() }}

                        {{-- What is already paid for, said before anything is
                             offered again. A seller looking at a running pin
                             needs to see it here rather than work it out from
                             the browse page. --}}
                        @if ($running)
                            <span class="badge-good">
                                активно · още {{ $running->hoursLeft() }} ч.
                            </span>
                        @endif
                    </p>

                    <p class="hint mt-0.5">{{ $tier->blurb() }}</p>

                    @if ($tier->days() > 0)
                        <p class="hint">За {{ $tier->days() }} дни.</p>
                    @endif

                    @if ($refusal)
                        <p class="mt-1 text-xs text-ink-muted">{{ $refusal }}</p>
                    @elseif ($short)
                        {{-- Not „insufficient funds": the seller needs the
                             number and the way out, in one line. --}}
                        <p class="mt-1 text-xs text-ink-muted">
                            Не ти достига кредит —
                            <a href="{{ route('credit') }}" wire:navigate class="link">виж кредита си</a>.
                        </p>
                    @endif
                </div>

                <div class="shrink-0 text-right">
                    <p class="price text-sm leading-none">
                        {{ number_format($price / 100, 2, ',', ' ') }} €
                    </p>

                    <button type="button"
                            wire:click="buyBoost({{ $listing->id }}, '{{ $tier->value }}')"
                            wire:loading.attr="disabled"
                            class="btn-secondary btn-sm mt-2"
                            @disabled($refusal !== null || $short)>
                        Купи
                    </button>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Required, and it says the uncomfortable half out loud.

         The Omnibus Directive is about a consumer knowing that payment moves
         things — but this text is for the SELLER, and the thing a seller most
         needs to know before spending is what their money does NOT buy. A
         listing waiting on moderation is the case that matters: the people
         most willing to pay for instant visibility are the ones with the most
         to gain from a fast scam, so it is said here, at the till. --}}
    <p class="hint mt-3 border-t border-line pt-2">
        Платената видимост не ускорява проверката и не променя реда на
        одобрението. Ако обявата се продаде или бъде свалена преди края на
        периода, неизползваното се връща като кредит.
    </p>

    <button type="button" wire:click="closeBoost" class="btn-ghost btn-sm mt-2">Затвори</button>
</div>
