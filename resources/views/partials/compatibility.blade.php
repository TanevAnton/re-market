{{-- Cross-category links built from the catalogue's own typed specs.

     Shared by the listing page and the model page. $links comes from
     App\Support\Compatibility, which has already dropped every edge with
     nothing behind it - so this partial never renders a link to an empty
     result, and renders nothing at all when there is nothing to say. --}}

@if (! empty($links))
    {{-- $class carries the caller's spacing rather than a wrapper div: an
         empty div with mt-6 on it still leaves a hole in the page on every
         model that has no compatibility edges, which is most of them. --}}
    <section class="card-pad {{ $class ?? '' }}">
        <h2 class="label">{{ $heading ?? 'Какво пасва с този модел' }}</h2>
        <p class="hint">Филтрите са нагласени — водят право към активните обяви.</p>

        <ul class="mt-3 space-y-2">
            @foreach ($links as $link)
                <li>
                    <a href="{{ $link['url'] }}" wire:navigate
                       class="flex items-baseline justify-between gap-2 rounded-md px-2 py-1.5
                              text-sm transition hover:bg-surface-alt hover:text-accent">
                        <span class="min-w-0">{{ $link['label'] }}</span>
                        <span class="shrink-0 font-mono text-[11px] tabular text-ink-faint">
                            {{ $link['count'] }}
                        </span>
                    </a>

                    @if ($link['why'])
                        <p class="px-2 text-xs leading-relaxed text-ink-faint">{{ $link['why'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
