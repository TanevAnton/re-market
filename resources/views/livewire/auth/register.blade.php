<div class="mx-auto max-w-md">
    <h1 class="text-2xl font-semibold tracking-tight">Създай профил</h1>
    {{-- The note that used to live here said this page does NOT ask for a
         phone, because at the time it did not. It does now: the number is
         required, and the person chooses whether to prove the account by email
         or by SMS. Both unlock it - see EnsureAccountIsVerified. --}}
    {{-- One sentence, two versions, because the one the visitor reads has to be
         the one that is true today. Promising „или с SMS" while the SMS channel
         is switched off is a small lie on the first screen of the site. --}}
    <p class="mt-1 text-sm text-ink-muted">
        @if ($this->phoneVerificationEnabled())
            Безплатно е. Потвърждаваш профила си по имейл или с SMS и си готов —
            това държи ботовете и фалшивите обяви навън.
        @else
            Безплатно е. Потвърждаваш имейла си и си готов — това държи ботовете
            и фалшивите обяви навън.
        @endif
    </p>

    <form wire:submit="register" class="mt-6 space-y-5">

        <div class="card-pad space-y-4">
            <div>
                <label for="username" class="label">Потребителско име</label>
                <input id="username" type="text" wire:model.blur="username" autocomplete="username"
                       autofocus class="mt-2">
                @error('username') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">Това виждат купувачите. Не може да се сменя после.</p>
            </div>

            <div>
                <label for="email" class="label">Имейл</label>
                <input id="email" type="email" wire:model.blur="email" autocomplete="email" class="mt-2">
                @error('email') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">Ще получиш линк за потвърждение. Не се показва публично.</p>
            </div>

            {{-- inputmode="tel" rather than type="number": a number input strips
                 the leading zero of 0888..., offers spinner arrows nobody wants
                 on a phone number, and refuses a + sign. --}}
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
                <label for="password" class="label">Парола</label>
                <input id="password" type="password" wire:model.blur="password"
                       autocomplete="new-password" class="mt-2">
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password_confirmation" class="label">Повтори паролата</label>
                <input id="password_confirmation" type="password" wire:model.blur="password_confirmation"
                       autocomplete="new-password" class="mt-2">
            </div>

            <div>
                <label for="city_id" class="label">Град</label>
                <select id="city_id" wire:model="city_id" class="mt-2">
                    <option value="">Избери град</option>
                    @foreach ($cities as $city)
                        <option value="{{ $city->id }}">{{ $city->name_bg }}</option>
                    @endforeach
                </select>
                @error('city_id') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">Помага на купувачите да преценят дали е за вземане на ръка.</p>
            </div>
        </div>

        {{-- ЗЗП / Omnibus Art. 6a: the trader declaration is a legal
             requirement, and it must be the seller's own statement.

             Given its own card and its own selected state, because it is a
             declaration rather than a preference - the one field on this form
             that carries consequences outside the site. --}}
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

        {{-- How to prove the account. Email is first and is the default: it is
             free and instant, and a default nobody chose should not be the one
             that costs money.

             HIDDEN WHOLESALE while phone verification is off, rather than
             showing one option. A fieldset headed „Как да потвърдим профила"
             with a single radio in it is a question that is not a question, and
             it invites somebody to wonder what the other answer was. The block
             comes back untouched when the flag does. --}}
        @if ($this->phoneVerificationEnabled())
        <fieldset class="card-pad">
            <legend class="label">Как да потвърдим профила</legend>
            <div class="mt-3 space-y-2">
                @foreach ([
                    'email' => ['Имейл', 'Пращаме линк на имейла ти. Безплатно и веднага.'],
                    'sms'   => ['SMS', 'Пращаме код на телефона ти. Полезно, ако имейлът ти се бави.'],
                ] as $value => [$title, $note])
                    <label @class([
                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition',
                        'border-accent bg-accent-soft' => $verify_via === $value,
                        'border-line hover:border-line-strong' => $verify_via !== $value,
                    ])>
                        <input type="radio" name="verify_via" value="{{ $value }}"
                               wire:model.live="verify_via" class="mt-0.5">
                        <span>
                            <span class="font-medium">{{ $title }}</span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-ink-muted">{{ $note }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('verify_via') <p class="error">{{ $message }}</p> @enderror
            <p class="hint mt-3">
                Може да потвърдиш и двете по-късно от профила си.
            </p>
        </fieldset>
        @endif

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

        <x-turnstile />
        @error('turnstile') <p class="error">{{ $message }}</p> @enderror

        <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="register">Създай профил</span>
            <span wire:loading wire:target="register">Момент…</span>
        </button>
    </form>

    {{-- „Влез с Google".

         NO GOOGLE LOGO HERE, and that is on purpose rather than an omission.
         Google's identity guidelines require their OFFICIAL mark on this
         button, so a hand-drawn approximation is both wrong and a trademark
         problem. Drop the official SVG into resources/views/partials/ and swap
         the span below for it. Until then the button is text, which is honest
         and works.

         Hidden entirely when the credentials are not configured, rather than
         rendering a button that dead-ends on an error page. --}}
    @if (filled(config('services.google.client_id')))
        <div class="mt-5">
            <div class="flex items-center gap-3">
                <span class="h-px flex-1 bg-line"></span>
                <span class="text-xs text-ink-faint">или</span>
                <span class="h-px flex-1 bg-line"></span>
            </div>

            <a href="{{ route('google.redirect') }}" class="btn-secondary mt-4 w-full">
                <span class="font-semibold">G</span>
                Продължи с Google
            </a>
        </div>
    @endif


    <p class="mt-6 text-center text-sm text-ink-muted">
        Вече имаш профил?
        <a href="{{ route('login') }}" wire:navigate class="link font-medium">Влез</a>
    </p>
</div>
