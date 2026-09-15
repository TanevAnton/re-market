{{-- The recorded price series for one model.

     No raw PHP in this file, in either form — the plot arrives through an
     assignment inside @if, which is an expression rather than a block. See the
     note at the top of browse-listings.blade.php for why that distinction is
     load-bearing here.

     Expects $trend (App\Models\Part::priceTrend) and $history, and renders
     nothing at all when the series cannot support a claim. $tone is 'buyer' or
     'seller': the same movement is good news to one of them and bad news to the
     other, which is exactly why the number is NOT colour-coded. Green for a
     falling price would be telling a seller their loss is a win. --}}

@if ($trend)
    <section class="card-pad {{ $class ?? '' }}">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="label">Как се движи цената</h2>
            <span class="font-mono text-[11px] text-ink-faint">
                {{ $trend['days'] }} дни · {{ $trend['points'] }} измервания
            </span>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-4">
            <div>
                <p class="font-mono text-xl font-semibold tabular">
                    @if ($trend['direction'] === 'flat')
                        <span class="text-ink-muted">без промяна</span>
                    @else
                        <span class="text-ink-muted" aria-hidden="true">{{ $trend['percent'] < 0 ? '↓' : '↑' }}</span>
                        {{ abs($trend['percent']) }}%
                    @endif
                </p>
                <p class="mt-1 font-mono text-xs text-ink-faint tabular">
                    {{ number_format($trend['from'] / 100, 0, ',', ' ') }} €
                    →
                    {{ number_format($trend['to'] / 100, 0, ',', ' ') }} €
                </p>
            </div>

            @if ($plot = \App\Support\Sparkline::plot($history))
                {{-- preserveAspectRatio="none" so the line fills whatever width
                     the card gives it; a sparkline has no meaningful aspect
                     ratio, only a shape. --}}
                <svg viewBox="0 0 240 40" preserveAspectRatio="none" role="img"
                     aria-label="Движение на средната цена за периода"
                     class="h-10 min-w-0 flex-1 overflow-visible">
                    <polyline points="{{ $plot['points'] }}"
                              fill="none" stroke="currentColor" stroke-width="2"
                              stroke-linecap="round" stroke-linejoin="round"
                              class="text-accent" vector-effect="non-scaling-stroke"/>
                    <circle cx="{{ $plot['last']['x'] }}" cy="{{ $plot['last']['y'] }}" r="3"
                            fill="currentColor" class="text-accent"/>
                </svg>
            @endif
        </div>

        <p class="hint mt-4">
            {{-- Parenthesised deliberately: ?? binds looser than ===, so
                 `$tone ?? 'buyer' === 'seller'` means `$tone ?? false` and
                 takes the seller branch for every caller that passes a tone at
                 all. It compiles, it renders, and it is simply wrong. --}}
            @if (($tone ?? 'buyer') === 'seller')
                Средната <strong>искана</strong> цена за този модел, по нашите обяви.
            @else
                Средната <strong>искана</strong> цена, а не цените на сключените сделки.
            @endif

            @if ($trend['sample'] < 6)
                Изчислено от малко обяви на ден — движението може да е случайно.
            @endif

            Измерва се веднъж дневно; дните без достатъчно обяви липсват в графиката.
        </p>
    </section>
@endif
