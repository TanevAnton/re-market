<div class="mx-auto max-w-3xl">

    <h1 class="text-2xl font-bold tracking-tight">
        {{ $bundleId ? 'Редакция на комплект' : 'Нов комплект' }}
    </h1>
    <p class="hint mt-2 max-w-xl">
        Групирай свои обяви, които се продават заедно — например частите на една машина.
        Всяка обява си остава отделна и може да се купи поотделно.
    </p>

    <form wire:submit="save" class="mt-8 space-y-6">

        <div>
            <label for="bundle-title" class="label">Име на комплекта</label>
            <input id="bundle-title" type="text" wire:model="title" maxlength="120"
                   placeholder="Цяла геймърска машина — RTX 3070 и Ryzen 5"
                   class="mt-1.5 w-full">
            @error('title') <p class="error mt-1.5">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="bundle-description" class="label">Описание <span class="text-ink-faint">(по избор)</span></label>
            <textarea id="bundle-description" wire:model="description" rows="4" maxlength="2000"
                      class="mt-1.5 w-full"
                      placeholder="Машината е работила до миналия месец. Продавам я цяла или на части."></textarea>
            @error('description') <p class="error mt-1.5">{{ $message }}</p> @enderror
        </div>

        {{-- The selection ------------------------------------------------ --}}

        <div>
            <p class="label">Кои обяви влизат</p>

            @if ($listings->isEmpty())
                <div class="card-pad mt-1.5 text-center">
                    <p class="text-sm font-medium">Нямаш активни обяви, които да групираш.</p>
                    <p class="hint mx-auto mt-2 max-w-md">
                        В комплект влизат само активни обяви, които не са вече в друг комплект.
                    </p>
                    <a href="{{ route('listing.create') }}" wire:navigate class="btn-primary mt-4 inline-block">
                        Публикувай обява
                    </a>
                </div>
            @else
                <div class="mt-1.5 space-y-2">
                    @foreach ($listings as $listing)
                        @php $checked = in_array($listing->id, $selected, true); @endphp

                        <label wire:key="pick-{{ $listing->id }}"
                               @class([
                                   'flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition',
                                   'border-accent bg-accent/5' => $checked,
                                   'border-line hover:border-ink-faint' => ! $checked,
                               ])>
                            <input type="checkbox" wire:click="toggle({{ $listing->id }})"
                                   @checked($checked) class="shrink-0">

                            @if ($img = $listing->coverImage())
                                <img src="{{ $img->thumbUrl() }}" alt=""
                                     class="h-12 w-12 shrink-0 rounded-md border border-line object-cover">
                            @else
                                <div class="grid h-12 w-12 shrink-0 place-items-center rounded-md border
                                            border-line bg-surface-alt text-[10px] text-ink-faint">няма</div>
                            @endif

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium">{{ $listing->title }}</span>
                                <span class="hint">{{ $listing->city?->name() }}</span>
                            </span>

                            <span class="tabular shrink-0 text-sm font-semibold">
                                {{ $listing->formattedPrice() }}
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif

            @error('selected') <p class="error mt-2">{{ $message }}</p> @enderror
        </div>

        {{-- The price ---------------------------------------------------- --}}

        <div class="card-pad">
            <label for="bundle-price" class="label">Цена за целия комплект (€) <span class="text-ink-faint">(по избор)</span></label>
            <input id="bundle-price" type="text" inputmode="decimal" wire:model.live.debounce.400ms="price"
                   class="tabular mt-1.5 w-40" placeholder="900">
            @error('price') <p class="error mt-1.5">{{ $message }}</p> @enderror

            <div class="mt-4 space-y-1 text-sm">
                <p class="text-ink-muted">
                    Избраните обяви, събрани:
                    <span class="tabular font-semibold text-ink">
                        {{ number_format($this->sumCents() / 100, 2, ',', ' ') }} €
                    </span>
                </p>

                @if ($saving = $this->savingCents())
                    <p class="font-semibold text-good">
                        Купувачът спестява {{ number_format($saving / 100, 2, ',', ' ') }} €,
                        ако вземе всичко.
                    </p>
                @endif
            </div>

            {{-- Said here rather than discovered later: the price disappearing
                 by itself is the rule that makes the whole feature safe, and a
                 seller who is surprised by it will think the site is broken. --}}
            <p class="hint mt-4">
                Ако оставиш полето празно, комплектът просто показва частите заедно, без обща цена.
                Щом някоя от обявите се продаде или запази, цената за комплект спира да се показва
                автоматично — останалите продължават да се продават поотделно.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="btn-primary">
                {{ $bundleId ? 'Запази' : 'Създай комплект' }}
            </button>

            @if ($bundleId)
                <a href="{{ route('bundle', $this->bundle()) }}" wire:navigate class="btn-ghost">Откажи</a>
            @else
                <a href="{{ route('bundles.mine') }}" wire:navigate class="btn-ghost">Откажи</a>
            @endif
        </div>
    </form>

    {{-- Breaking it up ---------------------------------------------------- --}}

    @if ($bundleId)
        <div class="card-pad mt-10">
            @if ($confirmingDissolve)
                <p class="text-sm font-medium">Да разформироваме ли комплекта?</p>
                <p class="hint mt-2">
                    Обявите остават — всяка продължава да се продава сама, на своята цена.
                    Изчезва само групата и общата ѝ цена.
                </p>
                <div class="mt-4 flex gap-2">
                    <button type="button" wire:click="dissolve" class="btn-primary btn-sm">
                        Да, разформировай
                    </button>
                    <button type="button" wire:click="cancelDissolve" class="btn-ghost btn-sm">Откажи</button>
                </div>
            @else
                <button type="button" wire:click="confirmDissolve" class="btn-ghost btn-sm">
                    Разформировай комплекта
                </button>
                <p class="hint mt-2">Обявите остават непокътнати.</p>
            @endif
        </div>
    @endif
</div>
