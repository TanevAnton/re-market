<div class="mx-auto max-w-2xl">

    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight">Редакция на обява</h1>
        <a href="{{ route('listings.mine') }}" wire:navigate class="link text-sm">← Моите обяви</a>
    </div>

    @error('listing') <p class="error mt-3">{{ $message }}</p> @enderror

    @if ($locked)
        <div class="card-pad mt-6 border-warn/40 bg-warn-soft">
            <p class="text-sm font-medium text-warn">Тази обява не може да се редактира сега</p>
            <p class="mt-1 text-xs leading-relaxed text-warn">
                Статус: {{ $listing->status->label() }}.
            </p>
        </div>
    @endif

    {{-- What is being sold is fixed. Shown rather than hidden, so it is obvious
         this was a decision and not an oversight. --}}
    <div class="card-pad mt-6">
        <p class="label">Артикул</p>
        <p class="mt-1 text-sm font-semibold">{{ $listing->part?->fullName() ?? $listing->title }}</p>
        <p class="hint">
            Моделът и категорията не се променят след публикуване — това би променило
            какво се продава. Ако си избрал грешен модел, изтрий обявата и публикувай нова.
        </p>
    </div>

    {{-- Photos ------------------------------------------------------------- --}}
    <div class="card-pad mt-4">
        <p class="label">Снимки</p>

        <p class="hint mt-1">
            Първата снимка е основната — тя се показва в резултатите от търсене.
        </p>

        <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4">
            @foreach ($listing->images as $index => $image)
                <div class="group relative" wire:key="img-{{ $image->id }}">
                    <img src="{{ $image->thumbUrl() }}" alt=""
                         @class([
                             'aspect-square w-full rounded-lg border object-cover',
                             'border-accent ring-2 ring-accent' => $index === 0,
                             'border-line'                      => $index !== 0,
                             'ring-2 ring-accent/50'            => $index !== 0 && $image->is_timestamp_photo,
                         ])>

                    @if ($index === 0)
                        <span class="absolute left-1 top-1 rounded bg-accent px-1.5 py-0.5 text-[10px]
                                     font-semibold text-[var(--accent-ink)]">
                            основна
                        </span>
                    @else
                        <button type="button" wire:click="makePrimary({{ $image->id }})"
                                class="absolute left-1 top-1 rounded bg-canvas/90 px-1.5 py-0.5 text-[10px]
                                       font-medium text-ink-muted opacity-0 backdrop-blur transition
                                       hover:text-accent group-hover:opacity-100"
                                title="Направи основна снимка">
                            основна
                        </button>
                    @endif

                    <div class="absolute inset-x-1 bottom-1 flex gap-1 opacity-0 transition group-hover:opacity-100">
                        <button type="button" wire:click="markTimestamp({{ $image->id }})"
                                @class([
                                    'flex-1 rounded px-1 py-0.5 text-[10px] font-medium backdrop-blur',
                                    'bg-accent text-[var(--accent-ink)]' => $image->is_timestamp_photo,
                                    'bg-canvas/90'                       => ! $image->is_timestamp_photo,
                                ])
                                title="Снимка с ръкописна бележка">
                            {{ $image->is_timestamp_photo ? '✓ бележка' : 'бележка' }}
                        </button>
                        <button type="button" wire:click="removePhoto({{ $image->id }})"
                                class="rounded bg-canvas/90 px-1.5 py-0.5 text-[10px] font-medium text-bad backdrop-blur">
                            ✕
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        <label class="mt-3 block">
            <span class="hint">Добави още снимки</span>
            <input type="file" wire:model="photos" multiple accept="image/*" class="mt-1 w-full text-sm">
        </label>
        <div wire:loading wire:target="photos" class="hint">Обработваме снимките…</div>
        @error('photos') <p class="error">{{ $message }}</p> @enderror
    </div>

    <form wire:submit="save" class="mt-4 space-y-4">

        <div class="card-pad space-y-4">
            <div>
                <label class="label" for="title">Заглавие</label>
                <input id="title" type="text" wire:model="title" class="mt-1 w-full">
                @error('title') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="description">Описание</label>
                <textarea id="description" wire:model="description" rows="6" class="mt-1 w-full"></textarea>
                @error('description') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="condition">Състояние</label>
                    <select id="condition" wire:model="condition" class="mt-1 w-full">
                        @foreach ($conditions as $c)
                            <option value="{{ $c->value }}">{{ $c->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label" for="city_id">Град</label>
                    <select id="city_id" wire:model="city_id" class="mt-1 w-full">
                        <option value="">— избери —</option>
                        @foreach ($cities as $city)
                            <option value="{{ $city->id }}">{{ $city->name_bg }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Price -------------------------------------------------------- --}}
        <div class="card-pad space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="price">Цена (лв)</label>
                    <input id="price" type="number" step="0.01" min="1" wire:model.live="price" class="mt-1 w-full">
                    @error('price') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="label" for="min_offer">Минимална оферта</label>
                    <input id="min_offer" type="number" step="0.01" min="1" wire:model="min_offer" class="mt-1 w-full">
                    <p class="hint">Не се показва на купувачите. По-ниските оферти отпадат сами.</p>
                    @error('min_offer') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- The one consequence of this form a seller could not guess.
                 Warned before saving, not explained afterwards. --}}
            @if ($this->pendingOffers() && $this->priceChanged())
                <div class="rounded-lg border border-line bg-warn-soft p-3">
                    <p class="text-sm font-medium text-warn">
                        Имаш {{ $this->pendingOffers() }} чакащи оферти по старата цена
                    </p>
                    <p class="mt-1 text-xs leading-relaxed text-warn">
                        При смяна на цената те отпадат — направени са срещу друго число.
                        Купувачите могат да предложат отново веднага, без изчакване.
                    </p>
                </div>
            @endif

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="offers_enabled">
                Приемам оферти
            </label>
        </div>

        {{-- Delivery and trust ------------------------------------------- --}}
        <div class="card-pad space-y-3">
            <p class="label">Доставка</p>
            @foreach (['econt' => 'Еконт', 'speedy' => 'Спиди', 'pickup' => 'Лично предаване'] as $key => $label)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" value="{{ $key }}" wire:model="delivery_options">
                    {{ $label }}
                </label>
            @endforeach

            <label class="flex items-center gap-2 border-t border-line pt-3 text-sm">
                <input type="checkbox" wire:model="accepts_inspect_test">
                Приемам „преглед и тест“
            </label>
            <p class="hint">
                Купувачът тества артикула в офиса на куриера преди да плати.
                Отказът се показва на обявата.
            </p>
        </div>

        {{-- Condition detail ---------------------------------------------- --}}
        <div class="card-pad space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="mining_use">Ползвана за копаене</label>
                    <select id="mining_use" wire:model.live="mining_use" class="mt-1 w-full">
                        @foreach ($miningUses as $m)
                            <option value="{{ $m->value }}">{{ $m->label() }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($mining_use === 'yes')
                    <div>
                        <label class="label" for="mining_months">Колко месеца</label>
                        <input id="mining_months" type="number" min="1" max="120"
                               wire:model="mining_months" class="mt-1 w-full">
                        @error('mining_months') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label class="label" for="warranty_until">Гаранция до</label>
                    <input id="warranty_until" type="date" wire:model="warranty_until" class="mt-1 w-full">
                </div>

                <div>
                    <label class="label" for="validation_url">Връзка към тест (по избор)</label>
                    <input id="validation_url" type="url" wire:model="validation_url" class="mt-1 w-full"
                           placeholder="3DMark, CPU-Z…">
                    @error('validation_url') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="has_receipt">
                Имам касова бележка / фактура
            </label>
        </div>

        @if ($itemSpecs)
            <div class="card-pad space-y-3">
                <p class="label">Данни за този конкретен брой</p>
                @foreach ($itemSpecs as $key => $spec)
                    <div>
                        <label class="text-sm" for="spec-{{ $key }}">
                            {{ $spec['label'][app()->getLocale()] ?? $spec['label']['en'] ?? $key }}
                        </label>
                        <input id="spec-{{ $key }}" type="text" wire:model="specs.{{ $key }}" class="mt-1 w-full">
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex gap-2">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" @disabled($locked)>
                <span wire:loading.remove wire:target="save">Запази промените</span>
                <span wire:loading wire:target="save">Запазваме…</span>
            </button>
            <a href="{{ route('listings.mine') }}" wire:navigate class="btn-ghost">Откажи</a>
        </div>
    </form>
</div>
