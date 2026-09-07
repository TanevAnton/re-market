{{-- Shared by browse and profile. One card definition, one place to change. --}}
<article class="card group flex flex-col overflow-hidden transition hover:border-accent">
    <a href="{{ route('listing', $listing) }}" wire:navigate class="flex flex-1 flex-col">

        <div class="aspect-[4/3] bg-surface-alt">
            @if ($img = $listing->coverImage())
                <img src="{{ $img->thumbUrl() }}" alt="{{ $listing->title }}" loading="lazy"
                     class="h-full w-full object-cover">
            @else
                <div class="grid h-full place-items-center text-xs text-ink-faint">без снимка</div>
            @endif
        </div>

        <div class="flex flex-1 flex-col p-3">
            <h3 class="line-clamp-2 text-sm font-medium leading-snug group-hover:text-accent">
                {{ $listing->title }}
            </h3>

            @if ($listing->part)
                <p class="mt-1 font-mono text-[11px] text-ink-muted">
                    @foreach (array_slice($listing->part->specs, 0, 3) as $v)
                        <span class="whitespace-nowrap">{{ is_bool($v) ? ($v ? 'да' : 'не') : $v }}</span>@if (! $loop->last) · @endif
                    @endforeach
                </p>
            @endif

            <div class="mt-2 flex flex-wrap gap-1">
                <span class="badge-neutral">{{ $listing->condition->label() }}</span>
                @if ($listing->mining_use === \App\Enums\MiningUse::Yes)
                    <span class="badge-warn">копала {{ $listing->mining_months }} мес.</span>
                @endif
                @if ($listing->accepts_inspect_test)
                    <span class="badge-good">преглед и тест</span>
                @endif
            </div>

            <div class="mt-auto pt-3">
                <p class="price text-lg">{{ $listing->formattedPrice() }}</p>
                <p class="mt-0.5 text-xs text-ink-muted">
                    {{ $listing->city?->name() }}@if ($listing->offers_enabled) · приема оферти @endif
                </p>
            </div>
        </div>
    </a>
</article>
