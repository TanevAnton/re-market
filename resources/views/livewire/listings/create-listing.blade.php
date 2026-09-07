<div class="mx-auto max-w-3xl">

    {{-- ---------------------------------------------------- progress --}}
    <ol class="mb-8 flex items-center gap-2 text-xs">
        @foreach ([1 => 'Категория', 2 => 'Модел', 3 => 'Състояние', 4 => 'Цена'] as $n => $label)
            <li class="flex flex-1 items-center gap-2">
                <span @class([
                    'grid h-6 w-6 shrink-0 place-items-center rounded-full font-mono text-[11px] font-semibold',
                    'bg-accent text-[var(--accent-ink)]' => $step >= $n,
                    'bg-surface-alt text-ink-faint'      => $step < $n,
                ])>{{ $n }}</span>
                <span @class([
                    'hidden truncate sm:inline',
                    'font-medium text-ink' => $step === $n,
                    'text-ink-faint'       => $step !== $n,
                ])>{{ $label }}</span>
                @unless ($loop->last)
                    <span @class(['h-px flex-1', 'bg-accent' => $step > $n, 'bg-line' => $step <= $n])></span>
                @endunless
            </li>
        @endforeach
    </ol>

    {{-- ==================================================== step 1 --}}
    @if ($step === 1)
        <h1 class="text-xl font-semibold tracking-tight">Какво продаваш?</h1>
        <p class="hint">Категорията определя кои характеристики ще те попитаме.</p>

        <div class="mt-5 grid gap-2 sm:grid-cols-3">
            @foreach ($categories as $c)
                <button type="button" wire:click="$set('category', '{{ $c['key'] }}')"
                        @class([
                            'card px-3 py-3 text-left text-sm transition hover:border-accent',
                            'border-accent bg-accent-soft text-accent' => $category === $c['key'],
                        ])>
                    {{ $c['label'] }}
                </button>
            @endforeach
        </div>
        @error('category') <p class="error">{{ $message }}</p> @enderror

    {{-- ==================================================== step 2 --}}
    @elseif ($step === 2)
        <h1 class="text-xl font-semibold tracking-tight">Кой точно модел?</h1>
        <p class="hint">
            Свързването с каталога прави обявата ти намираема по филтри —
            дължина, памет, консумация.
        </p>

        @if ($part)
            <div class="card-pad mt-5 border-accent bg-accent-soft">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="font-medium">{{ $part->fullName() }}</p>
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach (array_slice($part->specs, 0, 5) as $k => $v)
                                <span class="badge-neutral font-mono">
                                    {{ is_bool($v) ? ($v ? 'да' : 'не') : $v }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                    <button type="button" wire:click="clearPart" class="btn-ghost btn-sm">Смени</button>
                </div>
            </div>
        @elseif ($partNotListed)
            <div class="mt-5">
                <label class="label" for="customPart">Име на модела</label>
                <input id="customPart" type="text" wire:model.blur="customPart"
                       placeholder="напр. Gigabyte RTX 4070 Gaming OC">
                @error('customPart') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">
                    Ще го добавим в каталога ръчно. Обявата ти ще излиза при търсене,
                    но не и по филтри за характеристики.
                </p>
                <button type="button" wire:click="$set('partNotListed', false)"
                        class="btn-ghost btn-sm mt-3 px-0!">← Търси в каталога</button>
            </div>
        @else
            <div class="mt-5">
                <input type="search" wire:model.live.debounce.300ms="partSearch"
                       placeholder="ртх 4070, 7800x3d, rx 6700…" autofocus>
                <p class="hint">Пиши на кирилица или латиница — и двете работят.</p>

                @if (mb_strlen($partSearch) >= 2)
                    <div class="mt-3 divide-y divide-line overflow-hidden rounded-lg border border-line">
                        @forelse ($results as $r)
                            <button type="button" wire:click="choosePart({{ $r->id }})"
                                    class="flex w-full items-center justify-between gap-3 bg-surface px-3 py-2.5
                                           text-left text-sm transition hover:bg-surface-alt">
                                <span>
                                    <span class="font-medium">{{ $r->model }}</span>
                                    <span class="ml-1 text-ink-faint">{{ $r->manufacturer }}</span>
                                </span>
                                <span class="shrink-0 font-mono text-xs text-ink-muted">
                                    @if ($r->spec('vram_gb')) {{ $r->spec('vram_gb') }} GB @endif
                                    @if ($r->spec('cores')) {{ $r->spec('cores') }}C @endif
                                </span>
                            </button>
                        @empty
                            <p class="bg-surface px-3 py-4 text-sm text-ink-muted">Няма съвпадения.</p>
                        @endforelse
                    </div>
                @endif

                <button type="button" wire:click="useCustomPart" class="btn-ghost btn-sm mt-3 px-0!">
                    Моделът ми го няма в списъка
                </button>
            </div>
        @endif

    {{-- ==================================================== step 3 --}}
    @elseif ($step === 3)
        <h1 class="text-xl font-semibold tracking-tight">Състояние и снимки</h1>

        <div class="mt-5 space-y-5">
            <div>
                <label class="label" for="title">Заглавие</label>
                <input id="title" type="text" wire:model.blur="title" maxlength="120">
                @error('title') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="condition">Състояние</label>
                <select id="condition" wire:model.live="condition">
                    @foreach ($conditions as $c)
                        <option value="{{ $c->value }}">{{ $c->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="label" for="description">Описание</label>
                <textarea id="description" wire:model.blur="description" rows="5"
                          placeholder="Как е ползвана, има ли забележки, какво включва комплектът."></textarea>
                @error('description') <p class="error">{{ $message }}</p> @enderror
            </div>

            @if ($category === 'gpu')
                <div class="card-pad">
                    <label class="label" for="mining_use">Използвана ли е за копане?</label>
                    <select id="mining_use" wire:model.live="mining_use" class="mt-2">
                        @foreach ($miningUses as $m)
                            <option value="{{ $m->value }}">{{ $m->label() }}</option>
                        @endforeach
                    </select>
                    @if ($mining_use === 'yes')
                        <div class="mt-3">
                            <label class="label" for="mining_months">Колко месеца</label>
                            <input id="mining_months" type="number" min="1" max="120"
                                   wire:model.blur="mining_months" class="mt-1">
                        </div>
                    @endif
                    <p class="hint">Честният отговор продава по-бързо, отколкото мълчанието.</p>
                </div>
            @endif

            @foreach ($itemSpecs as $key => $spec)
                <div>
                    <label class="label" for="spec-{{ $key }}">
                        {{ $spec['label'][app()->getLocale()] ?? $spec['label']['en'] }}
                        @if (! empty($spec['unit'])) <span class="normal-case">({{ $spec['unit'] }})</span> @endif
                    </label>
                    @if ($spec['type'] === 'bool')
                        <label class="mt-1 flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="specs.{{ $key }}"> да
                        </label>
                    @elseif ($spec['type'] === 'select')
                        <select id="spec-{{ $key }}" wire:model="specs.{{ $key }}" class="mt-1">
                            <option value="">—</option>
                            @foreach ($spec['options'] ?? [] as $opt)
                                <option value="{{ $opt }}">{{ $opt }}</option>
                            @endforeach
                        </select>
                    @else
                        <input id="spec-{{ $key }}" type="{{ $spec['type'] === 'int' ? 'number' : 'text' }}"
                               wire:model.blur="specs.{{ $key }}" class="mt-1">
                    @endif
                    @if (! empty($spec['help']))
                        <p class="hint">{{ $spec['help'][app()->getLocale()] ?? $spec['help']['en'] }}</p>
                    @endif
                </div>
            @endforeach

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="warranty_until">Гаранция до</label>
                    <input id="warranty_until" type="date" wire:model.blur="warranty_until" class="mt-1">
                    @error('warranty_until') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="validation_url">Линк към тест (по желание)</label>
                    <input id="validation_url" type="url" wire:model.blur="validation_url"
                           placeholder="3DMark / CPU-Z валидация" class="mt-1">
                    @error('validation_url') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="has_receipt"> Имам касова бележка / фактура
            </label>

            {{-- ------------------------------------------------ photos --}}
            <div class="card-pad">
                <div class="flex items-baseline justify-between">
                    <span class="label">Снимки</span>
                    <span class="font-mono text-xs text-ink-muted tabular">
                        {{ count($stored) }} / {{ config('remarket.listings.max_images', 12) }}
                    </span>
                </div>

                @if ($this->requiresTimestampPhoto())
                    <div class="mt-2 rounded-md bg-warn-soft px-3 py-2 text-xs text-warn">
                        <strong>Задължително:</strong> една снимка на вещта до ръкописна бележка
                        с потребителското ти име и днешната дата. Това е най-бързият начин
                        купувачът да види, че вещта наистина е у теб.
                    </div>
                @endif

                <input type="file" wire:model="photos" multiple accept="image/*"
                       class="mt-3 block w-full text-sm file:mr-3 file:rounded-md file:border-0
                              file:bg-surface-alt file:px-3 file:py-2 file:text-sm file:text-ink">
                <div wire:loading wire:target="photos" class="hint">Качваме и обработваме…</div>
                @error('photos') <p class="error">{{ $message }}</p> @enderror
                @error('photos.*') <p class="error">{{ $message }}</p> @enderror
                @error('stored') <p class="error">{{ $message }}</p> @enderror
                @error('timestampIndex') <p class="error">{{ $message }}</p> @enderror

                @if ($stored)
                    <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4">
                        @foreach ($stored as $i => $img)
                            <div class="group relative overflow-hidden rounded-md border border-line">
                                <img src="{{ Storage::disk('public')->url($img['thumb']) }}" alt=""
                                     class="aspect-[4/3] w-full object-cover">

                                <button type="button" wire:click="removePhoto({{ $i }})"
                                        class="absolute right-1 top-1 rounded bg-canvas/90 px-1.5 py-0.5
                                               text-xs text-bad opacity-0 transition group-hover:opacity-100">
                                    ✕
                                </button>

                                <button type="button" wire:click="markTimestamp({{ $i }})"
                                        @class([
                                            'absolute inset-x-0 bottom-0 px-1 py-1 text-[10px] font-medium transition',
                                            'bg-accent text-[var(--accent-ink)]' => $timestampIndex === $i,
                                            'bg-canvas/85 text-ink-muted'        => $timestampIndex !== $i,
                                        ])>
                                    {{ $timestampIndex === $i ? '✓ с бележка' : 'отбележи' }}
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

    {{-- ==================================================== step 4 --}}
    @else
        <h1 class="text-xl font-semibold tracking-tight">Цена и доставка</h1>

        <div class="mt-5 space-y-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="price">Цена (€)</label>
                    <input id="price" type="number" step="0.01" min="1" wire:model.blur="price"
                           class="mt-1 font-mono text-lg">
                    @error('price') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="city_id">Град</label>
                    <select id="city_id" wire:model="city_id" class="mt-1">
                        <option value="">Избери</option>
                        @foreach ($cities as $c)
                            <option value="{{ $c->id }}">{{ $c->name_bg }}</option>
                        @endforeach
                    </select>
                    @error('city_id') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- The offer floor: the mechanism that removes haggling without
                 removing negotiation. --}}
            <div class="card-pad">
                <label class="flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" wire:model.live="offers_enabled">
                    Приемам оферти
                </label>

                @if ($offers_enabled)
                    <div class="mt-3">
                        <label class="label" for="min_offer">Минимална оферта (€)</label>
                        <input id="min_offer" type="number" step="0.01" min="1"
                               wire:model.blur="min_offer" class="mt-1 font-mono">
                        @error('min_offer') <p class="error">{{ $message }}</p> @enderror
                        <p class="hint">
                            Оферти под тази сума се отказват автоматично и <strong>не стигат до теб</strong>.
                            Купувачът вижда само, че е под прага — не и каква е сумата.
                            Празно поле значи, че виждаш всяка оферта.
                        </p>
                    </div>
                @endif
            </div>

            <div>
                <span class="label">Доставка</span>
                <div class="mt-2 space-y-1.5">
                    @foreach (['econt' => 'Еконт', 'speedy' => 'Спиди', 'pickup' => 'Лично предаване'] as $key => $label)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" value="{{ $key }}" wire:model="delivery_options"> {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('delivery_options') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="card-pad">
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" wire:model="accepts_inspect_test" class="mt-0.5">
                    <span>
                        <span class="font-medium">Приемам „преглед и тест“</span>
                        <span class="mt-0.5 block text-xs text-ink-muted">
                            Купувачът отваря и пробва вещта в офиса на куриера, преди да плати.
                            Обявите без тази опция получават предупредителен знак.
                        </span>
                    </span>
                </label>
            </div>
        </div>
    @endif

    {{-- ---------------------------------------------------- nav --}}
    <div class="mt-8 flex items-center gap-3 border-t border-line pt-5">
        @if ($step > 1)
            <button type="button" wire:click="back" class="btn-secondary">Назад</button>
        @endif

        <div class="flex-1"></div>

        @if ($step < 4)
            <button type="button" wire:click="next" class="btn-primary">Продължи</button>
        @else
            <x-turnstile />
            @error('turnstile') <p class="error">{{ $message }}</p> @enderror

            <button type="button" wire:click="publish" class="btn-primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="publish">Публикувай обявата</span>
                <span wire:loading wire:target="publish">Публикуваме…</span>
            </button>
        @endif
    </div>
</div>
