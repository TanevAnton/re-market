<div class="mt-4">
    @if ($sent)
        <div class="rounded-lg border border-line bg-good-soft p-3">
            <p class="text-sm font-medium text-good">Сигналът е получен.</p>
            <p class="mt-1 text-xs leading-relaxed text-good">
                Ще го прегледаме и ще ти съобщим решението.
                Номер за справка: <span class="font-mono">{{ $reference }}</span>
            </p>
        </div>
    @elseif (! $open)
        <button type="button" wire:click="toggle" class="text-xs text-ink-faint hover:text-bad hover:underline">
            Докладвай
        </button>
    @else
        <div class="card-pad">
            <p class="text-sm font-medium">Докладвай съдържание</p>
            <p class="hint">
                Прегледаме всеки сигнал. Злоупотребата със сигнали също е нарушение.
            </p>

            <label class="label mt-3" for="report-reason">Причина</label>
            <select id="report-reason" wire:model="reason" class="mt-1 w-full">
                <option value="">— избери —</option>
                @foreach ($reasons as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('reason') <p class="error">{{ $message }}</p> @enderror

            <label class="label mt-3" for="report-detail">Какъв е проблемът</label>
            <textarea id="report-detail" wire:model="detail" rows="3" class="mt-1 w-full"
                      placeholder="Например: тези снимки са от моята обява отпреди две седмици."></textarea>
            @error('detail') <p class="error">{{ $message }}</p> @enderror

            <label class="label mt-3" for="report-evidence">Връзка към доказателство (по избор)</label>
            <input id="report-evidence" type="url" wire:model="evidence" class="mt-1 w-full"
                   placeholder="https://...">
            @error('evidence') <p class="error">{{ $message }}</p> @enderror

            @guest
                <label class="label mt-3" for="report-email">Твоят имейл</label>
                <input id="report-email" type="email" wire:model="email" class="mt-1 w-full">
                <p class="hint">Нужен ни е само за да ти съобщим решението.</p>
                @error('email') <p class="error">{{ $message }}</p> @enderror
            @endguest

            <x-turnstile />
            @error('turnstile') <p class="error">{{ $message }}</p> @enderror

            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="submit" class="btn-primary btn-sm"
                        wire:loading.attr="disabled">
                    Изпрати сигнал
                </button>
                <button type="button" wire:click="toggle" class="btn-ghost btn-sm">Откажи</button>
            </div>
        </div>
    @endif
</div>
