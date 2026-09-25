<div class="mx-auto max-w-3xl">

    <h1 class="text-2xl font-bold tracking-tight">Поддръжка</h1>
    <p class="mt-1 text-sm text-ink-muted">
        Питай за всичко около профила, обявите и плащанията. Отговаряме на
        имейла, който оставиш.
    </p>

    {{-- ── Where OTHER things go ────────────────────────────────────────────
         ABOVE THE FORM, NOT BELOW IT. A DSA notice filed as a support ticket
         loses its clock, its statement of reasons and its appeal — and nobody
         finds out for months. The two routes that are not this one are named
         before the person starts typing, because after they have typed it they
         will send it here regardless of what the footnote says. --}}
    <div class="card-pad mt-6">
        <p class="text-sm font-semibold">Първо — това не е мястото за:</p>

        <ul class="mt-2 space-y-2 text-sm text-ink-muted">
            <li>
                <strong class="text-ink">Измама или незаконно съдържание.</strong>
                Подава се като сигнал, който се разглежда по правилата на
                Регламента за цифровите услуги и получава решение с мотиви —
                <a href="{{ route('legal.notice') }}" wire:navigate class="link">виж как</a>.
            </li>
            <li>
                <strong class="text-ink">Проблем с конкретна обява или продавач.</strong>
                Използвай бутона „Подай сигнал" в самата обява — така стига до
                модератор заедно с обявата, а не като описание на нея.
            </li>
            <li>
                <strong class="text-ink">Спор по сделка.</strong>
                Сделките се уговарят пряко между потребителите и платформата не
                е страна по договора. Пиши на продавача от
                <a href="{{ route('messages') }}" wire:navigate class="link">Съобщения</a>.
            </li>
        </ul>
    </div>

    {{-- ── Sent ─────────────────────────────────────────────────────────── --}}
    @if ($reference)
        <div class="card-pad mt-6 border-good/40">
            <p class="text-sm font-semibold text-good">Запитването е изпратено.</p>
            <p class="mt-1 text-sm">
                Номер: <strong class="font-mono">{{ $reference }}</strong>
            </p>
            <p class="hint mt-2">
                @auth
                    Отговорът се появява в
                    <a href="{{ route('support') }}" wire:navigate class="link">запитванията ти</a>
                    и идва и на имейл.
                @else
                    Отговорът идва на {{ $email }}, с връзка към разговора.
                    Пази имейла — през него се връщаш в запитването.
                @endauth
            </p>
        </div>
    @endif

    {{-- ── My tickets ───────────────────────────────────────────────────── --}}
    @if ($mine->isNotEmpty())
        <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-muted">
            Моите запитвания
        </h2>

        <div class="card mt-3 divide-y divide-line overflow-hidden">
            @foreach ($mine as $ticket)
                <a href="{{ route('support.ticket', $ticket) }}" wire:navigate
                   class="flex flex-wrap items-center justify-between gap-3 p-4 hover:bg-surface-alt"
                   wire:key="mine-{{ $ticket->id }}">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                            {{ $ticket->subject }}

                            {{-- The one badge that means „it is your turn". --}}
                            @if ($ticket->unreadForUser())
                                <span class="badge-accent">нов отговор</span>
                            @endif
                        </p>
                        <p class="hint mt-0.5">
                            <span class="font-mono">{{ $ticket->reference }}</span>
                            · {{ $ticket->topic->label() }}
                            · {{ $ticket->created_at->format('d.m.Y') }}
                        </p>
                    </div>

                    <span @class([
                        'shrink-0',
                        'badge-good'    => $ticket->status === \App\Enums\TicketStatus::Answered,
                        'badge-neutral' => $ticket->status !== \App\Enums\TicketStatus::Answered,
                    ])>{{ $ticket->status->label() }}</span>
                </a>
            @endforeach
        </div>
    @endif

    {{-- ── The form ─────────────────────────────────────────────────────── --}}
    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-ink-muted">Ново запитване</h2>

    <form wire:submit="submit" class="card-pad mt-3 space-y-4">

        <div>
            <label class="label" for="topic">За какво се отнася</label>
            <select id="topic" wire:model="topic" class="mt-1">
                {{-- A disabled placeholder, because a select whose bound value
                     matches no option shows the FIRST one — and an untouched
                     field that claims to have been answered is a trap this
                     codebase has already fallen into once. --}}
                <option value="" disabled>Избери…</option>
                @foreach ($topics as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('topic') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="subject">Накратко</label>
            <input id="subject" type="text" wire:model="subject" class="mt-1"
                   placeholder="Например: не мога да добавя снимки към обява">
            @error('subject') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="body">Подробно</label>
            <textarea id="body" wire:model="body" rows="6" class="mt-1"
                      placeholder="Какво направи, какво очакваше да стане и какво стана вместо това."></textarea>
            <p class="hint">
                Ако има съобщение за грешка, цитирай го. Не пращай пароли.
            </p>
            @error('body') <p class="error">{{ $message }}</p> @enderror
        </div>

        @guest
            <div>
                <label class="label" for="email">Имейл за отговор</label>
                <input id="email" type="email" wire:model="email" class="mt-1">
                <p class="hint">
                    Не е нужен профил. Ако имаш такъв, влез — така отговорът се
                    появява и в сайта.
                </p>
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
        @endguest

        <x-turnstile />
        @error('turnstile') <p class="error">{{ $message }}</p> @enderror

        <div class="border-t border-line pt-4">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">
                Изпрати
            </button>
        </div>
    </form>

    {{-- The address itself, spelled out. Some people will always rather use
         their own mail client than a form on a site they do not know yet, and
         refusing them a mailbox to write to costs a real question. --}}
    <p class="hint mt-6">
        Предпочиташ имейл? Пиши на
        <a href="mailto:{{ config('legal.contact.users') }}" class="link">{{ config('legal.contact.users') }}</a>.
        За въпроси от органи и по защита на данните:
        <a href="mailto:{{ config('legal.contact.authorities') }}" class="link">{{ config('legal.contact.authorities') }}</a>.
    </p>
</div>
