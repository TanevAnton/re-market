{{-- The side of the listing form: what the listing will look like, and how much
     of what helps it sell is filled in. Takes $preview (array) and $progress
     (CreateListing::completeness()).

     THE PREVIEW IS THE REAL CARD'S SHAPE, drawn from the form's own state. A
     seller who can see the photograph, the title and the price the way a buyer
     will meets the gaps while there is still a form open to fix them in, rather
     than on a listing that is already live.

     THE METER IS ARITHMETIC, NOT A PROMISE. It counts the things this form can
     ask for; it does not say the item will sell, and its caption says so. --}}
<div class="space-y-4" data-listing-aside>

    <div>
        <p class="font-mono text-[10.5px] uppercase tracking-[0.18em] text-ink-faint">Така ще изглежда</p>

        <article class="card mt-2 overflow-hidden">
            <div class="relative aspect-[4/3] bg-surface-alt">
                @if ($preview['cover'])
                    <img src="{{ $preview['cover'] }}" alt="" class="h-full w-full object-cover">
                @else
                    <div class="grid h-full place-items-center text-xs text-ink-faint">снимката ти ще е тук</div>
                @endif

                <div class="absolute left-2 top-2 flex flex-wrap gap-1">
                    @if ($preview['inspect'])
                        <span class="badge-good backdrop-blur">преглед и тест</span>
                    @endif
                </div>

                @if ($preview['photos'] > 1)
                    <span class="absolute bottom-2 right-2 rounded bg-canvas/80 px-1.5 py-0.5 font-mono text-[10px] backdrop-blur">
                        {{ $preview['photos'] }} снимки
                    </span>
                @endif
            </div>

            <div class="p-4">
                <h3 @class([
                    'line-clamp-2 text-sm font-semibold leading-snug',
                    'text-ink-faint' => ! $preview['title'],
                ])>{{ $preview['title'] ?? 'Заглавието ще се покаже тук' }}</h3>

                @if ($preview['chips'] !== [])
                    <ul class="mt-2 flex flex-wrap gap-1">
                        @foreach ($preview['chips'] as $chip)
                            <li class="whitespace-nowrap rounded-md border border-line bg-surface-alt px-1.5 py-0.5
                                       font-mono text-[11px] leading-none text-ink-muted">{{ $chip }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($preview['condition'])
                    <div class="mt-2.5"><span class="badge-neutral">{{ $preview['condition'] }}</span></div>
                @endif

                <div class="mt-3 flex items-end justify-between gap-2">
                    <div>
                        <p @class(['price text-xl leading-none', 'text-ink-faint' => ! $preview['price']])>
                            {{ $preview['price'] ?? '— €' }}
                        </p>
                        <p class="mt-1.5 text-xs text-ink-muted">{{ $preview['city'] ?? 'град' }}</p>
                    </div>

                    @if ($preview['offers'])
                        <span class="shrink-0 rounded-md border border-line px-1.5 py-0.5 text-[10px]
                                     font-medium uppercase tracking-wide text-ink-faint">оферти</span>
                    @endif
                </div>
            </div>
        </article>
    </div>

    <div class="card-pad">
        <div class="flex items-baseline justify-between gap-3">
            <p class="text-sm font-semibold">Пълнота на обявата</p>
            <p class="font-mono text-sm tabular" data-progress="{{ $progress['percent'] }}">{{ $progress['percent'] }}%</p>
        </div>

        <div class="mt-2 h-2 overflow-hidden rounded-full bg-surface-alt" role="progressbar"
             aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}"
             aria-label="Пълнота на обявата">
            <div class="h-full rounded-full bg-accent transition-all duration-500"
                 style="width: {{ $progress['percent'] }}%"></div>
        </div>

        <p class="mt-2 text-xs font-medium">
            @switch($progress['level'])
                @case('strong') Силна обява @break
                @case('good') Добра — още малко @break
                @default Започната
            @endswitch
        </p>

        @if ($progress['tips'] !== [])
            <ul class="mt-3 space-y-1.5">
                @foreach ($progress['tips'] as $tip)
                    <li class="flex gap-2 text-xs leading-relaxed text-ink-muted">
                        <span class="mt-px text-accent" aria-hidden="true">＋</span>
                        <span>{{ $tip }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="hint mt-3">Показва колко от препоръчаното е попълнено, а не колко бързо ще се продаде.</p>
    </div>
</div>
