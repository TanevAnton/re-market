{{-- One listing-scoped spec input.

     Extracted because these render in TWO places on step 3 now: the required
     ones in the body of the step, and the rest inside „Подробности по желание".
     Two copies of this markup would drift, and the half that drifted would be
     the half nobody was looking at.

     Takes $key, $spec and $required. --}}

<div>
    <label class="label" for="spec-{{ $key }}">
        {{ $spec['label'][app()->getLocale()] ?? $spec['label']['en'] }}
        @if (! empty($spec['unit'])) <span class="normal-case">({{ $spec['unit'] }})</span> @endif
    </label>

    @if ($spec['type'] === 'bool')
        <label class="mt-1 flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="specs.{{ $key }}"> да
        </label>
    @elseif ($spec['type'] === 'select')
        <select id="spec-{{ $key }}" wire:model="specs.{{ $key }}" class="mt-1">
            {{-- BOTH branches need an option with an empty value, and the
                 reason is the browser rather than the validator.

                 A <select> whose bound value matches no option displays the
                 FIRST one. Without this, a required select the seller has not
                 touched shows „Да, излязъл съм от iCloud" selected while the
                 property is still null — the screen claims an answer nobody
                 gave, and then rejects the listing over the field that appears
                 to be filled in. On the Apple question the implied answer is
                 also the reassuring one, which is the worst possible default
                 to put in somebody's mouth.

                 `disabled` on the required one is the difference: it is
                 there to be displayed, not to be chosen, so it cannot become a
                 way to skip a question the schema already gives an honest way
                 out of. Validation is unchanged — an empty value fails
                 `required` either way. --}}
            @if ($required ?? false)
                <option value="" disabled>— избери —</option>
            @else
                <option value="">—</option>
            @endif
            @foreach ($spec['options'] ?? [] as $opt)
                <option value="{{ $opt }}">{{ $opt }}</option>
            @endforeach
        </select>
    @else
        <input id="spec-{{ $key }}" type="{{ $spec['type'] === 'int' ? 'number' : 'text' }}"
               wire:model.blur="specs.{{ $key }}" class="mt-1">
    @endif

    @if (! empty($spec['help']))
        <p class="hint">{{ $spec['help'][app()->getLocale()] ?? $spec['help']['en'] }}</p>
    @endif

    @error('specs.'.$key) <p class="error">{{ $message }}</p> @enderror
</div>
