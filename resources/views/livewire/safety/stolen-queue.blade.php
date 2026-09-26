<div class="mx-auto max-w-4xl">

    <h1 class="text-2xl font-bold tracking-tight">Сигнали за отнети вещи</h1>
    <p class="hint mt-1">
        Потвърждаването не е решение, че вещта е открадната. То сваля
        съвпадащите обяви за проверка — решението за всяка обява се взема
        отделно, с мотиви към продавача.
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

    @if ($reports->isEmpty())
        <div class="card-pad mt-6 text-center">
            <p class="text-sm text-ink-muted">Няма сигнали в този раздел.</p>
        </div>
    @endif

    <div class="mt-6 space-y-3">
        @foreach ($reports as $report)
            <div class="card-pad" wire:key="report-{{ $report->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            <span>{{ $report->kind === 'imei' ? 'IMEI' : 'Сериен номер' }} {{ $report->masked() }}</span>
                            <span class="badge-neutral">{{ $report->statusLabel() }}</span>
                        </p>

                        <p class="hint mt-1">
                            сигнал в полицията: <span class="font-mono">{{ $report->police_ref }}</span>
                            · {{ $report->reporter?->username ?? 'без профил' }}
                            · {{ $report->reporter_email }}
                            · {{ $report->created_at->diffForHumans() }}
                        </p>

                        <p class="mt-2 whitespace-pre-line break-words text-sm text-ink-muted">{{ $report->detail }}</p>

                        @if ($report->decided_at)
                            <p class="hint mt-2 border-t border-line pt-2">
                                {{ $report->decider?->username }} · {{ $report->decided_at->format('d.m.Y') }}
                                @if ($report->decision_note) · {{ $report->decision_note }} @endif
                            </p>
                        @endif
                    </div>

                    @if ($report->status === \App\Models\StolenReport::PENDING)
                        <button type="button" wire:click="open({{ $report->id }})"
                                class="btn-secondary btn-sm shrink-0">Реши</button>
                    @endif
                </div>

                @if ($deciding === $report->id)
                    <div class="mt-4 border-t border-line pt-4">
                        <label class="label" for="note-{{ $report->id }}">
                            Бележка — остава в записа
                        </label>
                        <input id="note-{{ $report->id }}" type="text" wire:model="note" class="mt-1"
                               placeholder="Какво провери и какво реши.">

                        <p class="hint mt-2">
                            При потвърждаване: всяка активна обява с този номер се
                            сваля и влиза в модерацията. При отхвърляне бележката е
                            задължителна.
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="confirm({{ $report->id }})"
                                    class="btn-primary btn-sm" wire:loading.attr="disabled">
                                Потвърди сигнала
                            </button>
                            <button type="button" wire:click="reject({{ $report->id }})"
                                    class="btn-ghost btn-sm">Отхвърли</button>
                            <button type="button" wire:click="cancel" class="btn-ghost btn-sm">Назад</button>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-6">{{ $reports->links() }}</div>
</div>
