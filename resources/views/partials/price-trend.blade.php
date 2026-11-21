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

        </div>

        {{-- The chart itself, full width under the headline numbers: it used to
             be a forty-pixel sparkline beside them, which said which way the
             price was going and nothing about where it had been. Hover or touch
             a point for the day's median and its low-to-high range. The
             assignment inside @if is an expression, not a block - see the note
             at the top of this file. --}}
        @if ($plot = \App\Support\PriceChart::plot($history))
            @include('partials.price-chart', ['plot' => $plot])
        @endif

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

            Измерва се веднъж дневно; дните без достатъчно обяви липсват в графиката,
            а линията не се свързва през тях. Сенчестата област е разликата между ниска и висока цена.
        </p>
    </section>
@endif
