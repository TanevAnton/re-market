<div class="mx-auto max-w-md">
    <h1 class="text-2xl font-semibold tracking-tight">Забравена парола</h1>

    @if ($sent)
        {{-- The same screen whatever happened.

             Saying "we sent it" only when an account exists turns this form
             into a lookup: type an address, learn whether it is registered
             here. On a marketplace that list is people who own expensive
             hardware and meet strangers to sell it. --}}
        <div class="card-pad mt-6">
            <p class="text-sm leading-relaxed">
                Ако има профил с <strong class="font-medium">{{ $email }}</strong>,
                изпратихме линк за нова парола.
            </p>
            <p class="hint">
                Провери и папката със спам. Линкът важи един час.
            </p>

            <a href="{{ route('login') }}" wire:navigate class="btn-secondary btn-sm mt-4">
                Обратно към входа
            </a>
        </div>
    @else
        <p class="mt-1 text-sm text-ink-muted">
            Напиши имейла, с който си се регистрирал. Изпращаме линк за нова парола.
        </p>

        <div class="card-pad mt-6">
            <form wire:submit="send" class="space-y-4">
                <div>
                    <label for="email" class="label">Имейл</label>
                    <input id="email" type="email" wire:model="email" autocomplete="email"
                           autofocus class="mt-2">
                    @error('email') <p class="error">{{ $message }}</p> @enderror
                </div>

                <x-turnstile />
                @error('turnstile') <p class="error">{{ $message }}</p> @enderror

                <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="send">Изпрати линк</span>
                    <span wire:loading wire:target="send">Изпращаме…</span>
                </button>
            </form>
        </div>

        <p class="mt-6 text-center text-sm text-ink-muted">
            Спомни си я?
            <a href="{{ route('login') }}" wire:navigate class="link font-medium">Влез</a>
        </p>
    @endif
</div>
