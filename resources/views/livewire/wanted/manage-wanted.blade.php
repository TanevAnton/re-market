<div class="mx-auto max-w-2xl">

    <h1 class="text-2xl font-bold tracking-tight">Какво търсиш?</h1>
    <p class="mt-1 text-sm text-ink-muted">
        Продавачите с подходяща техника получават известие и могат да ти
        предложат обявата си. Безплатно е.
    </p>

    <form wire:submit="save" class="card-pad mt-6 space-y-4">

        <div>
            <label class="label" for="category">Категория</label>
            <select id="category" wire:model.live="category" class="mt-1">
                <option value="" disabled>Избери…</option>
                @foreach ($categories as $c)
                    <option value="{{ $c['key'] }}">{{ $c['label'] }}</option>
                @endforeach
            </select>
            @error('category') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="title">Какво точно</label>
            <input id="title" type="text" wire:model.live.debounce.400ms="title" class="mt-1"
                   placeholder="ртх 4070, 32 GB DDR5, 1TB NVMe…">

            {{-- Suggestions, never a requirement. Picking a model makes the
                 reverse match exact — only that model's listings will notify
                 the buyer — but „търся видеокарта до 300 €" is a real request
                 from somebody who does not know which card they want, and that
                 is a buyer a seller can actually help. --}}
            @if ($suggestions)
                <div class="mt-2 divide-y divide-line rounded-md border border-line">
                    @foreach ($suggestions as $s)
                        <button type="button" wire:click="pickPart({{ $s['id'] }}, '{{ addslashes($s['name']) }}')"
                                class="block w-full px-3 py-2 text-left text-sm hover:bg-surface-alt">
                            {{ $s['name'] }}
                        </button>
                    @endforeach
                </div>
                <p class="hint mt-1">Избери модел от каталога за по-точни предложения — или просто продължи.</p>
            @endif

            @if ($partId)
                <p class="hint mt-1 text-accent">Модел от каталога — ще получаваш известия само за него.</p>
            @endif

            @error('title') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="detail">Подробности <span class="text-ink-faint">(по желание)</span></label>
            <textarea id="detail" wire:model="detail" rows="4" class="mt-1"
                      placeholder="Състояние, което те устройва, за какво ти е, срок."></textarea>
            @error('detail') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="budget">Бюджет до <span class="text-ink-faint">(€)</span></label>
                <input id="budget" type="number" step="0.01" min="1" wire:model="budget"
                       class="mt-1 font-mono" placeholder="300">
                <p class="hint">Таван, не цена. Помага на продавачите да преценят.</p>
                @error('budget') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="city">Град</label>
                <select id="city" wire:model="city" class="mt-1">
                    <option value="">Цялата страна</option>
                    @foreach ($cities as $c)
                        <option value="{{ $c->slug }}">{{ $c->name() }}</option>
                    @endforeach
                </select>
                <p class="hint">Празно = приемаш доставка отвсякъде.</p>
            </div>
        </div>

        <x-turnstile />
        @error('turnstile') <p class="error">{{ $message }}</p> @enderror

        <div class="border-t border-line pt-4">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">
                Публикувай търсене
            </button>
        </div>
    </form>

    {{-- ── Existing requests ────────────────────────────────────────────── --}}
    @if ($mine->isNotEmpty())
        <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-muted">Моите търсения</h2>

        <div class="card mt-3 divide-y divide-line overflow-hidden">
            @foreach ($mine as $ad)
                <a href="{{ route('wanted.show', $ad) }}" wire:navigate
                   class="flex flex-wrap items-center justify-between gap-3 p-4 hover:bg-surface-alt"
                   wire:key="mine-{{ $ad->id }}">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                            {{ $ad->title }}

                            @if ($ad->response_count > 0)
                                <span class="badge-accent">{{ $ad->response_count }} предложени</span>
                            @endif
                        </p>
                        <p class="hint mt-0.5">
                            {{ $ad->formattedBudget() ?? 'без бюджет' }}
                            · {{ $ad->city?->name() ?? 'цялата страна' }}
                            · {{ $ad->created_at->format('d.m.Y') }}
                        </p>
                    </div>

                    <span @class([
                        'shrink-0',
                        'badge-good'    => $ad->status === \App\Enums\WantedStatus::Active,
                        'badge-warn'    => $ad->status === \App\Enums\WantedStatus::PendingReview,
                        'badge-neutral' => ! in_array($ad->status, [
                            \App\Enums\WantedStatus::Active,
                            \App\Enums\WantedStatus::PendingReview,
                        ], true),
                    ])>{{ $ad->status->label() }}</span>
                </a>
            @endforeach
        </div>
    @endif
</div>
