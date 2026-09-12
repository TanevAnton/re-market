@php
    $me = auth()->id();
    $unreadThreads = collect($unread)->filter()->count();
@endphp

<div class="mx-auto max-w-3xl">

    <div class="flex flex-wrap items-baseline gap-3">
        <h1 class="text-2xl font-semibold tracking-tight">Съобщения</h1>
        @if ($unreadThreads)
            <span class="badge-accent font-mono">{{ $unreadThreads }} непрочетени</span>
        @endif
    </div>

    @if ($threads->isEmpty())
        {{-- Two different people land here empty: someone who has never asked
             anyone anything, and someone who has listed something nobody has
             asked about yet. The first needs listings to look at; the second
             needs the reassurance that the silence is normal and not a bug. --}}
        <div class="card border-dashed p-12 text-center mt-6">
            <p class="font-medium">Още нямаш разговори</p>
            <p class="mx-auto mt-1 max-w-md text-sm text-ink-muted">
                Пишеш на продавач от самата обява. Купувачите ти пишат оттам същото.
            </p>
            <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                <a href="{{ route('browse') }}" wire:navigate class="btn-primary">Разгледай обявите</a>
                <a href="{{ route('listing.create') }}" wire:navigate class="btn-ghost">Публикувай обява</a>
            </div>
        </div>
    @endif

    <div class="mt-4 space-y-2">
        @foreach ($threads as $thread)
            @php
                $other = $thread->counterparty(auth()->user());
                $last  = $thread->messages->first();
                $n     = $unread[$thread->id] ?? 0;
            @endphp

            {{-- Unread was a badge and nothing else, so a list of twelve rows
                 that look identical made you hunt for the one small green pill.
                 An accent edge and a solid-weight preview line make the row
                 itself the thing that stands out, which is what you are
                 actually scanning for. --}}
            <a href="{{ route('thread', $thread) }}" wire:navigate
               @class([
                   'card flex gap-3 p-3 transition hover:border-accent',
                   'border-l-2 border-l-accent bg-surface-alt' => $n > 0,
               ])>

                <div class="h-14 w-16 shrink-0 overflow-hidden rounded-md bg-surface-alt">
                    @if ($cover = $thread->listing->images->first())
                        <img src="{{ $cover->thumbUrl() }}" alt="" class="h-full w-full object-cover">
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2">
                        <span @class(['truncate text-sm', $n > 0 ? 'font-semibold' : 'font-medium'])>
                            {{ $other->username }}
                        </span>
                        @if ($n)
                            <span class="badge-accent font-mono">{{ $n }}</span>
                        @endif
                        @if ($thread->is_locked)
                            <span class="badge-neutral">заключен</span>
                        @endif
                        <span class="ml-auto shrink-0 text-xs {{ $n > 0 ? 'text-ink-muted' : 'text-ink-faint' }}">
                            {{ $thread->last_message_at?->diffForHumans() }}
                        </span>
                    </div>

                    <p class="truncate text-xs text-ink-muted">{{ $thread->listing->title }}</p>

                    <p @class([
                        'mt-1 truncate text-sm',
                        'text-ink font-medium' => $n > 0,
                        'text-ink-muted' => $n === 0,
                    ])>
                        @if ($last)
                            @if ($last->sender_id === $me)
                                <span class="font-normal text-ink-faint">Ти:</span>
                            @endif
                            {{ $last->visibleBody() }}
                        @else
                            <span class="italic text-ink-faint">Няма съобщения</span>
                        @endif
                    </p>
                </div>
            </a>
        @endforeach
    </div>
</div>
