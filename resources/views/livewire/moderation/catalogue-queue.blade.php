{{-- No raw PHP in this file, in either form — the parenthesised one-liner or
     the block. See the note at the top of browse-listings.blade.php for why the
     two together are dangerous, and why this note does not spell the directive
     out literally: comments are compiled after raw blocks are extracted, so a
     directive written inside one still counts. --}}

<div class="mx-auto max-w-5xl">

    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Каталог: какво пишат продавачите</h1>
            <p class="mt-1 text-sm text-ink-muted">
                Модели, които хората са въвели на ръка, защото ги няма в каталога.
                Всяко изписване, което закачиш, става синоним за търсачката.
            </p>
        </div>

        <label class="flex shrink-0 items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="showDismissed">
            Скритите
        </label>
    </header>

    @if ($problem)
        <p class="mt-4 rounded-md bg-bad-soft px-3 py-2 text-sm text-bad">{{ $problem }}</p>
    @endif

    {{-- ------------------------------------------------- the action bar --}}
    {{-- Appears only with something ticked. Merging several spellings into one
         promotion is the normal case here, not an advanced feature: the queue's
         raw material is one model typed four ways. --}}
    @if ($selected)
        <div class="card-pad mt-4 border-accent/40">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm">
                    Избрани <strong>{{ count($selected) }}</strong> изписвания
                    @if ($this->dominantCategory())
                        <span class="text-ink-faint">·</span>
                        {{ \App\Support\SpecFilter::categoryLabel($this->dominantCategory()) }}
                    @endif
                </p>

                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="startAttach" class="btn-primary btn-sm">
                        Закачи за съществуващ модел
                    </button>
                    <button type="button" wire:click="startCreate" class="btn-secondary btn-sm">
                        Създай нов модел
                    </button>
                    <button type="button" wire:click="$set('selected', [])" class="btn-ghost btn-sm">
                        Откажи
                    </button>
                </div>
            </div>

            {{-- ------------------------------------------------- attach --}}
            @if ($mode === 'attach')
                <div class="mt-4 border-t border-line pt-4">
                    <label class="label" for="part-search">Търси модел в каталога</label>
                    <input id="part-search" type="search" wire:model.live.debounce.400ms="partSearch"
                           placeholder="4070, tomahawk, 990 pro…" class="mt-2">
                    <p class="hint">
                        Повечето от тази опашка не са липсващи модели — просто някой е
                        написал „4070" вместо да избере от каталога.
                    </p>

                    @if ($this->partResults()->isNotEmpty())
                        <ul class="mt-3 divide-y divide-line border-t border-line">
                            @foreach ($this->partResults() as $candidate)
                                <li class="flex items-center justify-between gap-3 py-2">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium">
                                            {{ $candidate->fullName() }}
                                        </span>
                                        <span class="font-mono text-[11px] text-ink-faint">
                                            {{ $candidate->active_listings_count }} активни
                                            @unless ($candidate->is_published)
                                                <span class="text-warn">· непубликуван</span>
                                            @endunless
                                        </span>
                                    </span>
                                    <button type="button" wire:click="attach({{ $candidate->id }})"
                                            class="btn-primary btn-sm shrink-0">
                                        Закачи
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            {{-- ------------------------------------------------- create --}}
            @if ($mode === 'create')
                <div class="mt-4 space-y-4 border-t border-line pt-4">

                    {{-- The variant-level path: inherit a chipset row's specs and
                         change only what differs. Retyping eight fields is how a
                         spec sheet ends up disagreeing with itself. --}}
                    <div>
                        <label class="label" for="base-search">Наследи спецификации от съществуващ модел</label>
                        @if ($this->base())
                            <p class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                                <span class="badge-neutral">{{ $this->base()->fullName() }}</span>
                                <button type="button" wire:click="clearBase" class="link text-xs">махни</button>
                            </p>
                        @else
                            <input id="base-search" type="search" wire:model.live.debounce.400ms="baseSearch"
                                   placeholder="например GeForce RTX 4070" class="mt-2">
                            @if ($this->baseResults()->isNotEmpty())
                                <ul class="mt-2 space-y-1">
                                    @foreach ($this->baseResults() as $candidate)
                                        <li>
                                            <button type="button" wire:click="chooseBase({{ $candidate->id }})"
                                                    class="w-full rounded-md px-2 py-1.5 text-left text-sm
                                                           transition hover:bg-surface-alt hover:text-accent">
                                                {{ $candidate->fullName() }}
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <p class="hint">
                                По избор. За „ASUS TUF RTX 4070 OC" тръгни от 4070 и смени само размерите.
                            </p>
                        @endif
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label" for="cat">Категория</label>
                            <select id="cat" wire:model.live="category" class="mt-2">
                                <option value="">—</option>
                                @foreach ($categories as $c)
                                    <option value="{{ $c['key'] }}">{{ $c['label'] }}</option>
                                @endforeach
                            </select>
                            @error('category') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label" for="mfr">Производител</label>
                            <input id="mfr" type="text" wire:model.blur="manufacturer" class="mt-2">
                            @error('manufacturer') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label" for="model">Модел</label>
                            <input id="model" type="text" wire:model.blur="model" class="mt-2">
                            <p class="hint">Името, под което ще се търси. Без „продавам" и без удивителни.</p>
                            @error('model') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label" for="variant">Вариант</label>
                            <input id="variant" type="text" wire:model.blur="variant" class="mt-2"
                                   placeholder="TUF Gaming OC">
                            <p class="hint">Само ако редът е за конкретна версия на модела.</p>
                            @error('variant') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label" for="year">Година</label>
                            <input id="year" type="number" wire:model.blur="launchYear" class="mt-2 font-mono">
                            @error('launchYear') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if ($fields)
                        <div>
                            <h3 class="label">Спецификации на модела</h3>
                            <p class="hint">
                                Само това, което е еднакво за всеки екземпляр. Празно поле е
                                по-честно от познато — филтърът просто няма да го хваща.
                            </p>

                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                @foreach ($fields as $key => $spec)
                                    <div>
                                        <label class="label" for="spec-{{ $key }}">
                                            {{ $spec['label'][app()->getLocale()] ?? $spec['label']['en'] }}
                                            @if (! empty($spec['unit']))
                                                <span class="normal-case">({{ $spec['unit'] }})</span>
                                            @endif
                                        </label>

                                        @if ($spec['type'] === 'bool')
                                            <label class="mt-1 flex items-center gap-2 text-sm">
                                                <input type="checkbox" wire:model="specs.{{ $key }}"> да
                                            </label>
                                        @elseif ($spec['type'] === 'select')
                                            <select id="spec-{{ $key }}" wire:model="specs.{{ $key }}" class="mt-1">
                                                <option value="">—</option>
                                                @foreach ($spec['options'] ?? [] as $opt)
                                                    <option value="{{ $opt }}">{{ $opt }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <input id="spec-{{ $key }}"
                                                   type="{{ $spec['type'] === 'int' ? 'number' : 'text' }}"
                                                   wire:model.blur="specs.{{ $key }}" class="mt-1">
                                            @if ($spec['type'] === 'multiselect')
                                                <p class="hint">Няколко стойности, разделени със запетая.</p>
                                            @endif
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="publish">
                            Публикувай страницата на модела веднага
                        </label>

                        <button type="button" wire:click="create" class="btn-primary">
                            Създай и закачи обявите
                        </button>
                    </div>

                    <p class="hint">
                        Непубликуван модел пак групира обявите и пак работи за филтрите —
                        просто няма публична страница, докато не се допълни. Тънка страница
                        в каталога вреди повече, отколкото липсваща.
                    </p>
                </div>
            @endif
        </div>
    @endif

    {{-- ------------------------------------------------------- the queue --}}
    <div class="mt-6 space-y-2">
        @forelse ($clusters as $cluster)
            <div @class([
                'card-pad',
                'border-accent/50' => in_array($cluster['key'], $selected, true),
            ])>
                <div class="flex items-start gap-3">
                    <input type="checkbox" class="mt-1 shrink-0"
                           wire:click="toggle('{{ $cluster['key'] }}')"
                           @checked(in_array($cluster['key'], $selected, true))>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span class="font-semibold">{{ $cluster['sample'] }}</span>
                            <span class="font-mono text-xs text-ink-faint">
                                {{ $cluster['count'] }} {{ $cluster['count'] === 1 ? 'обява' : 'обяви' }}
                            </span>
                            @foreach ($cluster['categories'] as $category => $n)
                                <span class="badge-neutral">
                                    {{ \App\Support\SpecFilter::categoryLabel($category) }} {{ $n }}
                                </span>
                            @endforeach
                        </div>

                        {{-- Every spelling, because that is what the admin is
                             judging: four rows of noise or one model typed four
                             ways. Each one becomes a search alias on promotion. --}}
                        @if (count($cluster['spellings']) > 1)
                            <p class="mt-1.5 font-mono text-[11px] leading-relaxed text-ink-muted">
                                @foreach ($cluster['spellings'] as $spelling => $n)
                                    <span class="whitespace-nowrap">{{ $spelling }} ({{ $n }})</span>@if (! $loop->last) <span class="text-ink-faint">·</span> @endif
                                @endforeach
                            </p>
                        @endif

                        <p class="mt-1.5 truncate text-xs text-ink-faint">
                            {{ implode(' · ', $cluster['titles']) }}
                        </p>
                    </div>

                    <div class="shrink-0">
                        @if ($showDismissed)
                            <button type="button" wire:click="restore('{{ $cluster['key'] }}')"
                                    class="btn-ghost btn-sm">Върни</button>
                        @else
                            <button type="button" wire:click="dismiss('{{ $cluster['key'] }}')"
                                    class="btn-ghost btn-sm" title="Не е име на модел">Скрий</button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="card border-dashed p-12 text-center">
                @if ($showDismissed)
                    <p class="font-medium">Няма скрити изписвания.</p>
                @else
                    <p class="font-medium">Опашката е празна.</p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-ink-muted">
                        Всеки продавач досега е намирал модела си в каталога — което е
                        точно целта. Върни се, когато се появят нови обяви.
                    </p>
                @endif
            </div>
        @endforelse
    </div>
</div>
