<div class="mx-auto max-w-3xl">

    <h1 class="text-2xl font-bold tracking-tight">Моят кредит</h1>

    <div class="card-pad mt-4">
        <p class="hint">Налично</p>
        <p class="price mt-1 text-3xl leading-none">
            {{ number_format($balance / 100, 2, ',', ' ') }} €
        </p>

        {{-- Said now, rather than letting a seller hunt for a „Зареди" button
             that does not exist yet. Nothing here pretends a payment page is
             one click away. --}}
        <p class="hint mt-3 max-w-prose border-t border-line pt-3">
            Засега кредит не се зарежда онлайн. Използва се за платена
            видимост на обявите — виж „Промотирай" при всяка активна обява в
            <a href="{{ route('listings.mine') }}" wire:navigate class="link">Моите обяви</a>.
        </p>
    </div>

    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-muted">Движения</h2>

    @if ($rows->isEmpty())
        <div class="card-pad mt-3 text-center">
            <p class="text-sm text-ink-muted">Още няма движения по кредита.</p>
        </div>
    @else
        {{-- A ledger, in the order a ledger is read: newest first, sign on the
             left of nothing and hard against the number. The amounts are
             `tabular` so a column of them lines up — which is the only reason
             a list of numbers is checkable by eye. --}}
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
