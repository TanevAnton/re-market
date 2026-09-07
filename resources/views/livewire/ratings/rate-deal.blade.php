<div class="mt-3 border-t border-line pt-3">

@if ($mine)
    <div class="flex flex-wrap items-center gap-2">
        <span class="label">Твоята оценка</span>
        <span class="font-mono text-sm text-accent">
            {{ str_repeat('★', $mine->score) }}<span class="text-ink-faint">{{ str_repeat('★', 5 - $mine->score) }}</span>
        </span>
    </div>
    @if ($mine->comment)
        <p class="mt-1 text-xs italic text-ink-muted">„{{ $mine->comment }}"</p>
    @endif

@elseif ($this->deal->status === \App\Enums\DealStatus::Completed)
    <form wire:submit="submit">
        <span class="label">Как мина с {{ $other->username }}?</span>

        {{-- Radios, not JavaScript stars. Keyboard-reachable, works with the
             form post, and one less thing to break. --}}
        <div class="mt-2 flex items-center gap-1">
            @foreach ([1, 2, 3, 4, 5] as $n)
                <label class="cursor-pointer">
                    <input type="radio" name="score-{{ $deal->id }}" value="{{ $n }}"
                           wire:model.live="score" class="sr-only peer">
                    <span @class([
                        'text-xl transition',
                        'text-accent' => $score >= $n,
                        'text-ink-faint hover:text-ink-muted' => $score < $n,
                    ])>★</span>
                </label>
            @endforeach

            @if ($score)
                <span class="ml-2 text-xs text-ink-muted">
                    {{ [1 => 'Зле', 2 => 'Слабо', 3 => 'Приемливо', 4 => 'Добре', 5 => 'Отлично'][$score] }}
                </span>
            @endif
        </div>

        @error('score') <p class="error">{{ $message }}</p> @enderror

        <input type="text" wire:model="comment" maxlength="1000"
               placeholder="Коментар (по избор)" class="mt-2 text-sm">
        @error('comment') <p class="error">{{ $message }}</p> @enderror

        <button type="submit" class="btn-secondary btn-sm mt-2">Оцени</button>
    </form>
@endif

</div>
