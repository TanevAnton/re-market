{{-- Autosave, delegated from the root rather than bolted onto forty fields.

     `focusout` is used instead of `blur` because blur does not bubble, so one
     listener here would never see it. Debounced, since tabbing through a form
     is a burst of these and each one is a round trip.

     wire:model is deferred by default in Livewire 3, so the values reach the
     server on this very call - the save is of what is on screen, not of what
     was there one field ago.

     THREE STEPS TO THE SELLER, FOUR TO THE SERVER. What is shown is „what is
     it", „details", „price"; the component still moves through category and
     model as two steps, because that is what its validation and its drafts are
     built on. The step the seller is on is `$visible`.

     PHOTOS FIRST. People list from a phone, and the photograph is the thing
     already in their hand. The photo block is one block, rendered once, and
     its place on the page follows the step - see the `order-*` classes. --}}
@php
    $labels  = collect($categories)->pluck('label', 'key');
    $cover   = ($stored[0]['thumb'] ?? null) ? Storage::disk('public')->url($stored[0]['thumb']) : null;
    $maxImg  = (int) config('remarket.listings.max_images', 12);

    $preview = [
        'cover'     => $cover,
        'photos'    => count($stored),
        'title'     => trim($title) !== '' ? $title : null,
        'chips'     => $part
            ? array_slice(array_map(fn ($v) => \App\Models\Part::specLabel($v), array_values($part->specs ?? [])), 0, 3)
            : [],
        'condition' => \App\Enums\ListingCondition::tryFrom($condition)?->label(),
        'price'     => is_numeric($price) && (float) $price > 0
            ? number_format((float) $price, 2, ',', ' ').' €'
            : null,
        'city'      => $city_id ? $cities->firstWhere('id', (int) $city_id)?->name_bg : null,
        'offers'    => $offers_enabled,
        'inspect'   => $accepts_inspect_test,
    ];

    $stepLabels = [1 => 'Какво продаваш', 2 => 'Детайли и снимки', 3 => 'Цена и доставка'];
@endphp

<div class="mx-auto max-w-6xl"
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
    <ol class="mb-8 flex items-center gap-2 text-xs" aria-label="Стъпки">
        @foreach ($stepLabels as $n => $label)
            <li class="flex flex-1 items-center gap-2" @if ($visible === $n) aria-current="step" @endif>
                <span @class([
                    'grid h-7 w-7 shrink-0 place-items-center rounded-full font-mono text-[11px] font-semibold transition',
                    'bg-accent text-[var(--accent-ink)]' => $visible >= $n,
                    'bg-surface-alt text-ink-faint'      => $visible < $n,
                    'ring-4 ring-accent/20'              => $visible === $n,
                ])>{{ $visible > $n ? '✓' : $n }}</span>
                <span @class([
                    'hidden truncate sm:inline',
                    'font-semibold text-ink' => $visible === $n,
                    'text-ink-faint'         => $visible !== $n,
                ])>{{ $label }}</span>
                @unless ($loop->last)
                    <span @class(['h-px flex-1 transition', 'bg-accent' => $visible > $n, 'bg-line' => $visible <= $n])></span>
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

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_340px]">

        {{-- ============================================== the form column --}}
        <div class="flex min-w-0 flex-col gap-6">

            {{-- ------------------------------------------- the other way to sell

                 The shop buys graphics cards outright, and this is where somebody is
                 about to spend ten minutes listing one - so it is said here, before
                 they start, rather than after.

                 ON THE FIRST TWO STEPS ONLY, and only while it could still apply:
                 before a category is chosen, and after that only if the category is
                 one the shop buys. Pick „Процесори" and it goes. From step three on
                 the seller is describing and pricing their own listing, and a banner
                 offering to take them somewhere else has stopped being information
                 and started being an interruption.

                 Absent until the offer has an address; see RetechOffer. --}}
            @if ($step <= 2 && \App\Support\RetechOffer::enabled()
                    && ($category === '' || \App\Support\RetechOffer::appliesTo($category)))
                <div class="order-1">
                    @include('partials.retech-buyback', [
                        'placement' => 'listing-form',
                        'variant'   => 'banner',
                    ])
                </div>
            @endif

            {{-- ------------------------------------------------- the heading --}}
            <div class="order-2">
                @if ($step === 1)
                    <h1 class="text-2xl font-bold tracking-tight">Какво продаваш?</h1>
                    <p class="hint mt-1">
                        Започни със снимка или с модела — останалото ще познаем.
                        Категорията определя кои характеристики ще те попитаме.
                    </p>
                @elseif ($step === 2)
                    <h1 class="text-2xl font-bold tracking-tight">Кой точно модел?</h1>
                    <p class="hint mt-1">
                        Свързването с каталога прави обявата ти намираема по филтри —
                        дължина, памет, консумация.
                    </p>
                @elseif ($step === 3)
                    <h1 class="text-2xl font-bold tracking-tight">Състояние и снимки</h1>
                    <p class="hint mt-1">
                        Заглавие, описание и поне една снимка. Всичко останало е по желание —
                        но колкото повече попълниш, толкова по-малко въпроси ще получиш.
                    </p>
                @else
                    <h1 class="text-2xl font-bold tracking-tight">Цена и доставка</h1>
                    <p class="hint mt-1">Последна стъпка. Купувачът плаща при получаване.</p>
                @endif
            </div>

            {{-- ======================================== the photographs

                 ONE BLOCK, TWO PLACES. On the first step it comes before the
                 search - the photograph is already in the seller's hand - and on
                 the details step it comes after the fields. The `order` utilities
                 do the moving, so the markup, the controls and the tests that
                 read them stay in one piece.

                 DRAG AND DROP is the label around the file input plus a few lines
                 of Alpine: dropped files are put on the input and a `change` event
                 is fired, so Livewire's own upload code does the rest. The input
                 stays a real input, which is what a phone's camera sheet needs. --}}
            @if ($step === 1 || $step === 3)
                <div @class(['order-3' => $step === 1, 'order-6' => $step === 3]) data-photo-block>
                    <div class="card-pad"
                         x-data="{ over: false, uploading: false, pct: 0 }"
                         x-on:livewire-upload-start="uploading = true; pct = 0"
                         x-on:livewire-upload-progress="pct = $event.detail.progress"
                         x-on:livewire-upload-finish="uploading = false"
                         x-on:livewire-upload-error="uploading = false">

                        <div class="flex items-baseline justify-between">
                            <span class="label">Снимки</span>
                            <span class="font-mono text-xs text-ink-muted tabular">
                                {{ count($stored) }} / {{ $maxImg }}
                            </span>
                        </div>

                        <label for="photo-input"
                               x-on:dragover.prevent="over = true"
                               x-on:dragleave.prevent="over = false"
                               x-on:drop.prevent="
                                   over = false;
                                   const files = [...$event.dataTransfer.files]
                                       .filter(f => f.type.startsWith('image/') || /\.(heic|heif)$/i.test(f.name))
                                       .slice(0, 5);
                                   if (files.length) {
                                       const dt = new DataTransfer();
                                       files.forEach(f => dt.items.add(f));
                                       $refs.file.files = dt.files;
                                       $refs.file.dispatchEvent(new Event('change', { bubbles: true }));
                                   }
                               "
                               :class="over ? 'border-accent bg-accent-soft' : 'border-line-strong bg-surface-alt/60'"
                               @class([
                                   'mt-3 flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2',
                                   'border-dashed px-4 text-center transition hover:border-accent',
                                   'py-12' => $step === 1,
                                   'py-7'  => $step !== 1,
                               ])>
                            <span class="grid h-12 w-12 place-items-center rounded-full bg-accent-soft text-accent" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                                     stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6">
                                    <path d="M4 8a2 2 0 0 1 2-2h1.5l1.2-1.6A1 1 0 0 1 9.5 4h5a1 1 0 0 1 .8.4L16.5 6H18a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8Z"/>
                                    <circle cx="12" cy="12.5" r="3.2"/>
                                </svg>
                            </span>
                            <span class="text-sm font-semibold">
                                <span class="sm:hidden">Снимай или избери от галерията</span>
                                <span class="hidden sm:inline">Пусни снимките тук или натисни, за да избереш</span>
                            </span>
                            <span class="text-xs text-ink-muted">JPG, PNG, WEBP, HEIC · до 10 MB на снимка</span>

                            <input id="photo-input" x-ref="file" type="file" wire:model="photos" multiple accept="image/*"
                                   class="sr-only">
                        </label>

                        {{-- Said BEFORE they pick, because the failure it prevents is
                             invisible. Livewire sends every selected file in one POST, and
                             a POST over `post_max_size` dies at nginx with a bare 413:
                             Laravel never runs, so there is nothing to catch and nothing to
                             show. A sentence here is worth more than an error handler that
                             cannot fire.

                             Five, not six, deliberately: 64M of POST holds six 10M photos
                             exactly, and „exactly" is not a margin. A drop is cut to five
                             in the handler above for the same reason. --}}
                        <p class="hint mt-2">
                            До 10 MB на снимка, и избирай до 5 наведнъж — иначе качването
                            може да се скъса без съобщение. Можеш да добавиш още след това.
                        </p>

                        <div x-show="uploading" x-cloak class="mt-3">
                            <div class="h-1.5 overflow-hidden rounded-full bg-surface-alt">
                                <div class="h-full rounded-full bg-accent transition-all" :style="`width: ${pct}%`"></div>
                            </div>
                        </div>
                        <div wire:loading wire:target="photos" class="hint mt-2">Качваме и обработваме…</div>

                        @error('photos') <p class="error">{{ $message }}</p> @enderror
                        @error('photos.*') <p class="error">{{ $message }}</p> @enderror
                        @error('stored') <p class="error">{{ $message }}</p> @enderror
                        @error('timestampIndex') <p class="error">{{ $message }}</p> @enderror

                        @if ($step === 1 && $stored === [])
                            <div class="mt-4 grid gap-2 text-xs text-ink-muted sm:grid-cols-3">
                                <p class="rounded-md bg-surface-alt px-3 py-2"><strong class="text-ink">1.</strong> Цялата вещ, на светло и на равна повърхност.</p>
                                <p class="rounded-md bg-surface-alt px-3 py-2"><strong class="text-ink">2.</strong> Близък план на конекторите и етикета.</p>
                                <p class="rounded-md bg-surface-alt px-3 py-2"><strong class="text-ink">3.</strong> Вещта до бележка с името ти и датата.</p>
                            </div>
                        @endif

                        @if ($this->requiresTimestampPhoto())
                            <div class="mt-3 rounded-md bg-warn-soft px-3 py-2 text-xs text-warn">
                                <strong>Задължително:</strong> една снимка на вещта до ръкописна бележка
                                с потребителското ти име и днешната дата. Това е най-бързият начин
                                купувачът да види, че вещта наистина е у теб.
                            </div>
                        @else
                            <div class="mt-3 rounded-md bg-surface-alt px-3 py-2 text-xs text-ink-muted">
                                <strong class="text-ink">Съвет:</strong> снимай вещта до ръкописна бележка с
                                потребителското си име и днешната дата, и я отбележи по-долу. Не е
                                задължително, но е най-бързият начин купувачът да види, че вещта наистина
                                е у теб — обявите с бележка получават повече оферти.
                            </div>
                        @endif

                        @if ($stored)
                            <p class="hint mt-3">
                                Първата снимка е основната — тя се показва в резултатите от търсене.
                            </p>

                            {{-- THE PHOTO CONTROLS USED TO BE HOVER-ONLY, WHICH MEANT
                                 THEY DID NOT EXIST ON A PHONE.

                                 A hover-only control never fires on Android Chrome and on
                                 iOS Safari fires on the first tap and then needs a second
                                 one to activate. So a seller photographing a card on a
                                 phone - which is how the first twenty listings on this site
                                 will be posted - could not delete a blurry photo and could
                                 not choose which one buyers see first. The tap targets were
                                 also about 16px tall against a 44px minimum.

                                 Now: a persistent strip under each thumbnail, every button
                                 at least 44px (`min-h-11`). And two columns rather than
                                 three on a phone, for two reasons - three 33px buttons
                                 cannot hold a Bulgarian word, and at two across the photo
                                 is big enough to see whether it is actually in focus. --}}
                            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                @foreach ($stored as $i => $img)
                                    <div wire:key="photo-{{ $img['path'] }}"
                                         @class([
                                             'overflow-hidden rounded-lg border bg-surface',
                                             'border-accent ring-1 ring-accent' => $i === 0,
                                             'border-line'                      => $i !== 0,
                                         ])>
                                        <div class="relative">
                                            <img src="{{ Storage::disk('public')->url($img['thumb']) }}" alt=""
                                                 class="aspect-[4/3] w-full object-cover">

                                            @if ($i === 0)
                                                <span class="absolute left-1 top-1 rounded bg-accent px-1.5 py-0.5
                                                             text-[10px] font-semibold text-[var(--accent-ink)]">
                                                    основна
                                                </span>
                                            @endif
                                        </div>

                                        <div class="flex divide-x divide-line border-t border-line">
                                            @if ($i !== 0)
                                                <button type="button" wire:click="makePrimary({{ $i }})"
                                                        class="min-h-11 flex-1 truncate px-1 text-[11px] font-medium
                                                               text-ink-muted active:bg-surface-alt">
                                                    основна
                                                </button>
                                            @endif

                                            <button type="button" wire:click="markTimestamp({{ $i }})"
                                                    @class([
                                                        'min-h-11 flex-1 truncate px-1 text-[11px] font-medium',
                                                        'bg-accent text-[var(--accent-ink)]' => $timestampIndex === $i,
                                                        'text-ink-muted active:bg-surface-alt' => $timestampIndex !== $i,
                                                    ])>
                                                {{ $timestampIndex === $i ? '✓ бележка' : 'бележка' }}
                                            </button>

                                            <button type="button" wire:click="removePhoto({{ $i }})"
                                                    class="min-h-11 w-11 shrink-0 text-sm text-bad active:bg-surface-alt"
                                                    aria-label="Изтрий снимката">
                                                ✕
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- ============================================== the steps --}}
            <div class="order-4">

                {{-- ================================================ step 1 --}}
                @if ($step === 1)
                    <div class="space-y-6">

                        <div class="card-pad">
                            <label class="label" for="partSearchAny">Знаеш модела? Напиши го</label>
                            <input id="partSearchAny" type="search" wire:model.live.debounce.300ms="partSearch"
                                   placeholder="ртх 4070, 7800x3d, rx 6700…" class="mt-1.5" autocomplete="off">
                            <p class="hint">Пиши на кирилица или латиница — и двете работят. Ще познаем категорията.</p>

                            @if (mb_strlen($partSearch) >= 2)
                                <div class="mt-3 divide-y divide-line overflow-hidden rounded-lg border border-line">
                                    @forelse ($anyResults as $r)
                                        <button type="button" wire:click="chooseAnyPart({{ $r->id }})"
                                                class="flex w-full items-center gap-3 bg-surface px-3 py-2.5
                                                       text-left text-sm transition hover:bg-surface-alt">
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-surface-alt text-ink-muted">
                                                @include('partials.category-icon', ['category' => $r->category, 'class' => 'h-5 w-5'])
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate font-medium">{{ $r->fullName() }}</span>
                                                <span class="block text-xs text-ink-faint">{{ $labels[$r->category] ?? $r->category }}</span>
                                            </span>
                                            <span class="shrink-0 font-mono text-xs text-ink-muted">
                                                @if ($r->spec('vram_gb')) {{ $r->spec('vram_gb') }} GB @endif
                                                @if ($r->spec('cores')) {{ $r->spec('cores') }}C @endif
                                            </span>
                                        </button>
                                    @empty
                                        <p class="bg-surface px-3 py-4 text-sm text-ink-muted">
                                            Няма съвпадения. Избери категория по-долу и ще го въведеш ръчно.
                                        </p>
                                    @endforelse
                                </div>
                            @endif
                        </div>

                        <div>
                            <div class="mb-3 flex items-center gap-3">
                                <span class="h-px flex-1 bg-line"></span>
                                <span class="font-mono text-[10.5px] uppercase tracking-[0.18em] text-ink-faint">или избери категория</span>
                                <span class="h-px flex-1 bg-line"></span>
                            </div>

                            <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 xl:grid-cols-5">
                                @foreach ($categories as $c)
                                    <button type="button" wire:click="pickCategory('{{ $c['key'] }}')"
                                            wire:key="cat-{{ $c['key'] }}"
                                            @class([
                                                'card group flex min-h-[96px] flex-col items-center justify-center gap-2 px-2 py-3 text-center',
                                                'transition hover:border-accent hover:bg-surface-alt',
                                                'border-accent bg-accent-soft text-accent' => $category === $c['key'],
                                            ])>
                                        @include('partials.category-icon', [
                                            'category' => $c['key'],
                                            'class'    => 'h-8 w-8 text-ink-muted transition group-hover:text-accent',
                                        ])
                                        <span class="text-[12.5px] font-semibold leading-tight">{{ $c['label'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                            @error('category') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                {{-- ================================================ step 2 --}}
                @elseif ($step === 2)
                    <div class="mb-4 flex items-center gap-2 text-sm">
                        <span class="badge-neutral">{{ $labels[$category] ?? $category }}</span>
                        <button type="button" wire:click="back" class="btn-ghost btn-sm">Смени категорията</button>
                    </div>

                    @if ($part)
                        <div class="card-pad border-accent bg-accent-soft">
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
                        <div class="card-pad">
                            <label class="label" for="customPart">Име на модела</label>
                            <input id="customPart" type="text" wire:model.blur="customPart"
                                   placeholder="напр. Gigabyte RTX 4070 Gaming OC" class="mt-1.5">
                            @error('customPart') <p class="error">{{ $message }}</p> @enderror
                            <p class="hint">
                                Ще го добавим в каталога ръчно. Обявата ти ще излиза при търсене,
                                но не и по филтри за характеристики.
                            </p>
                            <button type="button" wire:click="$set('partNotListed', false)"
                                    class="btn-ghost btn-sm mt-3 px-0!">← Търси в каталога</button>
                        </div>
                    @else
                        <div class="card-pad">
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

                {{-- ================================================ step 3 --}}
                @elseif ($step === 3)
                    <div class="space-y-5">
                        <div class="card-pad space-y-5">
                            <div>
                                <label class="label" for="title">Заглавие</label>
                                <input id="title" type="text" wire:model.blur="title" maxlength="120" class="mt-1.5">
                                <p class="hint">
                                    Моделът и състоянието, кратко. Поне 8 знака.
                                    <span class="font-mono tabular">{{ mb_strlen($title) }}/120</span>
                                </p>
                                @error('title') <p class="error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="label" for="condition">Състояние</label>
                                <select id="condition" wire:model.live="condition" class="mt-1.5">
                                    @foreach ($conditions as $c)
                                        <option value="{{ $c->value }}">{{ $c->label() }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="label" for="description">Описание</label>
                                <textarea id="description" wire:model.blur="description" rows="5" class="mt-1.5"
                                          placeholder="Как е ползвана, има ли забележки, какво включва комплектът."></textarea>
                                <p class="hint">
                                    Поне 20 знака. Купувачите питат едно и също — откога е,
                                    има ли кутия, защо я продаваш.
                                    <span class="font-mono tabular">{{ mb_strlen($description) }}</span>
                                </p>
                                @error('description') <p class="error">{{ $message }}</p> @enderror
                            </div>
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
                                               inputmode="numeric" wire:model.blur="mining_months" class="mt-1">
                                    </div>
                                @endif
                                <p class="hint">Честният отговор продава по-бързо, отколкото мълчанието.</p>
                            </div>
                        @endif

                        {{-- The specs the schema marks required, OUTSIDE the „по желание"
                             panel and above it.

                             They used to sit inside it, unvalidated. Validating them where
                             they were would have rejected the listing over a field in a
                             collapsed panel headed „by choice" — a field the seller cannot
                             see and was told was optional. A required field does not live
                             under that heading. --}}
                        @if (count($mustSpecs))
                            <div class="card-pad space-y-5">
                                @foreach ($mustSpecs as $key => $spec)
                                    @include('partials.spec-field', ['key' => $key, 'spec' => $spec, 'required' => true])
                                @endforeach
                            </div>
                        @endif

                        {{-- Everything below is optional and folded away by default.

                             Left open, this is a wall of a dozen fields that a first-time
                             seller reads as required - and the ones who bail here are the
                             supply the marketplace does not get. The specs still matter
                             (they are what makes a listing filterable), so the toggle says
                             what they buy rather than just "advanced". --}}
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

                                    @include('partials.identifier-field')

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
                    </div>

                {{-- ================================================ step 4 --}}
                @else
                    <div class="space-y-5">
                        <div class="card-pad">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="label" for="price">Цена (€)</label>
                                    {{-- inputmode="decimal", NOT type="number". A Bulgarian keypad
                                         offers a comma, Chrome and Safari call a comma invalid in a
                                         number input, and an invalid number input reports its value
                                         as the EMPTY STRING — so „450,00" vanished with no error and
                                         nothing on screen to explain it. Same keypad, without the
                                         browser editing the value behind our backs; the component
                                         normalises the comma on blur. --}}
                                    <input id="price" type="text" inputmode="decimal" autocomplete="off"
                                           wire:model.blur="price" class="mt-1 font-mono text-2xl font-semibold">
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

                            {{-- Under the price box, not beside it: the seller has just typed
                                 a number and this is the check on it. Renders nothing when the
                                 model has no band, which is most of them on a young site. --}}
                            <div class="mt-3">
                                @include('partials.price-guidance', ['guidance' => $guidance])
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
                                    <input id="min_offer" type="text" inputmode="decimal" autocomplete="off"
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

                        <div class="card-pad">
                            <span class="label">Доставка</span>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach (\App\Enums\Courier::options() as $key => $label)
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-2.5 text-sm
                                                  transition hover:bg-surface-alt has-[:checked]:border-accent has-[:checked]:bg-accent-soft">
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
            </div>

            {{-- ------------------------------ the preview, on a phone

                 Below the form because a phone has no room beside it, and folded
                 so that it is there for the seller who wants it and not in the
                 way of the one who does not. On a wide screen it is the side
                 column instead. --}}
            <details class="card order-7 lg:hidden">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-medium">
                    <span>Преглед на обявата</span>
                    <span class="font-mono text-xs text-ink-muted">{{ $progress['percent'] }}%</span>
                </summary>
                <div class="border-t border-line p-4">
                    @include('partials.listing-aside', ['preview' => $preview, 'progress' => $progress])
                </div>
            </details>

            {{-- ---------------------------------------------------- nav --}}
            <div class="order-8 flex items-center gap-3 border-t border-line pt-5">
                @if ($step > 1)
                    <button type="button" wire:click="back" class="btn-secondary">Назад</button>
                @endif

                <div class="flex-1"></div>

                @if ($step < 4)
                    <button type="button" wire:click="next" class="btn-primary">Продължи</button>
                @else
                    {{--
                        No Turnstile widget here. Publishing is authenticated, so the
                        challenge at registration has already been cleared, and a widget
                        in this branch could never work anyway: it is not rendered on
                        first page load, so its script never reaches the layout's stack.
                        See CreateListing::publish().
                    --}}
                    <button type="button" wire:click="publish" class="btn-primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="publish">Публикувай обявата</span>
                        <span wire:loading wire:target="publish">Публикуваме…</span>
                    </button>
                @endif
            </div>
        </div>

        {{-- ================================================= the side column --}}
        <aside class="hidden lg:block">
            <div class="sticky top-24">
                @include('partials.listing-aside', ['preview' => $preview, 'progress' => $progress])
            </div>
        </aside>
    </div>
</div>
