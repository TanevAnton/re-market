<div class="mx-auto max-w-md">
    <h1 class="text-2xl font-semibold tracking-tight">Нова парола</h1>

    @if ($email)
        <p class="mt-1 text-sm text-ink-muted">
            За профила с <strong class="font-medium text-ink">{{ $email }}</strong>.
        </p>
    @endif

    <div class="card-pad mt-6">
        <form wire:submit="save" class="space-y-4">
            {{-- Hidden but present, so a password manager knows which account
                 it is being asked to update. Without it the browser offers to
                 save the new password against no username at all. --}}
            <input type="hidden" autocomplete="username" value="{{ $email }}">

            <div>
                <label for="password" class="label">Нова парола</label>
                <input id="password" type="password" wire:model="password"
                       autocomplete="new-password" autofocus class="mt-2">
                @error('password') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">Поне 8 знака.</p>
            </div>

            <div>
                <label for="password_confirmation" class="label">Повтори паролата</label>
                <input id="password_confirmation" type="password" wire:model="password_confirmation"
                       autocomplete="new-password" class="mt-2">
            </div>

            <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Смени паролата</span>
                <span wire:loading wire:target="save">Момент…</span>
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-sm text-ink-muted">
        Линкът не работи?
        <a href="{{ route('password.request') }}" wire:navigate class="link font-medium">Поискай нов</a>
    </p>
</div>
