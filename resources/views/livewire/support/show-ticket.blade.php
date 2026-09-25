<div class="mx-auto max-w-2xl">

    <a href="{{ route('support') }}" wire:navigate class="link text-sm">← Поддръжка</a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-bold tracking-tight">{{ $ticket->subject }}</h1>
            <p class="hint mt-1">
                <span class="font-mono">{{ $ticket->reference }}</span>
                · {{ $ticket->topic->label() }}
                · отворено {{ $ticket->created_at->format('d.m.Y') }}
            </p>
        </div>

        <span @class([
            'shrink-0',
            'badge-good'    => $ticket->status === \App\Enums\TicketStatus::Answered,
            'badge-neutral' => $ticket->status !== \App\Enums\TicketStatus::Answered,
        ])>{{ $ticket->status->label() }}</span>
    </div>

    {{-- The conversation. Staff messages sit on the accent side so the two
         voices are distinguishable at a glance without a label being read —
         which is what makes a long thread scannable. --}}
    <div class="mt-6 space-y-3">
        @foreach ($messages as $message)
            <div @class([
                'card-pad',
                'border-accent/40' => $message->from_staff,
            ]) wire:key="msg-{{ $message->id }}">
                <p class="flex flex-wrap items-baseline justify-between gap-2">
                    <span @class([
                        'text-sm font-semibold',
                        'text-accent' => $message->from_staff,
                    ])>{{ $message->authorLabel() }}</span>

                    <span class="hint font-mono">{{ $message->created_at->format('d.m.Y H:i') }}</span>
                </p>

                {{-- Escaped and whitespace-preserved. A support thread carries
                     pasted error messages and log lines, and those need their
                     line breaks — but nothing in here is ever rendered as
                     markup. --}}
                <p class="mt-2 whitespace-pre-line break-words text-sm leading-relaxed">{{ $message->body }}</p>
            </div>
        @endforeach
    </div>

    {{-- ── Reply ────────────────────────────────────────────────────────── --}}
    @if ($ticket->status->isOpen())
        <form wire:submit="reply" class="card-pad mt-4">
            <label class="label" for="reply">Добави</label>
            <textarea id="reply" wire:model="body" rows="4" class="mt-1"
                      placeholder="Отговори тук — така всичко остава в едно място."></textarea>
            @error('body') <p class="error">{{ $message }}</p> @enderror

            <button type="submit" class="btn-primary btn-sm mt-3" wire:loading.attr="disabled">
                Изпрати
            </button>
        </form>
    @else
        <div class="card-pad mt-4 text-center">
            <p class="text-sm text-ink-muted">
                Запитването е затворено{{ $ticket->closed_at ? ' на '.$ticket->closed_at->format('d.m.Y') : '' }}.
            </p>
            <a href="{{ route('support') }}" wire:navigate class="btn-secondary btn-sm mt-3 inline-block">
                Отвори ново
            </a>
        </div>
    @endif

    {{-- Said once, at the bottom, where somebody about to paste a password
         will be looking. --}}
    <p class="hint mt-6">
        Никога не пращай пароли. Поддръжката не ги иска и не ги вижда.
    </p>
</div>
