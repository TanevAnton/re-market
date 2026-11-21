{{-- The price chart: the median line, the shaded low-to-high band around it, and
     a tooltip that follows the pointer.

     Expects $plot from App\Support\PriceChart::plot().

     NO RAW PHP IN THIS FILE, either form. All the arithmetic is in PriceChart;
     what arrives here is percentages of the plot area, which is why the SVG
     (a viewBox of 0 0 100 100, stretched to fit) and the HTML laid over it
     (dots, axis labels, the tooltip) can be positioned from the same numbers.

     The dots are HTML and not SVG circles: in a stretched SVG a circle is an
     ellipse. The strokes use vector-effect so they stay two pixels thick
     however the box is stretched.

     NOT COLOUR-CODED by direction. The same fall is good news to a buyer and bad
     news to a seller, and both pages draw this - see price-trend.blade.php.

     Tooltip: the nearest point by x, on pointer move and on touch. touch-pan-y
     keeps vertical scrolling working when a finger lands on the chart. --}}

<div class="mt-5"
     x-data="{
         pts: @js($plot['points']),
         i: null,
         pick(event) {
             const box = this.$refs.area.getBoundingClientRect();
             if (! box.width) return;
             const at = (event.clientX - box.left) / box.width * 100;
             let best = 0, gap = Infinity;
             this.pts.forEach((p, k) => {
                 const d = Math.abs(p.x - at);
                 if (d < gap) { gap = d; best = k; }
             });
             this.i = best;
         },
     }">
    <div class="flex gap-2">

        {{-- Value axis. Positioned by the same percentages as the gridlines. --}}
        <div class="relative h-44 w-[46px] shrink-0 font-mono text-[10px] tabular text-ink-faint sm:h-56" aria-hidden="true">
            @foreach ($plot['y_ticks'] as $tick)
                <span class="absolute right-0 -translate-y-1/2 whitespace-nowrap"
                      style="top: {{ $tick['y'] }}%">{{ $tick['label'] }}</span>
            @endforeach
        </div>

        <div class="min-w-0 flex-1">
            <div x-ref="area"
                 class="relative h-44 cursor-crosshair touch-pan-y select-none sm:h-56"
                 x-on:pointermove="pick($event)"
                 x-on:pointerdown="pick($event)"
                 x-on:pointerleave="i = null">

                <svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img"
                     aria-label="Графика на средната искана цена за периода"
                     class="absolute inset-0 h-full w-full overflow-visible">
                    @foreach ($plot['y_ticks'] as $tick)
                        <line x1="0" x2="100" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}"
                              class="stroke-line" stroke-width="1" vector-effect="non-scaling-stroke"/>
                    @endforeach

                    @foreach ($plot['bands'] as $band)
                        <path d="{{ $band }}" class="fill-accent/15"/>
                    @endforeach

                    @foreach ($plot['lines'] as $line)
                        <polyline points="{{ $line }}" fill="none" class="stroke-accent" stroke-width="2"
                                  stroke-linecap="round" stroke-linejoin="round"
                                  vector-effect="non-scaling-stroke"/>
                    @endforeach
                </svg>

                {{-- Where the series ends. --}}
                <span class="pointer-events-none absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-accent"
                      style="left: {{ $plot['last']['x'] }}%; top: {{ $plot['last']['y'] }}%"></span>

                {{-- Under the pointer. --}}
                <div x-show="i !== null" x-cloak class="pointer-events-none absolute inset-y-0 w-px -translate-x-1/2 bg-line-strong"
                     x-bind:style="i !== null ? `left: ${pts[i].x}%` : ''"></div>

                <span x-show="i !== null" x-cloak
                      class="pointer-events-none absolute h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-surface bg-accent"
                      x-bind:style="i !== null ? `left: ${pts[i].x}%; top: ${pts[i].y}%` : ''"></span>

                <div x-show="i !== null" x-cloak
                     class="pointer-events-none absolute top-0 z-10 w-[152px] rounded-lg border border-line-strong bg-surface p-2.5 shadow-[var(--shadow-lift)]"
                     x-bind:style="i !== null ? `left: min(max(0px, calc(${pts[i].x}% - 76px)), calc(100% - 152px))` : ''">
                    <p class="font-mono text-[10.5px] text-ink-faint" x-text="i !== null ? pts[i].date : ''"></p>
                    <p class="mt-0.5 font-mono text-[15px] font-semibold tabular" x-text="i !== null ? pts[i].median_label : ''"></p>
                    <p class="mt-0.5 text-[11px] text-ink-muted" x-text="i !== null ? 'от ' + pts[i].range_label : ''"></p>
                    <p class="text-[11px] text-ink-faint" x-text="i !== null ? 'по ' + pts[i].sample + ' обяви' : ''"></p>
                </div>
            </div>

            {{-- Date axis. --}}
            <div class="relative mt-1.5 h-4 font-mono text-[10px] tabular text-ink-faint" aria-hidden="true">
                @foreach ($plot['x_ticks'] as $tick)
                    <span class="absolute top-0 whitespace-nowrap {{ $tick['align'] === 'end' ? '-translate-x-full' : ($tick['align'] === 'center' ? '-translate-x-1/2' : '') }}"
                          style="left: {{ $tick['x'] }}%">{{ $tick['label'] }}</span>
                @endforeach
            </div>
        </div>
    </div>
</div>
