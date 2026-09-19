{{-- „Активните обяви за този модел вървят по 560 – 700 €."

     Shown while the seller is typing a price, which is the one moment the band
     is worth more than on any catalogue page — and the one place it was never
     shown.

     Two-sided, unlike the public „под средното" badge, because the audience is
     different: that badge is a claim about somebody's listing in front of
     buyers, this is private advice before anything is published. See
     App\Support\PriceGuidance.

     Takes $guidance (or nothing, in which case it renders nothing). --}}

@if ($guidance ?? null)
    <div class="card-pad border-l-2 border-l-accent">
        <h3 class="label">Колко върви този модел</h3>

        <p class="mt-2 font-mono text-sm tabular">
            {{ number_format($guidance['band']['p25'] / 100, 0, ',', ' ') }} –
            {{ number_format($guidance['band']['p75'] / 100, 0, ',', ' ') }} €
            <span class="text-ink-muted">· средно</span>
            <span class="font-semibold">{{ number_format($guidance['band']['median'] / 100, 0, ',', ' ') }} €</span>
        </p>

        {{-- „Asking", not „sold". Offers are private and a deal's agreed price
             may not even be stored, so „продава се по" would be a claim the
             site cannot support. --}}
        <p class="hint">Това са цени, на които се ПРЕДЛАГА в момента, не на които е продадено.</p>

        @if ($guidance['trend'] && $guidance['trend']['direction'] !== 'flat')
            <p class="mt-2 text-xs leading-relaxed text-ink-muted">
                За последните {{ $guidance['trend']['days'] }} дни средната цена
                @if ($guidance['trend']['direction'] === 'down')
                    <span class="font-medium text-bad">пада с {{ abs($guidance['trend']['percent']) }}%</span> —
                    ако чакаш, ще чакаш на по-ниска цена.
                @else
                    <span class="font-medium text-good">се покачва с {{ abs($guidance['trend']['percent']) }}%</span>.
                @endif
            </p>
        @endif

        @if ($note = \App\Support\PriceGuidance::note($guidance))
            <p class="mt-3 rounded-md bg-surface-alt px-3 py-2 text-xs leading-relaxed text-ink-muted">
                {{ $note }}
            </p>
        @endif
    </div>
@endif
