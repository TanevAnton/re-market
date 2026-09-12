<div class="mt-3 border-t border-line pt-3">

@if ($mine)
    <div class="flex flex-wrap items-center gap-2">
        <span class="label">Твоята оценка</span>
        @include('partials.stars', ['score' => $mine->score])
    </div>
    @if ($mine->comment)
        <p class="mt-1 text-xs italic text-ink-muted">„{{ $mine->comment }}"</p>
    @endif

@elseif ($canRate)
    {{-- The prompt, not just the form.

         A completed deal showed five grey stars and nothing saying why they
         were there. Ratings are the only reputation this site has, and they
         are collected exactly once, in the few days after a handover while
         both people still remember it - so this asks rather than waits. --}}
    <form wire:submit="submit">
        <span class="label">Как мина с {{ $other->username }}?</span>

        {{-- Radios, not JavaScript stars. Keyboard-reachable, works with the
             form post, and one less thing to break. --}}
        <div class="mt-2 flex items-center gap-1">
            @foreach ([1, 2, 3, 4, 5] as $n)
                <label class="cursor-pointer" title="{{ $n }} от 5">
                    <input type="radio" name="score-{{ $deal->id }}" value="{{ $n }}"
                           wire:model.live="score" class="sr-only peer">
                    <span @class([
                        'text-xl leading-none transition',
                        'text-accent' => $score >= $n,
                        'text-ink-faint hover:text-ink-muted' => $score < $n,
                        // Without this the keyboard user tabbing through five
                        // sr-only radios gets no visible focus anywhere.
                        'peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--accent)]' => true,
                    ])>★</span>
                    <span class="sr-only">{{ $n }} от 5</span>
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

        <div class="mt-2 flex flex-wrap items-center gap-3">
            <button type="submit" class="btn-secondary btn-sm">Оцени</button>
            {{-- Said before they write, not after: a rating that turns out to
                 be permanent and public only once it is posted is how people
                 learn to distrust the form. --}}
            <p class="text-xs text-ink-faint">Публична е и не може да се променя.</p>
        </div>
    </form>

@elseif ($this->deal->status === \App\Enums\DealStatus::Completed)
    {{-- Completed, unrated, and out of time. Said plainly rather than by
         silently removing the form, which reads as a bug. --}}
    <p class="text-xs text-ink-faint">
        Срокът за оценка на тази сделка изтече.
    </p>
@endif

</div>
