{{-- „Why is my listing not selling?" — the seller's own view of one listing.

     Expects: $listing, $insight (the array from ListingInsight::for()).

     THE FUNNEL READS LEFT TO RIGHT because that is the order it happens in:
     seen → saved → offered. A seller who can see where the row goes flat knows
     which of three completely different problems they have, which is the whole
     point of showing it as a row rather than three unrelated figures. --}}
<div class="mt-4 rounded-lg border border-line bg-surface-alt p-3">

    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="text-sm font-semibold">Как се движи обявата</p>
        <p class="hint">{{ $insight['days'] }} дни в сайта</p>
    </div>

    <div class="mt-3 grid grid-cols-3 gap-2 text-center">
        <div class="rounded-md border border-line bg-surface px-2 py-2.5">
            <p class="price text-lg leading-none">{{ $insight['views'] }}</p>
            <p class="hint mt-1">преглеждания</p>
            @if ($insight['median_views'] !== null)
                <p class="hint">подобни: {{ $insight['median_views'] }}</p>
            @endif
        </div>

        <div class="rounded-md border border-line bg-surface px-2 py-2.5">
            <p class="price text-lg leading-none">{{ $insight['saves'] }}</p>
            <p class="hint mt-1">запазвания</p>
        </div>

        <div class="rounded-md border border-line bg-surface px-2 py-2.5">
            <p class="price text-lg leading-none">{{ $insight['offers'] }}</p>
            <p class="hint mt-1">оферти</p>
            @if ($insight['median_offers'] !== null)
                <p class="hint">подобни: {{ $insight['median_offers'] }}</p>
            @endif
        </div>
    </div>

    {{-- The comparison is absent rather than softened when there is no sample.
         „средно за този модел" over two listings is a rumour, and a number that
         looks authoritative and is not gets quoted back in negotiations. --}}
    @if ($insight['median_views'] === null)
        <p class="hint mt-2">
            Още няма достатъчно подобни обяви, за да сравняваме — показваме само
            твоите числа.
        </p>
    @else
        <p class="hint mt-2">
            Сравнението е спрямо {{ $insight['peers'] }} активни подобни обяви.
        </p>
    @endif

    {{-- The diagnosis. At most three, ordered by what would change the outcome
         most — see ListingInsight::findings(). --}}
    @if ($insight['findings'])
        <div class="mt-3 space-y-2 border-t border-line pt-3">
            @foreach ($insight['findings'] as $finding)
                <div @class([
                    'rounded-md border px-3 py-2',
                    'border-warn/40 bg-warn-soft' => $finding['tone'] === 'warn',
                    'border-good/40 bg-good-soft' => $finding['tone'] === 'good',
                    'border-line bg-surface'      => $finding['tone'] === 'neutral',
                ])>
                    <p class="text-sm font-medium">{{ $finding['title'] }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-ink-muted">{{ $finding['body'] }}</p>

                    {{-- Paid visibility appears ONLY on the finding whose
                         diagnosis is „too few people have seen it". A boost on
                         an overpriced listing spends the seller's money showing
                         more people the same number they already declined —
                         and teaches them boosts do not work, which costs more
                         than the €9 earns. --}}
                    @if ($finding['boost'] && $listing->status === \App\Enums\ListingStatus::Active)
                        <button type="button" wire:click="openBoost({{ $listing->id }})"
                                class="btn-secondary btn-sm mt-2">
                            Виж платените опции
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <p class="hint mt-3 border-t border-line pt-3">
            Нищо не се откроява — обявата се движи като подобните на нея.
        </p>
    @endif
</div>
