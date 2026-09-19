<div class="mx-auto max-w-5xl">

    @if (! $bundle->status->isPubliclyVisible())
        {{-- Only the owner and moderators ever reach this branch. Saying so
             plainly beats a page that looks live to the one person who would
             assume it is. --}}
        <div class="card-pad mb-6 border-warn/40">
            <p class="text-sm font-medium">Комплектът не е публичен.</p>
            <p class="hint mt-1">Състояние: {{ $bundle->status->label() }}. Виждаш го, защото е твой.</p>
        </div>
    @endif

    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-accent">Комплект</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight">{{ $bundle->title }}</h1>
            <p class="mt-1.5 text-sm text-ink-muted">
                {{ $bundle->listings->count() }}
                {{ $bundle->listings->count() === 1 ? 'обява' : 'обяви' }}
                от
                <a href="{{ route('profile', $bundle->user->username) }}" wire:navigate class="link">
                    {{ $bundle->user->name }}
                </a>
            </p>
        </div>

        @auth
            @if (auth()->id() === $bundle->user_id)
                <a href="{{ route('bundle.edit', $bundle) }}" wire:navigate class="btn-secondary btn-sm">
                    Редактирай
                </a>
            @endif
        @endauth
    </div>

    @if ($bundle->description)
        <p class="mt-4 max-w-2xl whitespace-pre-line text-sm leading-relaxed text-ink-muted">
            {{ $bundle->description }}
        </p>
    @endif

    {{-- The price box ---------------------------------------------------- --}}

    <div class="card-pad mt-6">
        @if ($package = $bundle->formattedPackagePrice())
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="hint">Цена за целия комплект</p>
                    <p class="price mt-1 text-3xl leading-none">{{ $package }}</p>
                </div>

                <div class="text-right">
                    <p class="hint">Поотделно</p>
                    <p class="tabular mt-1 text-lg text-ink-muted line-through">{{ $bundle->formattedSum() }}</p>

                    @if ($saving = $bundle->formattedSaving())
                        <p class="mt-1 text-sm font-semibold text-good">
                            спестяваш {{ $saving }}
                            @if ($pct = $bundle->savingPercent())
                                ({{ $pct }}%)
                            @endif
                        </p>
                    @endif
                </div>
            </div>

            {{-- Said out loud, because it is the whole reason a buyer clicks a
                 bundle instead of the cheapest card in it. --}}
            <p class="hint mt-4">
                Цената важи, ако вземеш всичко наведнъж. Всяка част се продава и поотделно,
                на своята си цена.
            </p>
        @elseif ($bundle->price_cents && ! $bundle->isComplete())
            {{-- THE WITHDRAWAL RULE, visible. The package price is not shown at
                 all here: a machine advertised at a package price with its
                 graphics card already sold is a price nobody can honour. --}}
            <p class="text-sm font-medium">Цената за комплект вече не важи.</p>
            <p class="hint mt-2">
                Част от обявите не са налични. Останалите се продават поотделно,
                всяка на своята цена.
            </p>

            @if ($bundle->missing()->isNotEmpty())
                <ul class="mt-3 space-y-1 text-sm text-ink-muted">
                    @foreach ($bundle->missing() as $gone)
                        <li>· {{ $gone->title }} — {{ $gone->status->label() }}</li>
                    @endforeach
                </ul>
            @endif
        @else
            <p class="text-sm font-medium">Частите се продават поотделно.</p>
            <p class="hint mt-2">
                Продавачът ги е групирал, за да се виждат заедно — цена за целия комплект няма.
                Общо за всички: <span class="tabular">{{ $bundle->formattedSum() }}</span>.
            </p>
        @endif
    </div>

    {{-- The members ------------------------------------------------------ --}}

    <h2 class="mt-10 text-lg font-semibold tracking-tight">Какво влиза</h2>

    @if ($bundle->listings->isEmpty())
        <p class="hint mt-3">В комплекта вече няма обяви.</p>
    @else
        <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
            @foreach ($bundle->listings as $listing)
                <div wire:key="member-{{ $listing->id }}" @class([
                    'relative',
                    'opacity-60' => ! $listing->status->isPubliclyVisible()
                                    || $listing->status !== \App\Enums\ListingStatus::Active,
                ])>
                    @include('partials.listing-card', ['listing' => $listing])

                    @if ($listing->status !== \App\Enums\ListingStatus::Active)
                        <span class="absolute left-2 top-2 z-10 badge-neutral backdrop-blur">
                            {{ $listing->status->label() }}
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Compatibility is the reason this feature exists: these parts are
         already known to work together, because they came out of one machine. --}}
    <div class="card-pad mt-10">
        <p class="text-sm font-medium">Частите идват от една машина.</p>
        <p class="hint mt-2">
            Това е и най-простият начин да си сглобиш втора употреба — нищо не трябва да
            се проверява за съвместимост, защото вече е работело заедно.
        </p>
        <a href="{{ route('build') }}" wire:navigate class="btn-ghost btn-sm mt-3 inline-block">
            Сглоби машина от части
        </a>
    </div>
</div>
