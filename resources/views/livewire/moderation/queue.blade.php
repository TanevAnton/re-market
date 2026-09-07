<div class="mx-auto max-w-4xl">

    <div class="flex items-baseline justify-between">
        <h1 class="text-2xl font-semibold tracking-tight">Модерация</h1>
        <p class="font-mono text-sm text-ink-muted">{{ $this->pendingCount() }} чакащи</p>
    </div>

    @error('queue')
        <p class="error mt-3">{{ $message }}</p>
    @enderror

    @if ($items->isEmpty())
        <div class="card-pad mt-6 text-center">
            <p class="text-sm text-ink-muted">Опашката е празна.</p>
        </div>
    @endif

    <div class="mt-6 space-y-4">
        @foreach ($items as $item)
            @php
                $subject = $item->subject;
                $listing = $subject instanceof \App\Models\Listing ? $subject : null;
                $person  = $subject instanceof \App\Models\User ? $subject : null;
            @endphp

            <div class="card-pad" wire:key="item-{{ $item->id }}">

                @if (! $subject)
                    {{-- The listing was hard-deleted under us. Nothing to judge,
                         but the row must not become an invisible blocker. --}}
                    <p class="text-sm text-ink-muted">
                        Съдържанието е изтрито. Няма какво да се прегледа.
                    </p>
                    <button type="button" wire:click="approve({{ $item->id }})"
                            class="btn-ghost btn-sm mt-3">Затвори</button>
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge-accent font-mono">{{ $item->trigger->label() }}</span>
                        <span class="text-xs text-ink-faint">
                            подадена {{ $item->created_at->diffForHumans() }}
                        </span>
                    </div>

                    {{-- The notices themselves. Three independent "this is my
                         photo" reports mean something one does not, so they are
                         all shown rather than collapsed to a count. --}}
                    @foreach ($item->context['reports'] ?? [] as $report)
                        <div class="mt-3 rounded-md border border-line bg-warn-soft p-3">
                            <p class="text-xs font-medium text-warn">
                                {{ $report['label'] ?? $report['reason'] }} — {{ $report['by'] ?? 'анонимен' }}
                            </p>
                            <p class="mt-1 text-xs leading-relaxed text-warn">{{ $report['detail'] ?? '' }}</p>
                        </div>
                    @endforeach

                    {{-- A duplicate photo names the listing it collided with:
                         the judgement is which of the two is the original. --}}
                    @if ($match = ($item->context['matched_listing_uuid'] ?? null))
                        <p class="mt-3 text-xs text-warn">
                            Снимка съвпада с
                            <a href="/obiava/{{ $match }}" target="_blank" rel="noopener" class="link">друга обява</a>
                            (разстояние {{ $item->context['distance'] ?? '?' }}).
                        </p>
                    @endif

                    @if (isset($item->context['median_cents']))
                        <p class="mt-3 text-xs text-warn">
                            Цената е {{ $item->context['percent_of_median'] ?? '?' }}% от медианата за този модел
                            ({{ number_format($item->context['median_cents'] / 100, 2, ',', ' ') }} лв,
                            {{ $item->context['sample_size'] ?? '?' }} обяви).
                        </p>
                    @endif

                    @if ($person)
                        <div class="mt-3">
                            <a href="{{ route('profile', $person->username) }}" target="_blank"
                               rel="noopener" class="link font-medium">{{ $person->username }}</a>
                            <p class="mt-1 text-xs text-ink-muted">
                                профил от {{ $person->created_at->diffForHumans() }} ·
                                {{ $person->deals_completed }} сделки ·
                                {{ $person->listings()->count() }} обяви
                            </p>
                            <p class="mt-2 text-xs text-ink-faint">
                                Отхвърлянето ограничава профила. Обявите му остават — премахни ги поотделно.
                            </p>
                        </div>
                    @endif

                    @if ($listing)
                    <div class="mt-3 flex gap-4">
                        {{-- Photos are most of the judgement: stock images, a
                             missing handwritten note, a card that does not match
                             the title. Big enough to actually see. --}}
                        <div class="flex shrink-0 gap-2">
                            @forelse ($listing->images->take(3) as $image)
                                <a href="{{ $image->url() }}" target="_blank" rel="noopener">
                                    <img src="{{ $image->url() }}" alt=""
                                         class="h-28 w-28 rounded border border-line object-cover">
                                </a>
                            @empty
                                <div class="grid h-28 w-28 place-items-center rounded border border-line
                                            bg-surface-alt text-xs text-ink-faint">
                                    без снимки
                                </div>
                            @endforelse
                        </div>

                        <div class="min-w-0 flex-1">
                            <a href="{{ route('listing', $listing) }}" target="_blank" rel="noopener"
                               class="link font-medium">{{ $listing->title }}</a>

                            <p class="mt-1 font-mono text-sm">{{ $listing->formattedPrice() }}</p>

                            <p class="mt-1 text-xs text-ink-muted">
                                {{ $listing->city?->name() ?? 'без град' }} ·
                                {{ $listing->condition->label() }} ·
                                {{ $listing->images->count() }} снимки
                                @if ($listing->images->contains('is_timestamp_photo', true))
                                    · <span class="text-good">има снимка с бележка</span>
                                @else
                                    · <span class="text-warn">няма снимка с бележка</span>
                                @endif
                            </p>

                            <p class="mt-2 line-clamp-3 text-sm text-ink-muted">
                                {{ $listing->description }}
                            </p>
                        </div>
                    </div>

                    {{-- Who is asking. A first listing from a phone-verified
                         account with a real history is a different risk from a
                         first listing from an account made this morning. --}}
                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-line pt-3
                                text-xs text-ink-muted">
                        <a href="{{ route('profile', $listing->user->username) }}" target="_blank"
                           rel="noopener" class="link">{{ $listing->user->username }}</a>
                        <span>профил от {{ $listing->user->created_at->diffForHumans() }}</span>
                        <span>{{ $listing->user->deals_completed }} сделки</span>
                        <span @class(['text-good' => $listing->user->phone_verified_at,
                                      'text-warn' => ! $listing->user->phone_verified_at])>
                            {{ $listing->user->phone_verified_at ? 'телефон потвърден' : 'телефон непотвърден' }}
                        </span>
                        <span>{{ $listing->user->seller_type->label() }}</span>
                    </div>
                    @endif

                    @if ($rejecting === $item->id)
                        <div class="mt-4 border-t border-line pt-4">
                            <label class="label" for="reason-{{ $item->id }}">Причина</label>
                            <select id="reason-{{ $item->id }}" wire:model="reason" class="mt-1 w-full">
                                <option value="">— избери —</option>
                                @foreach ($reasons as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('reason') <p class="error mt-1">{{ $message }}</p> @enderror

                            <label class="label mt-3" for="facts-{{ $item->id }}">
                                Какво точно е нередно
                            </label>
                            <textarea id="facts-{{ $item->id }}" wire:model="facts" rows="3"
                                      class="mt-1 w-full"
                                      placeholder="Например: снимките са рекламни от сайта на производителя."></textarea>
                            <p class="hint">
                                Това го чете продавачът. Категорията казва кое правило е нарушено —
                                тук се описва какво се е случило в тази обява.
                            </p>
                            @error('facts') <p class="error mt-1">{{ $message }}</p> @enderror

                            <div class="mt-3 flex gap-2">
                                <button type="button" wire:click="reject({{ $item->id }})"
                                        class="btn-primary btn-sm" wire:loading.attr="disabled">
                                    Отхвърли
                                </button>
                                <button type="button" wire:click="cancelReject" class="btn-ghost btn-sm">
                                    Откажи
                                </button>
                            </div>
                        </div>
                    @else
                        <div class="mt-4 flex gap-2 border-t border-line pt-4">
                            <button type="button" wire:click="approve({{ $item->id }})"
                                    class="btn-primary btn-sm" wire:loading.attr="disabled">
                                Одобри
                            </button>
                            <button type="button" wire:click="startReject({{ $item->id }})"
                                    class="btn-ghost btn-sm">
                                Отхвърли
                            </button>
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-6">{{ $items->links() }}</div>
</div>
