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
            {{-- A required select has no blank option: the schema already
                 provides the honest way out („Не е проверено"), so an empty
                 choice would only be a way to skip the question. --}}
            @unless ($required ?? false)
                <option value="">—</option>
            @endunless
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
