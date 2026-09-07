<div class="mx-auto max-w-md">
    <h1 class="text-2xl font-semibold tracking-tight">Създай профил</h1>
    <p class="mt-1 text-sm text-neutral-500">
        Безплатно е. Ще ти поискаме телефон, за да няма ботове и фалшиви обяви.
    </p>

    <form wire:submit="register" class="mt-6 space-y-4">
        <div>
            <label for="username" class="block text-sm font-medium">Потребителско име</label>
            <input id="username" type="text" wire:model.blur="username" autocomplete="username" autofocus>
            @error('username') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-neutral-400">Това виждат купувачите. Не може да се сменя после.</p>
        </div>

        <div>
            <label for="email" class="block text-sm font-medium">Имейл</label>
            <input id="email" type="email" wire:model.blur="email" autocomplete="email">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">Парола</label>
            <input id="password" type="password" wire:model.blur="password" autocomplete="new-password">
            @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium">Повтори паролата</label>
            <input id="password_confirmation" type="password" wire:model.blur="password_confirmation"
                   autocomplete="new-password">
        </div>

        <div>
            <label for="city_id" class="block text-sm font-medium">Град</label>
            <select id="city_id" wire:model="city_id">
                <option value="">Избери град</option>
                @foreach ($cities as $city)
                    <option value="{{ $city->id }}">{{ $city->name_bg }}</option>
                @endforeach
            </select>
            @error('city_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        {{-- ЗЗП / Omnibus Art. 6a: the trader declaration is a legal
             requirement, and it must be the seller's own statement. --}}
        <fieldset>
            <legend class="block text-sm font-medium">Продаваш като</legend>
            <div class="mt-2 space-y-2">
                @foreach ($sellerTypes as $type)
                    <label class="flex items-start gap-2 rounded-lg border border-neutral-200 p-3 text-sm">
                        <input type="radio" name="seller_type" value="{{ $type->value }}"
                               wire:model="seller_type" class="mt-0.5">
                        <span>
                            <span class="font-medium">{{ $type->label() }}</span>
                            <span class="mt-0.5 block text-xs text-neutral-500">{{ $type->consumerNotice() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('seller_type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </fieldset>

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" wire:model="terms" class="mt-0.5">
            <span>Приемам условията за ползване и политиката за поверителност.</span>
        </label>
        @error('terms') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

        <x-turnstile />
        @error('turnstile') <p class="error">{{ $message }}</p> @enderror

        <button type="submit"
                class="w-full rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white
                       transition hover:bg-neutral-700 disabled:opacity-50"
                wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="register">Създай профил</span>
            <span wire:loading wire:target="register">Момент…</span>
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-neutral-500">
        Вече имаш профил?
        <a href="{{ route('login') }}" wire:navigate class="font-medium text-neutral-900 hover:underline">Влез</a>
    </p>
</div>
