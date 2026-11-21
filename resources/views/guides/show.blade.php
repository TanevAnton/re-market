{{-- One buyer's guide, rendered from config/guides.php. The text goes through
     App\Support\Guides::html(), which escapes first. --}}
@php($related = collect($guide['related'] ?? [])->map(fn ($s) => [$s, \App\Support\Guides::find($s)])->filter(fn ($r) => $r[1]))

<x-layouts.app
    :title="$guide['title']"
    :description="$guide['description']"
    :canonical="route('guide', $slug)"
    :json-ld="$jsonLd"
    og-type="article">

    <article class="legal mx-auto max-w-3xl">
        <nav class="text-xs text-ink-faint" aria-label="Пътека">
            <a href="{{ route('guides') }}" wire:navigate class="link">Ръководства</a>
            <span aria-hidden="true">/</span>
            <span>{{ $guide['title'] }}</span>
        </nav>

        <h1 class="!mt-3">{{ $guide['title'] }}</h1>
        <p class="lead">{!! \App\Support\Guides::html($guide['lead']) !!}</p>
        <p class="hint !mt-2">
            Обновено {{ \App\Support\Guides::dateBg($guide['updated']) }} · {{ $guide['minutes'] }} мин четене
        </p>

        @foreach ($guide['sections'] as $section)
            <h2>{{ $section['h'] }}</h2>

            @foreach ($section['p'] ?? [] as $paragraph)
                <p>{!! \App\Support\Guides::html($paragraph) !!}</p>
            @endforeach

            @if (! empty($section['ul']))
                <ul>
                    @foreach ($section['ul'] as $item)
                        <li>{!! \App\Support\Guides::html($item) !!}</li>
                    @endforeach
                </ul>
            @endif

            @if (! empty($section['after']))
                <p>{!! \App\Support\Guides::html($section['after']) !!}</p>
            @endif
        @endforeach

        @if (! empty($guide['cta']))
            <div class="callout !mt-10">
                <p class="!mt-0 font-semibold text-ink">{{ $guide['cta']['title'] }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($guide['cta']['links'] as $i => [$route, $label])
                        <a href="{{ route($route) }}" wire:navigate
                           class="{{ $i === 0 ? 'btn-primary' : 'btn-ghost' }} !no-underline">{{ $label }}</a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($related->isNotEmpty())
            <h2>Още ръководства</h2>
            <ul>
                @foreach ($related as [$relatedSlug, $relatedGuide])
                    <li>
                        <a href="{{ route('guide', $relatedSlug) }}" wire:navigate>{{ $relatedGuide['title'] }}</a>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="mt-10 border-t border-line pt-4 text-xs text-ink-faint">
            Общи съвети, не юридическа или техническа гаранция. Сделките са между купувача и продавача;
            RIGO не държи парите.
        </p>
    </article>
</x-layouts.app>
