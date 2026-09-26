<div class="mx-auto max-w-xl">

    <h1 class="text-2xl font-bold tracking-tight">Проверка на сериен номер</h1>
    <p class="mt-1 text-sm text-ink-muted">
        Преди да платиш — питай продавача за серийния номер и го провери тук.
    </p>

    <form wire:submit="check" class="card-pad mt-6 space-y-4">
        <div>
            <span class="label">Вид</span>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($kinds as $key => $label)
                    <button type="button" wire:click="$set('kind', '{{ $key }}')"
                            @class([
                                'rounded-md border px-3 py-1.5 text-sm font-medium transition',
                                'border-accent text-accent' => $kind === $key,
                                'border-line text-ink-muted hover:text-ink' => $kind !== $key,
                            ])>{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div>
            <label class="label" for="value">Номер</label>
            <input id="value" type="text" wire:model="value" class="mt-1 font-mono"
                   placeholder="{{ $kind === 'imei' ? '15 цифри — набери *#06# на телефона' : 'както е изписан на устройството' }}">
            @error('value') <p class="error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn-primary" wire:loading.attr="disabled">Провери</button>
    </form>

    {{-- The answer, and the wording IS the feature. --}}
    @if ($result)
        <div @class([
            'card-pad mt-4',
            'border-bad/50'    => $result['reported'],
            'border-accent/40' => ! $result['reported'] && $result['known'],
        ])>
            @if ($result['reported'])
                <p class="text-sm font-semibold text-bad">
                    Има подаден сигнал за този номер (…{{ $result['last4'] }})
                </p>
                {{-- The disclaimer is on ONE LINE on purpose. It is the
                     sentence that keeps this page from reading as a finding of
                     theft, there is a test asserting it verbatim, and a tidy
                     line-wrap in the middle of it puts a newline inside the
                     phrase and makes that test pass or fail on formatting. --}}
                <p class="mt-2 text-sm leading-relaxed">
                    Някой е заявил пред нас, че вещ с този номер му е отнета, и е
                    посочил номер на сигнал до полицията.
                    <strong>Това не е доказателство за кражба</strong> — не проверяваме
                    полицейски регистри и не можем. Но е основание да поискаш
                    документ за покупка и да не бързаш.
                </p>
                <p class="hint mt-3">
                    Ако смяташ, че става дума за престъпление, обади се на 112 или
                    се обърни към районното управление. Ние не сме орган и не
                    установяваме нищо.
                </p>
            @elseif ($result['known'])
                <p class="text-sm font-semibold text-accent">
                    Номерът е записан към обява тук (…{{ $result['last4'] }})
                </p>
                <p class="mt-2 text-sm leading-relaxed">
                    Продавач е въвел този номер при публикуване и няма подаден
                    сигнал срещу него. Това е слаб, но истински знак: човек,
                    който препродава чужди снимки, обикновено не разполага с
                    самата вещ.
                </p>
                @if ($result['listings'] > 1)
                    <p class="mt-2 text-sm font-medium text-bad">
                        Внимание: номерът е към {{ $result['listings'] }} активни обяви.
                        Една вещ е на едно място — питай продавача какво става.
                    </p>
                @endif
            @else
                <p class="text-sm font-semibold">Не знаем нищо за този номер</p>
                {{-- The most important paragraph on the page. „No result" read
                     as „not stolen" leaves a buyer worse off than not
                     checking at all. --}}
                <p class="mt-2 text-sm leading-relaxed">
                    <strong>Това не значи, че вещта е чиста.</strong> Значи само,
                    че при нас няма подаден сигнал и никой продавач не е въвел
                    този номер. Повечето вещи, включително откраднатите, не са в
                    никакъв регистър.
                </p>
                <p class="hint mt-3">
                    Пак поискай документ за покупка, провери вещта на място и
                    плати след като си я видял.
                </p>
            @endif
        </div>
    @endif

    <p class="hint mt-6">
        Вещ с този номер ти е отнета?
        <a href="{{ route('safety.report') }}" wire:navigate class="link">Подай сигнал</a>.
    </p>
</div>
