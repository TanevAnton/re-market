{{-- One request, in a list. Expects $ad.

     NO PHOTO, and the layout is built around that absence rather than
     apologising for it. A wanted ad has nothing to show — what it has is a
     model name, a ceiling and a place, and those are exactly the three things
     a seller needs to decide in two seconds whether they can help. --}}
<article class="card-interactive group flex flex-col p-4">
    <a href="{{ route('wanted.show', $ad) }}" wire:navigate class="flex flex-1 flex-col">

        <div class="flex flex-wrap items-start justify-between gap-2">
            <h3 class="line-clamp-2 text-sm font-semibold leading-snug transition-colors
                       group-hover:text-accent">
                {{ $ad->title }}
            </h3>

            {{-- The ceiling is the loudest thing here, the way a price is on a
                 listing card. It is what a seller scans for. --}}
            @if ($ad->formattedBudget())
                <span class="price shrink-0 text-sm leading-none">{{ $ad->formattedBudget() }}</span>
            @endif
        </div>

        @if ($ad->part)
            <p class="mt-1.5 font-mono text-[11px] text-ink-muted">{{ $ad->part->fullName() }}</p>
        @endif

        @if ($ad->detail)
            <p class="mt-2 line-clamp-2 text-xs leading-relaxed text-ink-muted">{{ $ad->detail }}</p>
        @endif

        <div class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 pt-4 text-xs text-ink-muted">
            <span>{{ $ad->city?->name() ?? 'Цялата страна' }}</span>

            @if ($ad->created_at)
                <span class="text-ink-faint">{{ $ad->created_at->diffForHumans() }}</span>
            @endif

            {{-- „Already answered by three people" tells a seller whether it is
                 worth their time, which is more useful to them than hiding it
                 would be to the buyer. --}}
            @if (($ad->response_count ?? 0) > 0)
                <span class="badge-neutral">{{ $ad->response_count }} предложени</span>
            @endif
        </div>
    </a>
</article>
