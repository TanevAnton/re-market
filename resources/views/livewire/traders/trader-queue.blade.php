<div class="mx-auto max-w-4xl">

    <h1 class="text-2xl font-bold tracking-tight">Проверка на фирми</h1>
    <p class="hint mt-1">
        Отваряш Търговския регистър, търсиш по ЕИК и сравняваш три неща:
        съществува ли дружеството, това ли е регистрираното наименование и това ли
        е седалището. Отказът не отнема нищо — продавачът си остава търговец с
        всички задължения по ЗЗП. На карта е само етикетът.
    </p>

    @error('note') <p class="error mt-3">{{ $message }}</p> @enderror

    <div class="mt-4 flex flex-wrap gap-1 border-b border-line">
        @foreach ($this->tabs() as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition',
                        'border-accent text-ink' => $tab === $key,
                        'border-transparent text-ink-muted hover:text-ink' => $tab !== $key,
                    ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($traders->isEmpty())
        <div class="card-pad mt-6 text-center">
            <p class="text-sm text-ink-muted">Няма заявки в този раздел.</p>
        </div>
    @endif

    <div class="mt-6 space-y-3">
        @foreach ($traders as $trader)
            @php($details = $trader->trader_details ?? [])

            <div class="card-pad" wire:key="trader-{{ $trader->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            <a href="{{ route('profile', $trader->username) }}" wire:navigate
                               class="hover:text-accent">{{ $trader->username }}</a>
                            <span class="badge-neutral">{{ $trader->traderStatusLabel() }}</span>
                        </p>

                        {{-- The three fields being compared, ЕИК first and
                             select-all so one gesture copies it into the
                             register's search box. --}}
                        <dl class="mt-2 space-y-1 text-sm">
                            <div class="flex gap-2">
                                <dt class="w-24 shrink-0 text-ink-muted">ЕИК</dt>
                                <dd class="select-all break-all font-mono font-medium">{{ $details['uic'] ?? '—' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-24 shrink-0 text-ink-muted">Фирма</dt>
                                <dd class="min-w-0 break-words font-medium">{{ $details['company'] ?? '—' }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-24 shrink-0 text-ink-muted">Адрес</dt>
                                <dd class="min-w-0 break-words">{{ $details['address'] ?? '—' }}</dd>
                            </div>
                            @if (! empty($details['vat']))
                                <div class="flex gap-2">
                                    <dt class="w-24 shrink-0 text-ink-muted">ДДС №</dt>
                                    <dd class="select-all font-mono">{{ $details['vat'] }}</dd>
                                </div>
                            @endif
                        </dl>

                        <p class="hint mt-2">
                            {{ $trader->city?->name() ?? 'без град' }}
                            · {{ $trader->email }}
                            · в сайта от {{ $trader->created_at->translatedFormat('F Y') }}
                            · {{ $trader->listings_count }} обяви
                        </p>

                        @if ($trader->trader_verified_by)
                            <p class="hint mt-2 border-t border-line pt-2">
                                {{ $trader->traderVerifier?->username ?? 'изтрит профил' }}
                                @if ($trader->trader_verified_at)
                                    · {{ $trader->trader_verified_at->format('d.m.Y') }}
                                @endif
                                @if ($trader->trader_note) · {{ $trader->trader_note }} @endif
                            </p>
                        @endif
                    </div>

                    <a href="https://portal.registryagency.bg/" target="_blank" rel="noopener noreferrer"
                       class="btn-ghost btn-sm shrink-0">Търговски регистър</a>
                </div>

                @if ($deciding === $trader->id)
                    <div class="mt-4 border-t border-line pt-4">
                        <label class="label" for="note-{{ $trader->id }}">
                            @if ($trader->trader_status === \App\Models\User::TRADER_VERIFIED)
                                Защо се отнема — задължително
                            @else
                                Бележка — при отказ продавачът я вижда
                            @endif
                        </label>
                        <input id="note-{{ $trader->id }}" type="text" wire:model="note" class="mt-1"
                               placeholder="Какво провери, или какво не съвпада.">

                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($trader->trader_status === \App\Models\User::TRADER_VERIFIED)
                                <button type="button" wire:click="revoke({{ $trader->id }})"
                                        class="btn-secondary btn-sm" wire:loading.attr="disabled">
                                    Отнеми етикета
                                </button>
                            @else
                                <button type="button" wire:click="verify({{ $trader->id }})"
                                        class="btn-primary btn-sm" wire:loading.attr="disabled">
                                    Съвпада — потвърди
                                </button>
                                <button type="button" wire:click="reject({{ $trader->id }})"
                                        class="btn-ghost btn-sm">Не съвпада</button>
                            @endif
                            <button type="button" wire:click="cancel" class="btn-ghost btn-sm">Назад</button>
                        </div>
                    </div>
                @else
                    @if (in_array($trader->trader_status, [
                        \App\Models\User::TRADER_PENDING,
                        \App\Models\User::TRADER_VERIFIED,
                    ], true))
                        <div class="mt-3 border-t border-line pt-3">
                            <button type="button" wire:click="open({{ $trader->id }})"
                                    class="btn-secondary btn-sm">
                                {{ $trader->trader_status === \App\Models\User::TRADER_VERIFIED ? 'Отнеми' : 'Реши' }}
                            </button>
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-6">{{ $traders->links() }}</div>
</div>
