<div class="mx-auto max-w-2xl space-y-6">
    <h1 class="text-xl font-semibold tracking-tight">Настройки на профила</h1>

    <form wire:submit="save" class="card-pad space-y-5">
        <div>
            <label class="label" for="city_id">Град</label>
            <select id="city_id" wire:model="city_id" class="mt-1">
                <option value="">Не е посочен</option>
                @foreach ($cities as $city)
                    <option value="{{ $city->id }}">{{ $city->name_bg }}</option>
                @endforeach
            </select>
        </div>

        <fieldset>
            <legend class="label">Продавам като</legend>
            <div class="mt-2 space-y-2">
                @foreach ($sellerTypes as $type)
                    <label class="flex items-start gap-2 rounded-md border border-line p-3 text-sm">
                        <input type="radio" value="{{ $type->value }}" wire:model.live="seller_type" class="mt-0.5">
                        <span>
                            <span class="font-medium">{{ $type->label() }}</span>
                            <span class="mt-0.5 block text-xs text-ink-muted">{{ $type->consumerNotice() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        @if ($seller_type === 'trader')
            <div class="space-y-3 rounded-md border border-line bg-surface-alt p-3">
                <p class="text-xs text-ink-muted">
                    По ЗЗП и ЗЕТ търговците трябва да са идентифицируеми. Данните се показват публично.
                </p>
                <div>
                    <label class="label" for="company">Фирма</label>
                    <input id="company" type="text" wire:model.blur="trader_details.company" class="mt-1">
                    @error('trader_details.company') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label" for="uic">ЕИК</label>
                        <input id="uic" type="text" wire:model.blur="trader_details.uic" class="mt-1 font-mono">
                        @error('trader_details.uic') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="vat">ДДС номер</label>
                        <input id="vat" type="text" wire:model.blur="trader_details.vat" class="mt-1 font-mono">
                    </div>
                </div>
                <div>
                    <label class="label" for="address">Адрес</label>
                    <input id="address" type="text" wire:model.blur="trader_details.address" class="mt-1">
                    @error('trader_details.address') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        <div>
            <label class="label" for="locale">Език</label>
            <select id="locale" wire:model="locale" class="mt-1">
                <option value="bg">Български</option>
                <option value="en">English</option>
            </select>
        </div>

        <button type="submit" class="btn-primary">Запази</button>
    </form>

    {{-- Verification: a SEPARATE card, and outside the form above on purpose.

         What the form above saves is the declaration — „продавам като търговец"
         plus the company details. That is the legal obligation, it applies from
         the moment it is saved, and nobody checks it. This card is the optional
         second thing: asking us to look the company up. Folding it into the same
         form would blur exactly the line the whole feature exists to keep.

         Gated on auth()->user()->isTrader() — the SAVED state — not on the
         $seller_type radio. There is nothing to check until the details are in
         the database. --}}
    @if (auth()->user()->isTrader())
        @php($me = auth()->user())

        <div class="card-pad space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-ink-muted">
                    Проверка на фирмата
                </h2>
                <span @class([
                    'badge-good'    => $me->trader_status === \App\Models\User::TRADER_VERIFIED,
                    'badge-warn'    => $me->trader_status === \App\Models\User::TRADER_PENDING,
                    'badge-bad'     => $me->trader_status === \App\Models\User::TRADER_REJECTED,
                    'badge-neutral' => $me->trader_status === \App\Models\User::TRADER_NONE,
                ])>{{ $me->traderStatusLabel() }}</span>
            </div>

            <p class="hint">
                Проверяваме фирмата в Търговския регистър и обявите ти получават етикет
                „проверена фирма" с името на дружеството. Незадължително е — правата на
                купувачите важат, откакто продаваш като търговец, независимо от проверката.
            </p>

            @if ($me->trader_status === \App\Models\User::TRADER_VERIFIED)
                <p class="text-sm">
                    Проверена на {{ $me->trader_verified_at?->format('d.m.Y') }}.
                    Ако данните на фирмата се променят, пиши ни от Поддръжка — етикетът
                    трябва да съвпада с регистъра.
                </p>
            @elseif ($me->trader_status === \App\Models\User::TRADER_PENDING)
                <p class="text-sm">Заявката чака човек. Обикновено до два работни дни.</p>
            @else
                @if ($me->trader_note)
                    {{-- The moderator's sentence, shown verbatim. „Не мина" on its
                         own teaches the applicant nothing and produces a support
                         ticket; „ЕИК-то е на друго дружество" they can fix. --}}
                    <p class="rounded-md border border-line bg-warn-soft p-3 text-sm text-warn">
                        {{ $me->trader_note }}
                    </p>
                @endif

                @error('verification') <p class="error">{{ $message }}</p> @enderror

                <button type="button" wire:click="requestVerification" class="btn-secondary btn-sm"
                        wire:loading.attr="disabled">
                    {{ $me->trader_status === \App\Models\User::TRADER_REJECTED
                        ? 'Заяви проверка отново'
                        : 'Заяви проверка' }}
                </button>
            @endif
        </div>
    @endif

    <form wire:submit="saveNotifications" class="card-pad space-y-4">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-ink-muted">Известия</h2>

        <p class="text-xs text-ink-muted">
            Известяваме те при нова оферта, контра-оферта, отговор на оферта, ново съобщение,
            потвърдена сделка и решение на модерацията. Офертите изтичат след 48 часа —
            изключиш ли всичко, часовникът пак върви.
        </p>

        <label class="flex items-start gap-2 rounded-md border border-line p-3 text-sm">
            <input type="checkbox" wire:model="notify_email" class="mt-0.5">
            <span>
                <span class="font-medium">Имейл</span>
                <span class="mt-0.5 block text-xs text-ink-muted">
                    @if (auth()->user()->hasVerifiedEmail())
                        До {{ auth()->user()->email }}
                    @else
                        Няма да получаваш имейли, докато не потвърдиш адреса си.
                    @endif
                </span>
            </span>
        </label>

        <label class="flex items-start gap-2 rounded-md border border-line p-3 text-sm">
            <input type="checkbox" wire:model="notify_telegram" class="mt-0.5">
            <span>
                <span class="font-medium">Telegram</span>
                <span class="mt-0.5 block text-xs text-ink-muted">
                    @if (auth()->user()->telegram_chat_id)
                        През бота, с който потвърди телефона си.
                    @else
                        Достъпно след потвърждаване на телефона през Telegram бота.
                    @endif
                </span>
            </span>
        </label>

        <button type="submit" class="btn-secondary">Запази известията</button>
    </form>

    <form wire:submit="updatePassword" class="card-pad space-y-4">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-ink-muted">Смяна на парола</h2>

        <div>
            <label class="label" for="current_password">Текуща парола</label>
            <input id="current_password" type="password" wire:model="current_password"
                   autocomplete="current-password" class="mt-1">
            @error('current_password') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="new_password">Нова парола</label>
            <input id="new_password" type="password" wire:model="password"
                   autocomplete="new-password" class="mt-1">
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="password_confirmation">Повтори новата парола</label>
            <input id="password_confirmation" type="password" wire:model="password_confirmation"
                   autocomplete="new-password" class="mt-1">
        </div>

        <button type="submit" class="btn-secondary">Смени паролата</button>
    </form>
</div>
