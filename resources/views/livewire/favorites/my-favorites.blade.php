<div class="mx-auto max-w-7xl">

    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight">Запазени обяви</h1>
        <a href="{{ route('searches') }}" wire:navigate class="link text-sm">Запазени търсения →</a>
    </div>

    @if ($gone)
        <p class="hint mt-2">
            {{ $gone }} от запазените вече не са активни. Оставяме ги видими —
            цената, на която нещо е приключило, обикновено е това, което те интересува.
        </p>
    @endif

    @if ($favorites->isEmpty())
        <div class="card-pad mt-6 text-center">
            <p class="text-sm font-medium">Още нямаш запазени обяви.</p>
            <p class="hint mx-auto mt-2 max-w-md">
                Натисни сърцето върху всяка обява, за да я добавиш тук. Удобно е,
                когато сравняваш няколко варианта, преди да пишеш на някого.
            </p>
            <a href="{{ route('browse') }}" wire:navigate class="btn-primary mt-4 inline-block">
                Разгледай обявите
            </a>
        </div>
    @else
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($favorites as $favorite)
                @php($listing = $favorite->listing)

                <div class="relative" wire:key="fav-{{ $favorite->id }}">
                    @unless ($listing->status->isPubliclyVisible())
                        {{-- Dimmed and labelled rather than removed. --}}
                        <div class="pointer-events-none absolute inset-0 z-10 grid place-items-center
                                    rounded-xl bg-canvas/65">
                            <span class="badge-neutral backdrop-blur">
                                {{ $listing->status->label() }}
                            </span>
                        </div>
                    @endunless

                    @include('partials.listing-card', [
                        'listing'       => $listing,
                        'withFavorite'  => false,
                    ])

                    {{-- Positioned here rather than by the card, so it sits
                         above the "sold" veil on a listing that has ended -
                         unsaving one has to stay possible. --}}
                    <div class="absolute right-2 top-2 z-20">
                        @livewire('favorites.favorite-button',
                            ['listing' => $listing, 'compact' => true],
                            key('favbtn-'.$listing->id))
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-8">{{ $favorites->links() }}</div>
    @endif
</div>
