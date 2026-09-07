<div class="mx-auto max-w-md">
    <h1 class="text-2xl font-semibold tracking-tight">Вход</h1>

    <form wire:submit="authenticate" class="mt-6 space-y-4">
        <div>
            <label for="login" class="block text-sm font-medium">Имейл или потребителско име</label>
            <input id="login" type="text" wire:model="login" autocomplete="username" autofocus>
            @error('login') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">Парола</label>
            <input id="password" type="password" wire:model="password" autocomplete="current-password">
            @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="remember">
            Запомни ме
        </label>

        <x-turnstile />
        @error('turnstile') <p class="error">{{ $message }}</p> @enderror

        <button type="submit"
                class="w-full rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white
                       transition hover:bg-neutral-700 disabled:opacity-50"
                wire:loading.attr="disabled">
            Влез
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-neutral-500">
        Нямаш профил?
        <a href="{{ route('register') }}" wire:navigate class="font-medium text-neutral-900 hover:underline">
            Регистрирай се
        </a>
    </p>
</div>
