<div class="mx-auto max-w-3xl">

    <h1 class="text-2xl font-semibold tracking-tight">Съобщения</h1>

    @if ($threads->isEmpty())
        <div class="card-pad mt-6 text-center">
            <p class="text-sm text-ink-muted">Още нямаш разговори.</p>
            <a href="{{ route('browse') }}" wire:navigate class="btn-secondary btn-sm mt-4">Разгледай обявите</a>
        </div>
    @endif

    <div class="mt-4 space-y-2">
        @foreach ($threads as $thread)
            @php
                $other  = $thread->counterparty(auth()->user());
                $last   = $thread->messages->first();
                $unread = $thread->unreadCountFor(auth()->user());
            @endphp

            <a href="{{ route('thread', $thread) }}" wire:navigate
               class="card flex gap-3 p-3 transition hover:border-accent">

                <div class="h-14 w-16 shrink-0 overflow-hidden rounded-md bg-surface-alt">
                    @if ($cover = $thread->listing->images->first())
                        <img src="{{ $cover->thumbUrl() }}" alt="" class="h-full w-full object-cover">
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2">
                        <span class="truncate text-sm font-medium">{{ $other->username }}</span>
                        @if ($unread)
                            <span class="badge-accent font-mono">{{ $unread }}</span>
                        @endif
                        <span class="ml-auto shrink-0 text-xs text-ink-faint">
                            {{ $thread->last_message_at?->diffForHumans() }}
                        </span>
                    </div>

                    <p class="truncate text-xs text-ink-muted">{{ $thread->listing->title }}</p>

                    <p class="mt-1 truncate text-sm {{ $unread ? 'text-ink' : 'text-ink-muted' }}">
                        @if ($last)
                            @if ($last->sender_id === auth()->id())
                                <span class="text-ink-faint">Ти:</span>
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
