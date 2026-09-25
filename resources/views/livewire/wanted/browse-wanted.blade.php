<div class="mx-auto max-w-6xl">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Търсения</h1>
            <p class="mt-1 max-w-2xl text-sm text-ink-muted">
                Какво търсят купувачите в момента. Ако имаш такова нещо —
                предложи обявата си директно, безплатно.
            </p>
        </div>

        <a href="{{ route('wanted.create') }}" wire:navigate class="btn-primary btn-sm shrink-0">
            Публикувай търсене
        </a>
    </div>

    <div class="mt-6 flex flex-wrap items-end gap-3">
        <div>
            <label class="label" for="kat">Категория</label>
            <select id="kat" wire:model.live="category" class="mt-1 w-auto">
                <option value="">Всички</option>
                @foreach ($categories as $c)
                    <option value="{{ $c['key'] }}">{{ $c['label'] }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="label" for="grad">Град</label>
            <select id="grad" wire:model.live="city" class="mt-1 w-auto">
                <option value="">Цялата страна</option>
                @foreach ($cities as $c)
                    <option value="{{ $c->slug }}">{{ $c->name() }}</option>
                @endforeach
            </select>
        </div>

        @if ($category || $city)
            <button type="button" wire:click="clearFilters" class="btn-ghost btn-sm">Изчисти</button>
        @endif
    </div>

    <div wire:loading.class="opacity-40" class="mt-6 transition-opacity">
        @if ($ads->isEmpty())
            {{-- The empty state here points the OTHER way from the listings
                 one. Somebody looking at an empty demand board is usually a
                 seller, and the useful thing to tell them is that posting what
                 they have is free — not to wait for a request that may never
                 come. --}}
            <div class="card border-dashed p-12 text-center">
                <p class="font-medium">Няма търсения по тези филтри</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-ink-muted">
                    Ако продаваш, публикувай обявата си — купувачите я намират
                    и без да са оставили търсене.
                </p>

                <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                    <a href="{{ route('listing.create') }}" wire:navigate class="btn-primary">
                        Публикувай обява
                    </a>
                    <a href="{{ route('wanted.create') }}" wire:navigate class="btn-ghost">
                        Или кажи какво търсиш
                    </a>
                </div>
            </div>
        @else
            <p class="text-sm text-ink-muted">
                <strong class="font-mono text-ink tabular">{{ $ads->total() }}</strong> търсения
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($ads as $ad)
                    @include('partials.wanted-card', ['ad' => $ad])
                @endforeach
            </div>

            <div class="mt-6">{{ $ads->links() }}</div>
        @endif
    </div>
</div>
