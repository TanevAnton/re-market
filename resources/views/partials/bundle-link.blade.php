{{-- „Тази част е от цяла машина."

     Shown on a listing that belongs to a bundle. This is the cross-link that
     makes the feature worth having: somebody who arrived at one graphics card
     from a search has no way of knowing the rest of the machine is for sale
     from the same person, and that is exactly the buyer a package price is
     aimed at.

     Only for a PUBLIC bundle. A draft or one waiting for review is not
     something to advertise from a live listing — the link would lead to a 404
     for everyone but its owner.

     Expects: $listing. --}}

{{-- $bundle->listings is the loaded COLLECTION, not listings() the query.
     packagePrice() reads the same collection to decide whether the package is
     still whole, so counting it here costs nothing extra; counting through the
     relation would put four more queries on every listing page. --}}
@if (($bundle = $listing->bundle) && $bundle->status->isPubliclyVisible() && $bundle->listings->isNotEmpty())
    <a href="{{ route('bundle', $bundle) }}" wire:navigate
       class="card-interactive block p-4 {{ $class ?? '' }}">

        <p class="text-xs font-semibold uppercase tracking-wide text-accent">Част от комплект</p>

        <p class="mt-1.5 text-sm font-medium">{{ $bundle->title }}</p>

        <p class="hint mt-1">
            @if ($package = $bundle->formattedPackagePrice())
                Всичките {{ $bundle->listings->count() }} части заедно —
                <span class="tabular font-semibold text-ink">{{ $package }}</span>@if ($saving = $bundle->formattedSaving()), спестяваш {{ $saving }}@endif.
            @else
                Още {{ max(0, $bundle->listings->count() - 1) }}
                {{ $bundle->listings->count() - 1 === 1 ? 'част' : 'части' }}
                от същата машина, от същия продавач.
            @endif
        </p>
    </a>
@endif
