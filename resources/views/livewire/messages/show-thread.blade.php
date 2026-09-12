@php
    $me = auth()->id();
@endphp

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
            с <a href="{{ route('profile', $other->username) }}" wire:navigate
                 class="link font-medium">{{ $other->username }}</a>
        </span>
    </a>

    {{-- ------------------------------------------------- where they stand

         A chat with no record of whether a price was agreed is how "ок, деал"
         becomes the only evidence a transaction ever happened. The offer and
         deal screens both knew this; the thread - the one place the two of
         them are actually talking - showed nothing, so the answer to "did we
         agree or not" lived in two people's memories.

         One card, three states, and in each one the next step is a link
         rather than a description of a link. --}}
    @if ($deal)
        <div @class([
            'mt-3 card p-3',
            'border-l-2 border-l-good' => $deal->status === \App\Enums\DealStatus::Completed,
            'border-l-2 border-l-accent' => $deal->status === \App\Enums\DealStatus::Open,
        ])>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="text-sm font-medium">
                    @if ($deal->status === \App\Enums\DealStatus::Completed)
                        Сделката е завършена
                    @else
                        Договорихте се
                    @endif
                </span>
                <span class="font-mono text-sm font-semibold tabular">{{ $deal->formattedAgreedPrice() }}</span>

                @if ($deal->status === \App\Enums\DealStatus::Open && $deal->expires_at)
                    @include('partials.deadline', [
                        'at'     => $deal->expires_at,
                        'prefix' => 'потвърждение,',
                        'done'   => $deal->confirmedBy($me),
                    ])
                @endif
            </div>

            <p class="mt-1.5 text-xs leading-relaxed text-ink-muted">
                @if ($deal->status === \App\Enums\DealStatus::Completed)
                    Можете да се оцените взаимно от „Сделки".
                @elseif ($deal->confirmedBy($me))
                    Ти потвърди. Чакаме {{ $other->username }}.
                @else
                    Потвърди сделката, след като получиш/предадеш стоката.
                @endif
                <a href="{{ route('deals') }}" wire:navigate class="link">Отвори „Сделки"</a>
            </p>
        </div>
    @elseif ($offer)
        @php $yourMove = $offer->awaitsResponseFrom($me); @endphp

        <div @class(['mt-3 card p-3', 'border-l-2 border-l-accent' => $yourMove])>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="text-sm font-medium">
                    {{ $offer->is_counter ? 'Насрещна оферта' : 'Има оферта' }}
                </span>
                <span class="font-mono text-sm font-semibold tabular">{{ $offer->formattedAmount() }}</span>
                @include('partials.deadline', [
                    'at'     => $offer->expires_at,
                    'prefix' => 'изтича,',
                ])
            </div>

            <p class="mt-1.5 text-xs leading-relaxed text-ink-muted">
                {{ $yourMove ? 'Чака твоя отговор.' : 'Чака отговор от '.$other->username.'.' }}
                <a href="{{ route('offers') }}" wire:navigate class="link">Отвори „Оферти"</a>
            </p>
        </div>
    @endif

    {{-- The masking rule made visible. Without saying this, a stripped message
         reads as the app being broken rather than the app doing something
         deliberate. --}}
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

    {{-- ------------------------------------------------------- messages

         Bounded and scrolled to the newest, rather than growing down the page
         forever. A thread that runs off the bottom pushes the compose box -
         and the warning above it - past the fold, so the longer a negotiation
         runs the less likely either side is to see the one paragraph on this
         screen that stops people getting robbed. --}}
    <div class="mt-4 max-h-[55vh] space-y-2 overflow-y-auto pr-1"
         x-data
         x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
         x-on:scroll-to-latest.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
        @php $lastDay = null; @endphp

        @forelse ($messages as $message)
            @php
                $mine = $message->sender_id === $me;
                $day  = $message->created_at->toDateString();
            @endphp

            {{-- "14:32" with no date is a lie by omission once a negotiation
                 spans two days, and these routinely do. --}}
            @if ($day !== $lastDay)
                @php $lastDay = $day; @endphp
                <p class="sticky top-0 z-10 py-1 text-center text-[11px] text-ink-faint">
                    <span class="rounded-full bg-canvas px-2 py-0.5">
                        @if ($message->created_at->isToday())
                            днес
                        @elseif ($message->created_at->isYesterday())
                            вчера
                        @else
                            {{ $message->created_at->format('d.m.Y') }}
                        @endif
                    </span>
                </p>
            @endif

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
                        {{ $message->created_at->format('H:i') }}
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
        {{-- Above the box, not under it.

             This was the last paragraph on the page, below the send button,
             in the faintest text available - which is to say it was placed
             exactly where nobody reads, and it is the single most valuable
             sentence on the screen. Advance payment is how people get robbed
             on a used marketplace, and this is the moment they are about to
             agree to one. --}}
        <p class="mt-4 rounded-md border border-warn/30 bg-warn-soft px-3 py-2 text-xs leading-relaxed text-warn">
            Не плащай предварително и не изпращай капаро. Използвай „преглед и тест"
            при куриера — плащаш, след като видиш стоката.
        </p>

        <form wire:submit="send" class="mt-2">
            <textarea wire:model="body" rows="3" maxlength="2000"
                      placeholder="Съобщение до {{ $other->username }}…"
                      class="min-h-20"></textarea>
            @error('body') <p class="error">{{ $message }}</p> @enderror

            <div class="mt-2 flex items-center justify-end gap-3">
                {{-- The offer system is the only haggling this site has, and
                     the thread is where someone types a number at the other
                     person instead. Offered here, next to the box they were
                     about to do it in. --}}
                @if (! $deal && ! $offer && $thread->listing->offers_enabled && $thread->buyer_id === $me)
                    <a href="{{ route('listing', $thread->listing) }}" wire:navigate
                       class="text-xs text-ink-muted underline-offset-2 hover:text-accent hover:underline">
                        Прати оферта вместо това
                    </a>
                @endif

                <button type="submit" class="btn-primary btn-sm">
                    <span wire:loading.remove wire:target="send">Изпрати</span>
                    <span wire:loading wire:target="send">Изпращам…</span>
                </button>
            </div>
        </form>
    @endif
</div>
