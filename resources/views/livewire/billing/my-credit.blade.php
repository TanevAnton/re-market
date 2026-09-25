<div class="mx-auto max-w-3xl">

    <h1 class="text-2xl font-bold tracking-tight">Моят кредит</h1>

    <div class="card-pad mt-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="hint">Налично</p>
                <p class="price mt-1 text-3xl leading-none">
                    {{ number_format($balance / 100, 2, ',', ' ') }} €
                </p>
            </div>

            @if ($canPay && ! $toppingUp)
                <button type="button" wire:click="startTopUp" class="btn-primary">Зареди кредит</button>
            @endif
        </div>

        {{-- Said plainly rather than shown as a button that refuses. A site
             that cannot issue an invoice must not take money for a service,
             and the honest version of that is not offering to. --}}
        @unless ($canPay)
            <p class="hint mt-3 max-w-prose border-t border-line pt-3">
                Зареждането на кредит не е активно в момента. Пиши ни и ще
                заредим кредит ръчно.
            </p>
        @endunless
    </div>

    {{-- ── The top-up form ──────────────────────────────────────────────── --}}
    @if ($toppingUp)
        <div class="card-pad mt-4">
            <h2 class="text-sm font-semibold">Зареждане с банков превод</h2>

            <form wire:submit="requestTopUp" class="mt-4 space-y-5">

                <div>
                    <span class="label">Сума</span>

                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($options as $option)
                            <button type="button" wire:click="$set('amount', {{ $option }})"
                                    @class([
                                        'rounded-md border px-3 py-1.5 text-sm font-medium transition',
                                        'border-accent text-accent' => $amount === $option,
                                        'border-line text-ink-muted hover:text-ink' => $amount !== $option,
                                    ])>
                                {{ number_format($option / 100, 0, ',', ' ') }} €
                            </button>
                        @endforeach
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                        <label class="hint" for="amount">или друга сума, в евроцентове</label>
                        <input id="amount" type="number" wire:model="amount" class="w-32 font-mono">
                    </div>

                    @error('amount') <p class="error">{{ $message }}</p> @enderror
                </div>

                {{-- These go on a legal document, which is why they are asked
                     for here — while the buyer is present — rather than being
                     chased by email after a transfer has already arrived. --}}
                <div class="border-t border-line pt-4">
                    <p class="text-sm font-semibold">Данни за фактура</p>
                    <p class="hint mt-0.5">
                        Ако си физическо лице, остави ЕИК и МОЛ празни.
                    </p>

                    <div class="mt-3 space-y-3">
                        <div>
                            <label class="label" for="billName">Наименование / име</label>
                            <input id="billName" type="text" wire:model="billName" class="mt-1">
                            @error('billName') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="label" for="billEik">ЕИК / Булстат</label>
                                <input id="billEik" type="text" wire:model="billEik" class="mt-1 font-mono">
                                @error('billEik') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="label" for="billVat">ДДС номер</label>
                                <input id="billVat" type="text" wire:model="billVat" class="mt-1 font-mono">
                                @error('billVat') <p class="error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div>
                            <label class="label" for="billAddress">Адрес</label>
                            <input id="billAddress" type="text" wire:model="billAddress" class="mt-1">
                            @error('billAddress') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="label" for="billCity">Град</label>
                                <input id="billCity" type="text" wire:model="billCity" class="mt-1">
                                @error('billCity') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="label" for="billPerson">МОЛ</label>
                                <input id="billPerson" type="text" wire:model="billPerson" class="mt-1">
                                @error('billPerson') <p class="error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 border-t border-line pt-4">
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">
                        Създай заявка
                    </button>
                    <button type="button" wire:click="cancelTopUp" class="btn-ghost">Откажи</button>
                </div>

                <p class="hint">
                    Кредитът се зарежда след като преводът постъпи по сметката —
                    обикновено на следващия работен ден. Фактурата се издава при
                    потвърждаване на плащането.
                </p>
            </form>
        </div>
    @endif

    {{-- ── Waiting on a transfer ────────────────────────────────────────────
         Above everything else on purpose: a seller who has just paid and sees
         nothing assumes the payment failed, and the next thing they do is pay
         again or write in. --}}
    @foreach ($pending as $payment)
        <div class="card-pad mt-4 border-accent/40" wire:key="pending-{{ $payment->id }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm font-semibold">Чака превод · {{ $payment->formattedAmount() }}</p>
                <p class="hint">заявено {{ $payment->created_at->format('d.m.Y') }}</p>
            </div>

            <dl class="mt-3 divide-y divide-line border-y border-line">
                @foreach ($provider->instructions($payment) as $label => $value)
                    <div class="flex flex-wrap items-baseline justify-between gap-3 py-2">
                        <dt class="hint">{{ $label }}</dt>
                        <dd @class([
                            'text-sm',
                            'font-mono font-semibold tabular' => in_array($label, ['IBAN', 'Основание', 'Сума'], true),
                        ])>{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="hint mt-3">
                Основанието трябва да е точно това — по него разпознаваме превода.
            </p>
        </div>
    @endforeach

    {{-- ── Settled payments and their documents ─────────────────────────── --}}
    @if ($payments->isNotEmpty())
        <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-muted">Плащания</h2>

        <div class="card mt-3 divide-y divide-line overflow-hidden">
            @foreach ($payments as $payment)
                <div class="flex flex-wrap items-center justify-between gap-3 p-4"
                     wire:key="payment-{{ $payment->id }}">
                    <div class="min-w-0">
                        <p class="text-sm font-medium">
                            {{ $payment->status->label() }} · {{ $payment->formattedAmount() }}
                        </p>
                        <p class="hint mt-0.5 font-mono">{{ $payment->reference }}</p>
                        @if ($payment->cancelled_reason)
                            <p class="hint mt-0.5">{{ $payment->cancelled_reason }}</p>
                        @endif
                    </div>

                    @if ($payment->invoice)
                        <a href="{{ route('invoice', $payment->invoice) }}"
                           class="btn-secondary btn-sm shrink-0">
                            Фактура № {{ $payment->invoice->number }}
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- ── The ledger ───────────────────────────────────────────────────── --}}
    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-muted">Движения</h2>

    @if ($rows->isEmpty())
        <div class="card-pad mt-3 text-center">
            <p class="text-sm text-ink-muted">Още няма движения по кредита.</p>
        </div>
    @else
        {{-- A ledger, in the order a ledger is read: newest first, the amounts
             `tabular` so a column of them lines up — which is the only reason a
             list of numbers is checkable by eye. --}}
        <div class="card mt-3 divide-y divide-line overflow-hidden">
            @foreach ($rows as $row)
                <div class="flex items-start justify-between gap-4 p-4" wire:key="tx-{{ $row->id }}">
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ $row->kindLabel() }}</p>

                        @if ($row->note)
                            <p class="hint mt-0.5 break-words">{{ $row->note }}</p>
                        @endif

                        {{-- The listing the money went to, when there is one.
                             „Плащане · 9,00 €" with no name is a row a seller
                             cannot check against anything. --}}
                        @if ($row->source instanceof \App\Models\Boost && $row->source->listing)
                            <a href="{{ route('listing', $row->source->listing) }}" wire:navigate
                               class="link mt-0.5 block truncate text-xs">
                                {{ $row->source->listing->title }}
                            </a>
                        @endif

                        <p class="hint mt-0.5 font-mono">
                            {{ $row->created_at->format('d.m.Y H:i') }}
                        </p>
                    </div>

                    <p @class([
                        'shrink-0 font-mono tabular text-sm font-semibold',
                        'text-good' => $row->amount_cents > 0,
                        'text-ink'  => $row->amount_cents < 0,
                    ])>{{ $row->formattedAmount() }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-6">{{ $rows->links() }}</div>
    @endif
</div>
