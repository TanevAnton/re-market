{{-- Login and register predate the design system and were never converted.
     They were still on raw palette utilities - fixed mid-greys for muted text,
     a hand-rolled near-black submit button, a fixed red for errors - none of
     which move with the theme. Dark is the default here, so that meant grey on
     grey and a near-black button on a near-black page, on the first two
     screens a stranger ever sees.

     The class names are deliberately not spelled out above: Tailwind scans
     this file for utilities and would compile any it found in a comment. --}}
<div class="mx-auto max-w-md">
    <h1 class="text-2xl font-semibold tracking-tight">Вход</h1>

    <div class="card-pad mt-6">
        <form wire:submit="authenticate" class="space-y-4">
            <div>
                <label for="login" class="label">Имейл или потребителско име</label>
                <input id="login" type="text" wire:model="login" autocomplete="username" autofocus class="mt-2">
                @error('login') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div>
                <div class="flex items-baseline justify-between gap-2">
                    <label for="password" class="label">Парола</label>
                    {{-- Next to the field it belongs to, not buried under the
                         form: someone who cannot get in is looking at the
                         password box, and that is where the way out should be. --}}
                    @if (\Illuminate\Support\Facades\Route::has('password.request'))
                        <a href="{{ route('password.request') }}" wire:navigate
                           class="text-xs text-ink-muted underline-offset-2 hover:text-accent hover:underline">
                            Забравена парола?
                        </a>
                    @endif
                </div>
                <input id="password" type="password" wire:model="password" autocomplete="current-password" class="mt-2">
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-ink-muted">
                <input type="checkbox" wire:model="remember">
                Запомни ме
            </label>

            <x-turnstile />
            @error('turnstile') <p class="error">{{ $message }}</p> @enderror

            <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="authenticate">Влез</span>
                <span wire:loading wire:target="authenticate">Момент…</span>
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-sm text-ink-muted">
        Нямаш профил?
        <a href="{{ route('register') }}" wire:navigate class="link font-medium">Регистрирай се</a>
    </p>
</div>
