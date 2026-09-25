<div class="mx-auto max-w-4xl">

    <h1 class="text-2xl font-bold tracking-tight">Плащания</h1>
    <p class="hint mt-1">
        Потвърждаването издава фактура и зарежда кредит. И двете са необратими.
    </p>

    @error('payment') <p class="error mt-3">{{ $message }}</p> @enderror

    {{-- Named here, before anything can be clicked. An admin who confirms a
         payment on a half-configured deployment would otherwise be told no at
         the moment it matters — after they have already checked the bank. --}}
    @if ($blanks)
        <div class="card-pad mt-4 border-bad/40">
            <p class="text-sm font-semibold text-bad">Фактури не могат да се издават</p>
            <p class="mt-1 text-sm text-ink-muted">
                Липсват данни в <code class="font-mono">.env</code>:
            </p>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($blanks as $key => $what)
                    <li><code class="font-mono text-xs">{{ $key }}</code> — {{ $what }}</li>
                @endforeach
            </ul>
            <p class="hint mt-3">След редакция: <code class="font-mono">php artisan config:cache</code></p>
        </div>
    @endif

    {{-- ── Waiting ──────────────────────────────────────────────────────── --}}
    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-muted">
        Чакащи ({{ $pending->count() }})
    </h2>

    @if ($pending->isEmpty())
        <div class="card-pad mt-3 text-center">
            <p class="text-sm text-ink-muted">Няма чакащи плащания.</p>
        </div>
    @else
        <div class="mt-3 space-y-3">
            @foreach ($pending as $payment)
                <div class="card-pad" wire:key="pending-{{ $payment->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                <span class="font-mono">{{ $payment->reference }}</span>
                                <span class="price">{{ $payment->formattedAmount() }}</span>
                            </p>

                            <p class="hint mt-1">
                                {{ $payment->user->username }} ·
                                заявено {{ $payment->created_at->diffForHumans() }}
                            </p>

                            {{-- Shown in full, because this is what will be
                                 printed on the document and the moment to
                                 notice a blank ЕИК is before it is issued. --}}
                            <p class="mt-2 text-xs leading-relaxed text-ink-muted">
                                {{ $payment->bill_to_name }}@if ($payment->bill_to_eik), ЕИК {{ $payment->bill_to_eik }}@endif<br>
                                {{ $payment->bill_to_address }}, {{ $payment->bill_to_city }}
                                @if ($payment->bill_to_person)<br>МОЛ: {{ $payment->bill_to_person }}@endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap gap-2">
                            <button type="button" wire:click="confirm({{ $payment->id }})"
                                    class="btn-primary btn-sm" wire:loading.attr="disabled"
                                    @disabled($blanks)>
                                Постъпи
                            </button>
                            <button type="button" wire:click="startCancel({{ $payment->id }})"
                                    class="btn-ghost btn-sm">
                                Откажи
                            </button>
                        </div>
                    </div>

                    @if ($cancelling === $payment->id)
                        <div class="mt-4 rounded-lg border border-line bg-surface-alt p-3">
                            <label class="label" for="reason-{{ $payment->id }}">
                                Причина — продавачът я вижда
                            </label>
                            <input id="reason-{{ $payment->id }}" type="text" wire:model="cancelReason"
                                   placeholder="Преводът не постъпи." class="mt-1">

                            <div class="mt-3 flex gap-2">
                                <button type="button" wire:click="cancel({{ $payment->id }})"
                                        class="btn-primary btn-sm">Откажи плащането</button>
                                <button type="button" wire:click="cancelCancel"
                                        class="btn-ghost btn-sm">Назад</button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- ── History ──────────────────────────────────────────────────────── --}}
    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-muted">Приключени</h2>

    @if ($settled->isEmpty())
        <div class="card-pad mt-3 text-center">
            <p class="text-sm text-ink-muted">Още няма приключени плащания.</p>
        </div>
    @else
        <div class="card mt-3 divide-y divide-line overflow-hidden">
            @foreach ($settled as $payment)
                <div class="flex flex-wrap items-center justify-between gap-3 p-4"
                     wire:key="settled-{{ $payment->id }}">
                    <div class="min-w-0">
                        <p class="text-sm">
                            <span class="font-mono">{{ $payment->reference }}</span>
                            · {{ $payment->status->label() }}
                            · <span class="price">{{ $payment->formattedAmount() }}</span>
                        </p>
                        <p class="hint mt-0.5">
                            {{ $payment->user->username }}
                            @if ($payment->confirmer) · потвърдил {{ $payment->confirmer->username }} @endif
                            @if ($payment->cancelled_reason) · {{ $payment->cancelled_reason }} @endif
                        </p>
                    </div>

                    @if ($payment->invoice)
                        <a href="{{ route('invoice', $payment->invoice) }}"
                           class="btn-ghost btn-sm shrink-0">
                            № {{ $payment->invoice->number }}
                        </a>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-6">{{ $settled->links() }}</div>
    @endif
</div>
