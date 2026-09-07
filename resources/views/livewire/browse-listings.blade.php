<div class="grid gap-6 lg:grid-cols-[250px_1fr]">

    {{-- ------------------------------------------------ filters --}}
    <aside class="space-y-4">
        <div class="card-pad">
            <label class="label" for="q">Търсене</label>
            <input id="q" type="search" wire:model.live.debounce.400ms="q" placeholder="ртх 4090, 7800x3d…"
                   class="mt-2">
            <p class="hint">Работи на кирилица и латиница</p>
        </div>

        <div class="card-pad">
            <label class="label" for="cat">Категория</label>
            <select id="cat" wire:model.live="category" class="mt-2">
                <option value="">Всички</option>
                @foreach ($categories as $c)
                    <option value="{{ $c['key'] }}">{{ $c['label'] }}</option>
                @endforeach
            </select>
        </div>

        <div class="card-pad">
            <span class="label">Цена (€)</span>
            <div class="mt-2 flex items-center gap-2">
                <input type="number" min="0" wire:model.live.debounce.600ms="priceMin" placeholder="от"
                       class="font-mono">
                <span class="text-ink-faint">–</span>
                <input type="number" min="0" wire:model.live.debounce.600ms="priceMax" placeholder="до"
                       class="font-mono">
            </div>
        </div>

        <div class="card-pad">
            <span class="label">Състояние</span>
            <div class="mt-2 space-y-1.5">
                @foreach ($conditions as $cond)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" value="{{ $cond->value }}" wire:model.live="condition">
                        {{ $cond->label() }}
                    </label>
                @endforeach
            </div>
        </div>

        {{-- Facets come from config/catalog.php. Adding a filter is a config
             key, not a query - which is why GPUs get nine and mice get two. --}}
        @foreach ($facets as $key => $spec)
            @php $options = $this->facetOptions($key, $spec); @endphp

            <div class="card-pad">
                <span class="label">
                    {{ $filter->label($key) }}
                    @if ($filter->unit($key))
                        <span class="normal-case text-ink-faint">({{ $filter->unit($key) }})</span>
                    @endif
                </span>

                @if ($filter->help($key))
                    <p class="hint">{{ $filter->help($key) }}</p>
                @endif

                @if ($spec['facet'] === 'range')
                    <div class="mt-2 flex items-center gap-2">
                        <input type="number" wire:model.live.debounce.600ms="specs.{{ $key }}.min"
                               placeholder="от" class="font-mono">
                        <span class="text-ink-faint">–</span>
                        <input type="number" wire:model.live.debounce.600ms="specs.{{ $key }}.max"
                               placeholder="до" class="font-mono">
                    </div>
                @elseif ($spec['facet'] === 'bool')
                    <label class="mt-2 flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="specs.{{ $key }}">
                        Само с {{ mb_strtolower($filter->label($key)) }}
                    </label>
                @else
                    <div class="mt-2 max-h-44 space-y-1.5 overflow-y-auto pr-1">
                        @forelse ($options as $opt)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" value="{{ $opt }}" wire:model.live="specs.{{ $key }}">
                                <span class="font-mono text-[13px]">{{ $opt }}</span>
                                @if ($filter->unit($key))
                                    <span class="text-ink-faint">{{ $filter->unit($key) }}</span>
                                @endif
                            </label>
                        @empty
                            <p class="text-xs text-ink-faint">няма данни</p>
                        @endforelse
                    </div>
                @endif
            </div>
        @endforeach
    </aside>

    {{-- ------------------------------------------------ results --}}
    <section>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <p class="text-sm text-ink-muted">
                <strong class="font-mono text-ink tabular">{{ number_format($listings->total(), 0, ',', ' ') }}</strong>
                обяви
            </p>

            <div class="flex-1"></div>

            <button type="button" wire:click="clearFilters" class="btn-ghost btn-sm">Изчисти филтрите</button>

            <select wire:model.live="sort" class="w-auto py-1.5 text-sm">
                <option value="new">Най-нови</option>
                <option value="price_asc">Цена ↑</option>
                <option value="price_desc">Цена ↓</option>
                <option value="views">Най-гледани</option>
            </select>
        </div>

        <div wire:loading.class="opacity-40" class="transition-opacity">
            @if ($listings->isEmpty())
                <div class="card border-dashed p-12 text-center">
                    <p class="font-medium">Няма обяви по тези филтри</p>
                    <p class="mt-1 text-sm text-ink-muted">Опитай с по-широки критерии.</p>
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($listings as $listing)
                        @include('partials.listing-card', ['listing' => $listing])
                    @endforeach
                </div>

                <div class="mt-6">{{ $listings->links() }}</div>
            @endif
        </div>
    </section>
</div>
