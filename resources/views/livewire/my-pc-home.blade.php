{{-- „Моят компютър" on the home page. See App\Livewire\MyPcHome.

     TWO STATES, ONE PLACE. Nothing saved: what it is for, and three questions
     that can be answered right here. Something saved: what the site now knows,
     and a way to add the rest - the panel on every model and listing page is
     already using it. A visitor who has set it up sees their own computer on
     the front page rather than the same pitch again. --}}
@php
    // Short names for the summary; the full wording belongs to the form.
    $short = [
        'socket'            => 'Сокет',
        'ram_type'          => 'Памет',
        'board_form_factor' => 'Платка',
        'case_form_factor'  => 'Кутия приема',
        'case_max_gpu_mm'   => 'Макс. карта',
        'psu_w'             => 'Захранване',
        'gpu_tdp_w'         => 'Карта',
        'gpu_length_mm'     => 'Дължина на картата',
    ];
@endphp

<section class="pb-12 sm:pb-14" data-my-pc-home>
    <div class="overflow-hidden rounded-xl border border-line border-l-2 border-l-accent bg-surface">

        @if ($filled === 0)
            <div class="grid gap-6 p-5 sm:p-6 lg:grid-cols-[1fr_1.15fr] lg:items-center">
                <div>
                    <p class="font-mono text-[10.5px] uppercase tracking-[0.18em] text-ink-faint">Моят компютър</p>
                    <h2 class="mt-2 text-[19px] font-bold leading-snug tracking-tight sm:text-[21px]">
                        Пасва ли на твоя компютър?
                    </h2>
                    <p class="mt-2 text-[13.5px] leading-relaxed text-ink-muted">
                        Кажи три неща и на страницата на всяка видеокарта, процесор, платка, памет и
                        захранване ще пише дали влиза при теб — или защо не. Без регистрация.
                    </p>
                </div>

                <form wire:submit="save" class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="text-xs font-medium text-ink-muted" for="home-pc-socket">{{ $short['socket'] }}</label>
                        <select id="home-pc-socket" wire:model="quick.socket" class="mt-1">
                            <option value="">—</option>
                            @foreach ($fields['socket']['options'] as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-ink-muted" for="home-pc-ram">{{ $short['ram_type'] }}</label>
                        <select id="home-pc-ram" wire:model="quick.ram_type" class="mt-1">
                            <option value="">—</option>
                            @foreach ($fields['ram_type']['options'] as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-ink-muted" for="home-pc-psu">{{ $short['psu_w'] }}</label>
                        <div class="relative mt-1">
                            <input id="home-pc-psu" type="number" inputmode="numeric"
                                   min="{{ $fields['psu_w']['min'] }}" max="{{ $fields['psu_w']['max'] }}"
                                   wire:model="quick.psu_w" class="pr-9">
                            <span class="pointer-events-none absolute inset-y-0 right-3 grid place-items-center
                                         font-mono text-xs text-ink-faint">W</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 sm:col-span-3">
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
                            Запази и проверявай
                        </button>
                        <a href="{{ route('my-pc') }}" wire:navigate
                           class="text-[12.5px] text-ink-muted transition hover:text-accent">Всички полета →</a>
                    </div>
                </form>
            </div>
        @else
            <div class="p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                    <div class="min-w-[220px] flex-1">
                        <p class="font-mono text-[10.5px] uppercase tracking-[0.18em] text-ink-faint">Моят компютър</p>
                        <h2 class="mt-2 text-[19px] font-bold leading-snug tracking-tight sm:text-[21px]">
                            Проверяваме по твоя компютър
                        </h2>
                        <p class="mt-1 text-[12.5px] text-ink-muted">
                            {{ $filled }} от {{ $total }} данни. На страниците на моделите и обявите пише дали влиза при теб.
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <a href="{{ route('my-pc') }}" wire:navigate class="btn-secondary btn-sm">
                            {{ $missing === [] ? 'Промени' : 'Добави още' }}
                        </a>
                        <button type="button" wire:click="forget" class="btn-secondary btn-sm"
                                wire:confirm="Да изтрием ли данните за компютъра?">Изчисти</button>
                    </div>
                </div>

                <ul class="mt-4 flex flex-wrap gap-1.5" aria-label="Данни за моя компютър">
                    @foreach ($pc as $key => $value)
                        <li class="whitespace-nowrap rounded-md border border-line bg-surface-alt px-2 py-1 text-[12px]">
                            <span class="text-ink-muted">{{ $short[$key] ?? $key }}</span>
                            <span class="ml-1 font-mono font-semibold">{{ $value }}{{ ($fields[$key]['unit'] ?? null) ? ' '.$fields[$key]['unit'] : '' }}</span>
                        </li>
                    @endforeach
                </ul>

                @if ($missing !== [])
                    <p class="hint mt-3">
                        За по-точен отговор липсва:
                        {{ collect($missing)->take(3)->map(fn ($k) => mb_strtolower($short[$k] ?? $k))->implode(', ') }}{{ count($missing) > 3 ? ' и още' : '' }}.
                    </p>
                @endif
            </div>
        @endif
    </div>
</section>
