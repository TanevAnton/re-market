<div class="mx-auto max-w-2xl">

    {{-- ------------------------------------------------- listing header --}}
    <a href="{{ route('listing', $thread->listing) }}" wire:navigate
       class="card flex items-center gap-3 p-3 transition hover:border-accent">
        <div class="h-12 w-14 shrink-0 overflow-hidden rounded-md bg-surface-alt">
            @if ($cover = $thread->listing->images->first())
                <img src="{{ $cover->thumbUrl() }}" alt="" class="h-full w-full object-cover">
            @endif
        </div>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium">{{ $thread->listing->title }}</p>
            <p class="font-mono text-xs text-ink-muted">{{ $thread->listing->formattedPrice() }}</p>
        </div>
        <span class="shrink-0 text-xs text-ink-muted">
            с <span class="font-medium">{{ $other->username }}</span>
        </span>
    </a>

    {{-- The rule made visible. Without saying this, a masked message reads as
         the app being broken rather than the app doing something deliberate. --}}
    @if ($contactsOpen)
        <p class="mt-3 rounded-md border border-line bg-good-soft px-3 py-2 text-xs leading-relaxed text-good">
            Имате уговорена сделка — телефони и адреси вече минават свободно.
        </p>
    @else
        <p class="mt-3 rounded-md border border-line bg-surface-alt px-3 py-2 text-xs leading-relaxed text-ink-muted">
            Телефони, имейли и линкове се скриват, докато не приемете оферта.
            Така сделката остава в платформата и има следа, ако нещо се обърка.
        </p>
    @endif

    {{-- ------------------------------------------------------- messages --}}
    <div class="mt-4 space-y-2">
        @forelse ($messages as $message)
            @php $mine = $message->sender_id === auth()->id(); @endphp

            <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                <div @class([
                    'max-w-[80%] rounded-lg px-3 py-2',
                    'bg-accent text-[var(--accent-ink)]' => $mine,
                    'border border-line bg-surface' => ! $mine,
                ])>
                    <p class="whitespace-pre-line text-sm leading-relaxed">{{ $message->visibleBody() }}</p>
                    <p @class([
                        'mt-1 text-[10px]',
                        'opacity-70' => $mine,
                        'text-ink-faint' => ! $mine,
                    ])>
                        {{ $message->created_at->format('d.m H:i') }}
                        {{-- Only the sender is told their own message was masked;
                             the recipient never sees that anything was removed. --}}
                        @if ($mine && $message->had_contact_info && ! $contactsOpen)
                            · контактите са скрити
                        @endif
                    </p>
                </div>
            </div>
        @empty
            <p class="py-8 text-center text-sm text-ink-muted">
                Няма съобщения. Напиши първото.
            </p>
        @endforelse
    </div>

    {{-- --------------------------------------------------------- compose --}}
    @if ($thread->is_locked)
        <p class="error mt-4">Разговорът е заключен от модератор.</p>
    @else
        <form wire:submit="send" class="mt-4">
            <textarea wire:model="body" rows="3" maxlength="2000"
                      placeholder="Съобщение до {{ $other->username }}…"
                      class="min-h-20"></textarea>
            @error('body') <p class="error">{{ $message }}</p> @enderror

            <div class="mt-2 flex justify-end">
                <button type="submit" class="btn-primary btn-sm">
                    <span wire:loading.remove wire:target="send">Изпрати</span>
                    <span wire:loading wire:target="send">Изпращам…</span>
                </button>
            </div>
        </form>
    @endif

    <p class="mt-6 text-xs leading-relaxed text-ink-faint">
        Не плащай предварително и не изпращай капаро. Използвай „преглед и тест"
        при куриера — плащаш след като видиш стоката.
    </p>
</div>
