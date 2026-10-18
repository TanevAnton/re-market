{{-- The last screen of „влез с Google", and the only one that creates a row.

     Three fields, because Google supplies everything else. The reason each one
     is here rather than defaulted is in CompleteGoogleSignup's docblock; the
     short version is that a username is permanent, a phone is required, and
     accepting a contract is an act. --}}

<div class="mx-auto max-w-md">
    <h1 class="text-2xl font-semibold tracking-tight">Остават три неща</h1>
    <p class="mt-1 text-sm text-ink-muted">
        Google потвърди имейла ти. Това не можем да го вземем от там.
    </p>

    {{-- Shown, not editable. The address is the one thing already proved, and
         a field somebody could change here would quietly undo that proof. --}}
    <div class="card-pad mt-6 flex items-center gap-3">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-good-soft text-good">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="h-5 w-5">
                <path d="m5 12.5 4.5 4.5L19 7.5"/>
            </svg>
        </span>
        <div class="min-w-0">
            <p class="truncate text-sm font-medium">{{ $email }}</p>
            <p class="text-xs text-ink-muted">Потвърден през Google</p>
        </div>
    </div>

    <form wire:submit="complete" class="mt-5 space-y-5">

        <div class="card-pad space-y-4">
            <div>
                <label for="username" class="label">Потребителско име</label>
                <input id="username" type="text" wire:model.blur="username"
                       autocomplete="username" autofocus class="mt-2">
                @error('username') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">Това виждат купувачите. Не може да се сменя после.</p>
            </div>

            <div>
                <label for="phone" class="label">Телефон</label>
                <input id="phone" type="tel" inputmode="tel" wire:model.blur="phone"
                       autocomplete="tel" placeholder="0888 123 456" class="mt-2">
                @error('phone') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">
                    Български мобилен номер. Не се показва публично — купувачите
                    виждат само последните четири цифри, и то след сделка.
                </p>
            </div>

            <div>
                <label for="city_id" class="label">Град <span class="text-ink-faint">(по желание)</span></label>
                <select id="city_id" wire:model="city_id" class="mt-2">
                    <option value="">Избери град</option>
                    @foreach ($cities as $city)
                        <option value="{{ $city->id }}">{{ $city->name_bg }}</option>
                    @endforeach
                </select>
                @error('city_id') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- ЗЗП / Omnibus Art. 6a, same as the ordinary register page: the
             trader declaration is a legal requirement and it has to be the
             seller's own statement. It does not get to be skipped because this
             signup started somewhere else. --}}
        <fieldset class="card-pad">
            <legend class="label">Продаваш като</legend>
            <div class="mt-3 space-y-2">
                @foreach ($sellerTypes as $type)
                    <label @class([
                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition',
                        'border-accent bg-accent-soft' => $seller_type === $type->value,
                        'border-line hover:border-line-strong' => $seller_type !== $type->value,
                    ])>
                        <input type="radio" name="seller_type" value="{{ $type->value }}"
                               wire:model.live="seller_type" class="mt-0.5">
                        <span>
                            <span class="font-medium">{{ $type->label() }}</span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-ink-muted">
                                {{ $type->consumerNotice() }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('seller_type') <p class="error">{{ $message }}</p> @enderror
        </fieldset>

        <div>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" wire:model="terms" class="mt-0.5">
                <span class="text-ink-muted">
                    Приемам
                    <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener" class="link">условията за ползване</a>
                    и
                    <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener" class="link">политиката за поверителност</a>.
                </span>
            </label>
            @error('terms') <p class="error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="complete">Създай профила</span>
            <span wire:loading wire:target="complete">Момент…</span>
        </button>

        {{-- Said here rather than left to be discovered. The account has no
             password anybody knows, so the only way in is this button — and the
             day somebody wants a second way, the reset flow is it, and it works
             because the address is already confirmed. --}}
        <p class="hint text-center">
            Ще влизаш с Google. Ако искаш и парола, задай си я по-късно през
            „забравена парола".
        </p>
    </form>
</div>
