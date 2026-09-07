<div>

@if ($this->isPrivateView())
    <div class="mb-5 rounded-lg border border-line bg-warn-soft p-4">
        <p class="text-sm font-medium text-warn">
            @if ($listing->status === \App\Enums\ListingStatus::PendingReview)
                Обявата чака преглед
            @else
                Обявата не е публична ({{ $listing->status->label() }})
            @endif
        </p>
        <p class="mt-1 text-xs leading-relaxed text-warn">
            Виждаш я, защото е твоя. Първите обяви от нов профил се проверяват ръчно —
            обикновено до няколко часа. След одобрение излиза в резултатите.
        </p>
    </div>
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

        {{-- gallery --}}
        <div class="mt-5 space-y-2">
            <div class="card overflow-hidden">
                @if ($listing->images->isNotEmpty())
                    <img src="{{ $listing->images->first()->url() }}" alt="{{ $listing->title }}"
                         class="aspect-[4/3] w-full object-cover">
                @else
                    <div class="grid aspect-[4/3] place-items-center bg-surface-alt text-sm text-ink-faint">
                        Няма снимки
                    </div>
                @endif
            </div>

            @if ($listing->images->count() > 1)
                <div class="grid grid-cols-5 gap-2 sm:grid-cols-6">
                    @foreach ($listing->images as $img)
                        <div class="relative overflow-hidden rounded-md border border-line">
                            <img src="{{ $img->thumbUrl() }}" alt="" loading="lazy"
                                 class="aspect-[4/3] w-full object-cover">
                            @if ($img->is_timestamp_photo)
                                <span class="absolute inset-x-0 bottom-0 bg-accent px-1 py-0.5 text-center
                                             text-[9px] font-medium text-[var(--accent-ink)]">
                                    с бележка
                                </span>
                            @endif
                        </div>
                    @endforeach
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
    </aside>
</div>
</div>
