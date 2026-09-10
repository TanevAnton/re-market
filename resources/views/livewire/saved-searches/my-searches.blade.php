<div class="mx-auto max-w-3xl">

    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight">Запазени търсения</h1>
        <a href="{{ route('favorites') }}" wire:navigate class="link text-sm">Запазени обяви →</a>
    </div>

    @if (! $rows)
        <div class="card-pad mt-6 text-center">
            <p class="text-sm font-medium">Още нямаш запазени търсения.</p>
            <p class="hint mx-auto mt-2 max-w-md">
                Задай филтри в обявите и натисни „Запази търсенето“. Ще получаваш
                съобщение при всяка нова обява, която отговаря — обявите за търсени
                модели се разграбват за часове.
            </p>
            <a href="{{ route('browse') }}" wire:navigate class="btn-primary mt-4 inline-block">
                Към обявите
            </a>
        </div>
    @else
        <ul class="mt-6 space-y-3">
            @foreach ($rows as $row)
                @php($search = $row['model'])

                <li class="card-pad" wire:key="search-{{ $search->id }}">
                    @if ($renaming === $search->id)
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="min-w-0 flex-1">
                                <label class="label" for="name-{{ $search->id }}">Име</label>
                                <input id="name-{{ $search->id }}" type="text" wire:model="name"
                                       wire:keydown.enter="rename" class="mt-1 w-full">
                                @error('name') <p class="error mt-1">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="rename" class="btn-primary">Запази</button>
                            <button type="button" wire:click="cancelRename" class="btn-ghost">Откажи</button>
                        </div>
                    @else
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ $row['url'] }}" wire:navigate
                                   class="text-sm font-semibold hover:text-accent">
                                    {{ $search->name }}
                                </a>
                                <p class="mt-1 font-mono text-[11px] text-ink-muted">{{ $row['summary'] }}</p>

                                <p class="hint mt-1.5">
                                    @if (! $search->notify)
                                        Известията са спрени.
                                    @elseif ($search->last_notified_at)
                                        Последно известие {{ $search->last_notified_at->diffForHumans() }}.
                                    @else
                                        Още няма изпратено известие.
                                    @endif
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                <button type="button" wire:click="toggleNotify({{ $search->id }})"
                                        @class([
                                            'btn-ghost btn-sm',
                                            'text-accent' => $search->notify,
                                        ])
                                        title="{{ $search->notify ? 'Спри известията' : 'Пусни известията' }}">
                                    {{ $search->notify ? '🔔 вкл.' : '🔕 изкл.' }}
                                </button>
                                <button type="button" wire:click="startRename({{ $search->id }})"
                                        class="btn-ghost btn-sm">преименувай</button>
                                <button type="button" wire:click="delete({{ $search->id }})"
                                        class="btn-ghost btn-sm text-bad">изтрий</button>
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        <p class="hint mt-6">
            Проверяваме за нови обяви на всеки час. Известията идват в Telegram, ако
            си потвърдил телефона си, иначе на имейл — според настройките ти в
            <a href="{{ route('profile.edit') }}" wire:navigate class="link">профила</a>.
        </p>
    @endif
</div>
