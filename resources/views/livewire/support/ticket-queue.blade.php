<div class="mx-auto max-w-4xl">

    <h1 class="text-2xl font-bold tracking-tight">Запитвания</h1>
    <p class="hint mt-1">
        Най-старите са най-горе — най-дълго чакащото е това, по което човекът
        вече се е отказал.
    </p>

    <div class="mt-4 flex flex-wrap gap-1 border-b border-line">
        @foreach ($this->tabs() as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition',
                        'border-accent text-ink' => $tab === $key,
                        'border-transparent text-ink-muted hover:text-ink' => $tab !== $key,
                    ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($tickets->isEmpty())
        <div class="card-pad mt-6 text-center">
            <p class="text-sm text-ink-muted">Няма запитвания в този раздел.</p>
        </div>
    @endif

    <div class="mt-6 space-y-3">
        @foreach ($tickets as $ticket)
            @php $first = $ticket->messages->first(); @endphp

            <div class="card-pad" wire:key="ticket-{{ $ticket->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            {{ $ticket->subject }}

                            @if ($ticket->unreadForStaff())
                                <span class="badge-accent">чака отговор</span>
                            @endif

                            <span class="badge-neutral">{{ $ticket->status->label() }}</span>
                        </p>

                        <p class="hint mt-1">
                            <span class="font-mono">{{ $ticket->reference }}</span>
                            · {{ $ticket->topic->label() }}
                            · {{ $ticket->user?->username ?? 'гост' }}
                            · {{ $ticket->email }}
                            · {{ $ticket->created_at->diffForHumans() }}
                        </p>

                        {{-- The first message inline: the whole point of the
                             queue is deciding what to pick up, and a list of
                             subject lines is not enough to decide with. --}}
                        @if ($first)
                            <p class="mt-2 line-clamp-3 whitespace-pre-line break-words text-sm text-ink-muted">{{ $first->body }}</p>
                        @endif

                        <p class="mt-2 text-xs">
                            <a href="{{ route('support.ticket', $ticket) }}" wire:navigate class="link">
                                Целият разговор ({{ $ticket->messages->count() }})
                            </a>
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-2">
                        @if ($ticket->status->isOpen())
                            <button type="button" wire:click="openReply({{ $ticket->id }})"
                                    class="btn-secondary btn-sm">Отговори</button>
                            <button type="button" wire:click="close({{ $ticket->id }})"
                                    class="btn-ghost btn-sm">Затвори</button>
                        @else
                            <button type="button" wire:click="reopen({{ $ticket->id }})"
                                    class="btn-ghost btn-sm">Отвори отново</button>
                        @endif
                    </div>
                </div>

                @if ($replyingTo === $ticket->id)
                    <div class="mt-4 border-t border-line pt-4">
                        <label class="label" for="reply-{{ $ticket->id }}">
                            Отговор — изпраща се на {{ $ticket->email }}
                        </label>
                        <textarea id="reply-{{ $ticket->id }}" wire:model="body" rows="5" class="mt-1"></textarea>
                        @error('body') <p class="error">{{ $message }}</p> @enderror

                        <label class="mt-3 flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="closeWithReply">
                            Затвори запитването с този отговор
                        </label>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="send" class="btn-primary btn-sm"
                                    wire:loading.attr="disabled">Изпрати</button>
                            <button type="button" wire:click="cancelReply" class="btn-ghost btn-sm">Откажи</button>
                        </div>

                        <p class="hint mt-2">
                            Отговорът влиза и в самия имейл, не само като връзка —
                            повечето хора четат в пощата си и не цъкат.
                        </p>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-6">{{ $tickets->links() }}</div>
</div>
