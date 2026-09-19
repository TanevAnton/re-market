<div class="mx-auto max-w-4xl">

    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight">Моите комплекти</h1>
        <a href="{{ route('bundle.create') }}" wire:navigate class="btn-primary btn-sm">Нов комплект</a>
    </div>

    {{-- No flash block here: the layout already renders session('status') for
         every page, and a second copy would show every message twice. --}}

    @if ($bundles->isEmpty())
        <div class="card-pad mt-6 text-center">
            <p class="text-sm font-medium">Още нямаш комплекти.</p>
            <p class="hint mx-auto mt-2 max-w-md">
                Ако продаваш части от една машина, групирай ги — купувачът вижда, че си пасват,
                и може да вземе всичко наведнъж на обща цена.
            </p>
            <a href="{{ route('bundle.create') }}" wire:navigate class="btn-primary mt-4 inline-block">
                Създай комплект
            </a>
        </div>
    @endif

    <div class="mt-6 space-y-3">
        @foreach ($bundles as $bundle)
            <div class="card-pad" wire:key="bundle-{{ $bundle->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('bundle', $bundle) }}" wire:navigate
                           class="text-sm font-semibold hover:text-accent">
                            {{ $bundle->title }}
                        </a>

                        <p class="hint mt-1">
                            {{ $bundle->listings->count() }}
                            {{ $bundle->listings->count() === 1 ? 'обява' : 'обяви' }}
                            · общо {{ $bundle->formattedSum() }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <span class="badge-neutral">{{ $bundle->status->label() }}</span>
                        <a href="{{ route('bundle.edit', $bundle) }}" wire:navigate class="btn-secondary btn-sm">
                            Редактирай
                        </a>
                    </div>
                </div>

                @if ($package = $bundle->formattedPackagePrice())
                    <p class="mt-3 text-sm">
                        <span class="price">{{ $package }}</span>
                        @if ($saving = $bundle->formattedSaving())
                            <span class="ml-2 text-good">−{{ $saving }}</span>
                        @endif
                    </p>
                @elseif ($bundle->price_cents)
                    {{-- The withdrawal rule, stated to the person it affects.
                         The seller is the one who needs to know the discount
                         stopped showing, and why, before they wonder. --}}
                    <p class="mt-3 text-sm text-warn">
                        Цената за комплект не се показва — част от обявите вече не са активни.
                    </p>
                @endif
            </div>
        @endforeach
    </div>
</div>
