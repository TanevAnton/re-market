{{-- Autosave, delegated from the root rather than bolted onto forty fields.

     `focusout` is used instead of `blur` because blur does not bubble, so one
     listener here would never see it. Debounced, since tabbing through a form
     is a burst of these and each one is a round trip.

     wire:model is deferred by default in Livewire 3, so the values reach the
     server on this very call - the save is of what is on screen, not of what
     was there one field ago. --}}
<div class="mx-auto max-w-3xl"
     x-data
     x-on:focusout.debounce.1500ms="$wire.saveDraft()"
     x-on:form-invalid.window="
         $nextTick(() => {
             const first = $el.querySelector('.error');
             if (first) {
                 first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                 const field = first.closest('div')?.querySelector('input, textarea, select');
                 field?.focus({ preventScroll: true });
             }
         })
     ">

    {{-- ------------------------------------------------ resume a draft

         Offered, never restored silently. Somebody who came here to post a
         second, unrelated card would otherwise find last week's half-written
         ad already in the boxes and have to work out what had happened to
         their form. --}}
    @if ($draftAvailable)
        <div class="mb-6 card border-l-2 border-l-accent p-4">
            <p class="text-sm font-medium">Имаш незапазена обява</p>
            <p class="mt-1 text-xs leading-relaxed text-ink-muted">
                Започнал си я {{ $draftAge }}@if ($draftPhotos) и си качил
                    {{ $draftPhotos }} {{ $draftPhotos === 1 ? 'снимка' : 'снимки' }}@endif.
                Можеш да продължиш оттам или да започнеш начисто.
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" wire:click="resumeDraft" class="btn-primary btn-sm">
                    Продължи
                </button>
                <button type="button" wire:click="discardDraft"
                        wire:confirm="Черновата и качените снимки ще бъдат изтрити. Сигурен ли си?"
                        class="btn-ghost btn-sm">
                    Започни нова
                </button>
            </div>
        </div>
    @endif

    {{-- Said once, at the top, so nobody publishes a copy thinking it is the
         original — and so the missing photos read as deliberate. --}}
    @if ($copiedFrom)
        <div class="mb-6 rounded-md border border-line bg-surface-alt px-3 py-2 text-xs leading-relaxed text-ink-muted">
            Копие на съществуваща обява. Всичко е пренесено без снимките и
            гаранцията — добави снимки на конкретната бройка, която продаваш.
        </div>
    @endif

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

    {{-- One place that says what went wrong, at the top where the button is
         not. Step 3 is long enough that the failing field is usually off
         screen, and a button that silently does nothing reads as a broken
         site rather than a validation error. --}}
    @if ($errors->any())
        <div class="mb-5 rounded-lg border border-bad/40 bg-bad-soft p-4">
            <p class="text-sm font-medium text-bad">
                Липсва нещо, преди да продължим
            </p>
            <ul class="mt-2 space-y-1 text-xs leading-relaxed text-bad">
                @foreach ($errors->all() as $message)
                    <li>· {{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

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
                                    {{ \App\Models\Part::specLabel($v) }}
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
        <p class="hint">
            Заглавие, описание и поне една снимка. Всичко останало е по желание —
            но колкото повече попълниш, толкова по-малко въпроси ще получиш.
        </p>

        <div class="mt-5 space-y-5">
            <div>
                <label class="label" for="title">Заглавие</label>
                <input id="title" type="text" wire:model.blur="title" maxlength="120">
                <p class="hint">
                    Моделът и състоянието, кратко. Поне 8 знака.
                    <span class="font-mono tabular">{{ mb_strlen($title) }}/120</span>
                </p>
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
                <p class="hint">
                    Поне 20 знака. Купувачите питат едно и също — откога е,
                    има ли кутия, защо я продаваш.
                    <span class="font-mono tabular">{{ mb_strlen($description) }}</span>
                </p>
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

            {{-- Everything below is optional and folded away by default.

                 Left open, this is a wall of a dozen fields that a first-time
                 seller reads as required - and the ones who bail here are the
                 supply the marketplace does not get. The specs still matter
                 (they are what makes a listing filterable), so the toggle says
                 what they buy rather than just "advanced". --}}
            {{-- The specs the schema marks required, OUTSIDE the „по желание"
                 panel and above it.

                 They used to sit inside it, unvalidated. Validating them where
                 they were would have rejected the listing over a field in a
                 collapsed panel headed „by choice" — a field the seller cannot
                 see and was told was optional. A required field does not live
                 under that heading. --}}
            @if (count($mustSpecs))
                <div class="space-y-5">
                    @foreach ($mustSpecs as $key => $spec)
                        @include('partials.spec-field', ['key' => $key, 'spec' => $spec, 'required' => true])
                    @endforeach
                </div>
            @endif

            <div class="rounded-lg border border-line">
                <button type="button" wire:click="toggleOptional"
                        class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                    <span>
                        <span class="text-sm font-medium">Подробности по желание</span>
                        <span class="mt-0.5 block text-xs text-ink-muted">
                            Характеристики, гаранция, тест, касова бележка — обявите с
                            попълнени характеристики излизат във филтрите.
                        </span>
                    </span>
                    <span class="shrink-0 font-mono text-xs text-ink-faint">
                        {{ $showOptional ? '−' : '+' }}
                    </span>
                </button>

                @if ($showOptional)
                    <div class="space-y-5 border-t border-line p-4">

            @foreach ($maySpecs as $key => $spec)
                @include('partials.spec-field', ['key' => $key, 'spec' => $spec, 'required' => false])
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

                    </div>
                @endif
            </div>

            {{-- The buyer's checklist, shown to the seller, immediately above
                 the photo upload.

                 Same config and the same words - no second copy to drift.
                 Reading what buyers are told to check IS the brief for a good
                 listing: half these lines are "photograph X" or "show the
                 SMART screenshot", and they are most useful in the second
                 before somebody chooses which pictures to take.

                 This is the supply side of the same feature. An ad that
                 answers these questions gets fewer messages, fewer lowballs
                 and fewer abandoned deals. --}}
            @include('partials.checklist', [
                'items'   => \App\Support\Checklist::forCategory($category),
                'heading' => 'Какво ще проверят купувачите',
                'intro'   => 'Обява, която отговаря на това предварително, получава по-малко въпроси и по-сериозни оферти.',
            ])

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
                @else
                    <div class="mt-2 rounded-md bg-surface-alt px-3 py-2 text-xs text-ink-muted">
                        <strong class="text-ink">Съвет:</strong> снимай вещта до ръкописна бележка с
                        потребителското си име и днешната дата, и я отбележи по-долу. Не е
                        задължително, но е най-бързият начин купувачът да види, че вещта наистина
                        е у теб — обявите с бележка получават повече оферти.
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
                    <p class="hint mt-3">
                        Първата снимка е основната — тя се показва в резултатите от търсене.
                    </p>

                    <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
                        @foreach ($stored as $i => $img)
                            <div wire:key="photo-{{ $img['path'] }}"
                                 @class([
                                     'group relative overflow-hidden rounded-md border',
                                     'border-accent ring-1 ring-accent' => $i === 0,
                                     'border-line'                      => $i !== 0,
                                 ])>
                                <img src="{{ Storage::disk('public')->url($img['thumb']) }}" alt=""
                                     class="aspect-[4/3] w-full object-cover">

                                @if ($i === 0)
                                    <span class="absolute left-1 top-1 rounded bg-accent px-1.5 py-0.5
                                                 text-[10px] font-semibold text-[var(--accent-ink)]">
                                        основна
                                    </span>
                                @else
                                    <button type="button" wire:click="makePrimary({{ $i }})"
                                            class="absolute left-1 top-1 rounded bg-canvas/90 px-1.5 py-0.5
                                                   text-[10px] font-medium text-ink-muted opacity-0
                                                   transition hover:text-accent group-hover:opacity-100">
                                        направи основна
                                    </button>
                                @endif

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
