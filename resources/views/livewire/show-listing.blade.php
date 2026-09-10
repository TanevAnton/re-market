<div>

@if ($this->isPrivateView())
    @php $statement = $this->statementOfReasons(); @endphp

    @if ($statement)
        {{-- The decision, in full, to the person it was made about. A statement
             of reasons filed only in the database is not a statement of
             reasons - DSA Art. 17 is about what the user receives. --}}
        <div class="mb-5 rounded-lg border border-line bg-bad-soft p-4">
            <p class="text-sm font-medium text-bad">Обявата е премахната след преглед</p>
            <p class="mt-2 whitespace-pre-line text-xs leading-relaxed text-bad">{{ $statement }}</p>
        </div>
    @else
        <div class="mb-5 rounded-lg border border-line bg-warn-soft p-4">
            <p class="text-sm font-medium text-warn">
                @if ($listing->status === \App\Enums\ListingStatus::PendingReview)
                    Обявата чака преглед
                @else
                    Обявата не е публична ({{ $listing->status->label() }})
                @endif
            </p>
            <p class="mt-1 text-xs leading-relaxed text-warn">
                @if (auth()->id() === $listing->user_id)
                    Виждаш я, защото е твоя. Първите обяви от нов профил се проверяват ръчно —
                    обикновено до няколко часа. След одобрение излиза в резултатите.
                @else
                    Виждаш я като модератор. Публично не е достъпна.
                @endif
            </p>
        </div>
    @endif
@endif

<div class="grid gap-8 lg:grid-cols-[1fr_330px]">

    {{-- ------------------------------------------------ main --}}
    <div>
        <nav class="mb-3 text-sm text-ink-muted">
            <a href="{{ route('browse') }}" wire:navigate class="link">Обяви</a>
            <span class="mx-1 text-ink-faint">/</span>
            <a href="{{ route('browse', ['kat' => $listing->category]) }}" wire:navigate class="link">
                {{ config("catalog.categories.{$listing->category}.label.bg", $listing->category) }}
            </a>
        </nav>

        <h1 class="text-2xl font-semibold tracking-tight">{{ $listing->title }}</h1>

        <div class="mt-2 flex flex-wrap items-center gap-1.5">
            <span class="badge-neutral">{{ $listing->condition->label() }}</span>

            @if ($listing->mining_use === \App\Enums\MiningUse::Yes)
                <span class="badge-warn">Копала {{ $listing->mining_months }} мес.</span>
            @elseif ($listing->mining_use === \App\Enums\MiningUse::No && $listing->category === 'gpu')
                <span class="badge-good">Не е копала</span>
            @endif

            @if ($listing->isWarrantied())
                <span class="badge-accent">Гаранция до {{ $listing->warranty_until->format('m.Y') }}</span>
            @endif

            @if ($listing->has_receipt)
                <span class="badge-neutral">С касова бележка</span>
            @endif

            @if ($listing->quantity > 1)
                <span class="badge-neutral font-mono">{{ $listing->quantity }} бр.</span>
            @endif
        </div>

        {{-- gallery ---------------------------------------------------------

             Alpine rather than Livewire: switching photo is a client-side
             concern, and a server round trip per thumbnail makes a gallery feel
             broken on a phone. Nothing here needs the server.

             wire:ignore so a Livewire re-render (the admin takedown form, for
             one) cannot reach in and reset which photo is open. --}}
        @php($images = $listing->images)

        <div class="mt-5 space-y-2" wire:ignore
             x-data="{
                 i: 0,
                 open: false,
                 urls: {{ Illuminate\Support\Js::from($images->map->url()) }},
                 next() { this.i = (this.i + 1) % this.urls.length },
                 prev() { this.i = (this.i - 1 + this.urls.length) % this.urls.length },
             }"
             @keydown.window.escape="open = false"
             @keydown.window.arrow-right="if (open) next()"
             @keydown.window.arrow-left="if (open) prev()">

            <div class="card overflow-hidden">
                @if ($images->isNotEmpty())
                    <img :src="urls[i]" alt="{{ $listing->title }}"
                         src="{{ $images->first()->url() }}"
                         @click="open = true"
                         class="aspect-[4/3] w-full cursor-zoom-in object-cover
                                transition hover:brightness-105">
                @else
                    <div class="grid aspect-[4/3] place-items-center bg-surface-alt text-sm text-ink-faint">
                        Няма снимки
                    </div>
                @endif
            </div>

            @if ($images->count() > 1)
                <div class="grid grid-cols-5 gap-2 sm:grid-cols-6">
                    @foreach ($images as $index => $img)
                        <button type="button" @click="i = {{ $index }}"
                                :class="i === {{ $index }} ? 'border-accent' : 'border-line hover:border-ink-faint'"
                                class="relative overflow-hidden rounded-md border transition"
                                aria-label="Снимка {{ $index + 1 }}">
                            <img src="{{ $img->thumbUrl() }}" alt="" loading="lazy"
                                 class="aspect-[4/3] w-full object-cover">
                            @if ($img->is_timestamp_photo)
                                <span class="absolute inset-x-0 bottom-0 bg-accent px-1 py-0.5 text-center
                                             text-[9px] font-medium text-[var(--accent-ink)]">
                                    с бележка
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- lightbox --}}
            @if ($images->isNotEmpty())
                <div x-show="open" x-cloak x-transition.opacity
                     @click.self="open = false"
                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4">

                    <button type="button" @click="open = false" aria-label="Затвори"
                            class="absolute right-4 top-4 rounded-md px-3 py-1.5 text-2xl leading-none
                                   text-white/70 transition hover:bg-white/10 hover:text-white">
                        &times;
                    </button>

                    @if ($images->count() > 1)
                        <button type="button" @click="prev()" aria-label="Предишна"
                                class="absolute left-2 rounded-md px-3 py-6 text-3xl text-white/70
                                       transition hover:bg-white/10 hover:text-white sm:left-6">
                            &lsaquo;
                        </button>
                        <button type="button" @click="next()" aria-label="Следваща"
                                class="absolute right-2 rounded-md px-3 py-6 text-3xl text-white/70
                                       transition hover:bg-white/10 hover:text-white sm:right-6">
                            &rsaquo;
                        </button>
                    @endif

                    <img :src="urls[i]" alt="{{ $listing->title }}"
                         class="max-h-[88vh] max-w-full rounded-md object-contain">

                    @if ($images->count() > 1)
                        <p class="absolute bottom-4 font-mono text-xs text-white/60">
                            <span x-text="i + 1"></span> / {{ $images->count() }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        {{-- the spec sheet: the thing OLX cannot render --}}
        @if ($rows = $this->specRows())
            <section class="card-pad mt-6">
                <h2 class="label">Спецификации</h2>
                <dl class="mt-3 grid gap-x-10 sm:grid-cols-2">
                    @foreach ($rows as $row)
                        <div class="spec-row">
                            <dt class="spec-key">{{ $row['label'] }}</dt>
                            <dd class="spec-value">
                                {{ $row['value'] }}{{ $row['unit'] ? ' '.$row['unit'] : '' }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif

        <section class="card-pad mt-6">
            <h2 class="label">Описание</h2>
            <p class="mt-3 whitespace-pre-line text-sm leading-relaxed">{{ $listing->description }}</p>

            @if ($listing->validation_url)
                <a href="{{ $listing->validation_url }}" target="_blank" rel="noopener nofollow"
                   class="mt-4 inline-flex items-center gap-1.5 text-sm text-accent hover:underline">
                    Резултат от тест ↗
                </a>
            @endif
        </section>
    </div>

    {{-- ------------------------------------------------ sidebar --}}
    <aside class="space-y-4 lg:sticky lg:top-20 lg:self-start">

        <div class="card-pad">
            <p class="price text-3xl">{{ $listing->formattedPrice() }}</p>

            {{-- Its own component so that placing an offer re-renders the box
                 and not the entire listing page, gallery and spec sheet
                 included. Keyed by listing so Livewire never reuses state
                 across a wire:navigate to a different ad. --}}
            @livewire('offers.make-offer', ['listing' => $listing], key('offer-'.$listing->id))

            @auth
                @if (auth()->id() !== $listing->user_id)
                    <a href="{{ route('listing.message', $listing) }}" wire:navigate
                       class="btn-secondary mt-2 block w-full text-center">
                        Съобщение до продавача
                    </a>
                @endif
            @else
                <a href="{{ route('login') }}" wire:navigate
                   class="btn-secondary mt-2 block w-full text-center">
                    Съобщение до продавача
                </a>
            @endauth
        </div>

        @if ($listing->accepts_inspect_test)
            <div class="rounded-lg border border-line bg-good-soft p-4">
                <p class="text-sm font-medium text-good">Преглед и тест при получаване</p>
                <p class="mt-1 text-xs leading-relaxed text-good">
                    Отваряш и пробваш пратката в офиса на куриера, преди да платиш.
                    Ако нещо не е наред, не я приемаш.
                </p>
            </div>
        @else
            <div class="rounded-lg border border-line bg-warn-soft p-4">
                <p class="text-sm font-medium text-warn">Без преглед и тест</p>
                <p class="mt-1 text-xs leading-relaxed text-warn">
                    Продавачът не приема отваряне преди плащане. Прецени риска.
                </p>
            </div>
        @endif

        <div class="card-pad">
            <h2 class="label">Продавач</h2>

            <a href="{{ route('profile', $listing->user->username) }}" wire:navigate
               class="mt-3 flex items-center gap-3">
                <div class="grid h-10 w-10 place-items-center rounded-full bg-accent font-mono text-sm
                            font-semibold text-[var(--accent-ink)]">
                    {{ mb_strtoupper(mb_substr($listing->user->username, 0, 1)) }}
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium hover:text-accent">{{ $listing->user->username }}</p>
                    <p class="text-xs text-ink-muted">{{ $listing->city?->name() }}</p>
                </div>
            </a>

            <dl class="mt-4 space-y-1.5 text-sm">
                <div class="flex justify-between">
                    <dt class="text-ink-muted">Завършени сделки</dt>
                    <dd class="font-mono font-medium tabular">{{ $listing->user->deals_completed }}</dd>
                </div>
                @if ($rate = $listing->user->completionRate())
                    <div class="flex justify-between">
                        <dt class="text-ink-muted">Довежда до край</dt>
                        <dd class="font-mono font-medium tabular">{{ $rate }}%</dd>
                    </div>
                @endif
                @if ($listing->user->rating_avg)
                    <div class="flex justify-between">
                        <dt class="text-ink-muted">Оценка</dt>
                        <dd class="font-mono font-medium tabular">{{ $listing->user->rating_avg }} / 5</dd>
                    </div>
                @endif
            </dl>

            {{-- ЗЗП / Omnibus Art. 6a. A legal requirement, not a design choice:
                 it must be visible before the buyer commits. --}}
            <p class="mt-4 rounded-md bg-surface-alt p-3 text-xs leading-relaxed text-ink-muted">
                {{ $listing->user->seller_type->consumerNotice() }}
            </p>
        </div>

        @if ($listing->delivery_options)
            <div class="card-pad">
                <h2 class="label">Доставка</h2>
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($listing->delivery_options as $opt)
                        <li class="flex items-center gap-2">
                            <span class="h-1 w-1 rounded-full bg-ink-faint"></span>
                            {{ ['econt' => 'Еконт', 'speedy' => 'Спиди', 'pickup' => 'Лично предаване'][$opt] ?? $opt }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="px-1 text-xs text-ink-faint">
            Публикувана {{ $listing->published_at?->diffForHumans() }} ·
            <span class="font-mono tabular">{{ $listing->view_count }}</span> преглеждания
        </p>

        {{-- Notice and action, DSA Art. 16. Not behind a login: the person most
             likely to recognise a stolen photograph is the seller it was taken
             from, who has no account here. --}}
        @if (auth()->id() !== $listing->user_id)
            @livewire('reports.report-form', ['subject' => $listing], key('report-listing-'.$listing->id))
        @endif

        {{-- Moderator takedown ------------------------------------------------

             Here rather than only in the queue, because the queue shows what
             was flagged. A moderator who runs into something bad while just
             browsing should not have to wait for a report before they can act.

             It is the same door as the queue - ModerationService - so it leaves
             the same record and sends the seller the same statement of reasons.
             Deliberately not a one-click delete: a listing that vanishes with
             no reason attached is one nobody can explain later. --}}
        @if ($this->canModerate())
            <div class="card-pad border-bad/30">
                <h2 class="label text-bad">Модерация</h2>

                @unless ($removing)
                    <p class="hint mt-2">
                        Премахването е решение по DSA чл. 17 — продавачът получава
                        причината и фактите, и може да го оспори.
                    </p>
                    <button type="button" wire:click="startRemove"
                            class="btn-secondary mt-3 w-full border-bad/40 text-bad">
                        Премахни обявата
                    </button>
                @else
                    <div class="mt-3 space-y-3">
                        <div>
                            <label class="label" for="mod-reason">Причина</label>
                            <select id="mod-reason" wire:model="reason" class="mt-1 w-full">
                                <option value="">— избери —</option>
                                @foreach ($this->rejectionReasons() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('reason') <p class="error mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label" for="mod-facts">Установени факти</label>
                            <textarea id="mod-facts" rows="4" wire:model="facts" class="mt-1 w-full"
                                      placeholder="Какво точно е нередно в тази обява?"></textarea>
                            <p class="hint">
                                Продавачът чете точно този текст. Категорията казва коя
                                е нарушената точка; това казва какво се е случило.
                            </p>
                            @error('facts') <p class="error mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div class="flex gap-2">
                            <button type="button" wire:click="remove"
                                    class="btn-primary flex-1">Премахни</button>
                            <button type="button" wire:click="cancelRemove"
                                    class="btn-ghost">Откажи</button>
                        </div>
                    </div>
                @endunless
            </div>
        @endif
    </aside>
</div>
</div>
