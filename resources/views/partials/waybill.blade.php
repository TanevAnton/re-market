@php($draft = \App\Support\WaybillDraft::for($deal))
@php($courier = $deal->courierEnum())

{{-- The seller's half of the handover: everything the courier form asks for, in
     the order it asks for it.

     WHY THIS IS A COPY BLOCK AND NOT A BUTTON THAT CREATES THE WAYBILL. RIGO
     holds no contract with either courier and does not want one — whoever creates
     the waybill is the sender of record, and the sender of record is who the
     cash-on-delivery is paid to. „The platform never touches the money" is the
     whole regulatory position, and a convenience button must not be what undoes
     it. See App\Support\WaybillDraft. --}}
<div class="mt-4 rounded-lg border border-line bg-surface-alt p-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-semibold">Товарителница</h3>
        @if ($courier)
            <span class="badge-neutral">{{ $courier->label() }}</span>
        @endif
    </div>

    @if ($deal->deliveryPurged())
        <p class="hint mt-2">
            Данните за доставка са изтрити — сделката е приключена отдавна.
            Товарителница {{ $deal->tracking_number ?: '—' }}.
        </p>
    @elseif (! $draft->isReady())
        <p class="hint mt-2">
            Купувачът още не е попълнил данните за доставка. Ще получиш известие.
        </p>
    @else
        <dl class="mt-3 space-y-2.5 text-sm">
            @foreach ($draft->fields() as $field)
                <div>
                    <dt class="text-xs uppercase tracking-wider text-ink-muted">{{ $field['label'] }}</dt>
                    <dd class="select-all break-words font-medium">{{ $field['value'] }}</dd>
                    @if ($field['hint'])
                        <p class="hint">{{ $field['hint'] }}</p>
                    @endif
                </div>
            @endforeach
        </dl>

        {{-- Alpine, not a Livewire round trip: the clipboard is entirely a browser
             job and a spinner on the one interaction that has to feel instant is
             worse than no button.

             A HIDDEN TEXTAREA, read through `.value`. The obvious version — put
             the text in a data attribute or read innerHTML back and un-escape the
             entities by hand — breaks the first time a buyer's address contains
             an `&` or a quote, and the hand-written un-escaper is how an address
             field becomes an injection. Blade escapes into the textarea's text
             content and the browser decodes it for `.value`, so neither end has
             to be clever. `sr-only` rather than `hidden`, because a display:none
             textarea has no reliable value in every browser. --}}
        <div class="mt-4" x-data="{ copied: false }">
            <textarea x-ref="payload" readonly tabindex="-1" aria-hidden="true"
                      class="sr-only">{{ $draft->asText() }}</textarea>

            <button type="button" class="btn-secondary btn-sm"
                    x-on:click="navigator.clipboard.writeText($refs.payload.value)
                                .then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                <span x-show="! copied">Копирай всички полета</span>
                <span x-show="copied" x-cloak>Копирано</span>
            </button>
        </div>

        <p class="hint mt-3 border-t border-line pt-3">
            Товарителницата я правиш ти — от профила си в {{ $courier?->label() }}
            или на място в офиса. Наложеният платеж отива директно при теб;
            RIGO не участва в плащането и не взима нищо от сумата.
        </p>
        <p class="hint">
            {{-- Said plainly because nothing on the platform captures it, and a
                 panel that guessed „получателят плаща" would be inventing a term
                 of somebody else's agreement. --}}
            Кой плаща доставката е между вас двамата — уговорете го в чата, ако още не сте.
        </p>
    @endif
</div>
