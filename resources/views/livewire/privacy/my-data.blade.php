<div class="mx-auto max-w-2xl space-y-6">

    <div>
        <h1 class="text-xl font-semibold tracking-tight">Моите данни</h1>
        <p class="hint mt-1">
            Можеш да свалиш всичко, което пазим за теб, и да изтриеш профила си —
            без да пишеш на поддръжката.
        </p>
    </div>

    {{-- --- Art. 15 / 20: a copy ------------------------------------------ --}}
    <div class="card-pad space-y-3">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-ink-muted">Свали данните си</h2>

        <p class="text-sm text-ink-muted">
            Архив с профила, обявите, офертите, сделките, съобщенията, оценките,
            кредита и фактурите ти — плюс снимките, които си качил.
        </p>

        @if ($export && $export->status === \App\Models\DataExport::PENDING)
            <p class="rounded-md bg-surface-alt p-3 text-sm">
                Подготвяме архива. Ще получиш известие, когато е готов — обикновено до няколко минути.
            </p>
        @elseif ($export && $export->isReady())
            <div class="flex flex-wrap items-center gap-3 rounded-md border border-line bg-good-soft p-3">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-good">Готово · {{ $export->formattedSize() }}</p>
                    <p class="text-xs text-good">
                        Изтича {{ $export->expires_at->diffForHumans() }} и се изтрива автоматично.
                    </p>
                </div>
                <button type="button" wire:click="download('{{ $export->uuid }}')"
                        class="btn-primary btn-sm shrink-0">Свали</button>
            </div>
        @elseif ($export && $export->status === \App\Models\DataExport::FAILED)
            {{-- A failed export SAYS so. Left looking pending, the user waits and
                 then writes to support — the exact outcome this page exists to
                 prevent. --}}
            <p class="error">
                Нещо се обърка при подготовката. Опитай пак — ако пак не стане, пиши ни от Поддръжка.
            </p>
        @endif

        @if (! $export || ! in_array($export->status, [\App\Models\DataExport::PENDING, \App\Models\DataExport::READY], true) || $export->hasExpired())
            <button type="button" wire:click="requestExport" class="btn-secondary btn-sm"
                    wire:loading.attr="disabled">
                Подготви архив
            </button>
        @endif

        <p class="hint">
            Файлът се пази {{ $hours }} часа. Пази го като лична карта — вътре е всичко наведнъж.
        </p>
    </div>

    {{-- --- Art. 17: erasure ---------------------------------------------- --}}
    <div class="card-pad space-y-3">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-ink-muted">Изтриване на профила</h2>

        @if (auth()->user()->deletion_requested_at)
            @php($goesOn = auth()->user()->deletion_requested_at->copy()->addDays($graceDays))

            <div class="rounded-md border border-line bg-warn-soft p-3">
                <p class="text-sm font-medium text-warn">
                    Профилът ти е скрит и се изтрива на {{ $goesOn->format('d.m.Y') }}.
                </p>
                <p class="mt-1 text-xs leading-relaxed text-warn">
                    Обявите ти са свалени. Дотогава можеш да спреш изтриването — след това не.
                </p>
            </div>

            <button type="button" wire:click="undoDelete" class="btn-primary btn-sm">
                Спри изтриването
            </button>
        @else
            {{-- Said BEFORE the button, not in a dialog after it. What survives an
                 erasure is the part people are surprised by, and being surprised
                 afterwards is what turns a feature into a complaint. --}}
            <p class="text-sm text-ink-muted">Какво се случва:</p>

            <ul class="space-y-1.5 text-sm text-ink-muted">
                <li>· Профилът ти изчезва веднага и обявите ти се свалят.</li>
                <li>· След {{ $graceDays }} дни изтриваме името, имейла, телефона, адресите и снимките ти.</li>
                <li>· <strong class="text-ink">Оценките и сделките остават</strong> — те са и на отсрещната
                      страна, и нейната репутация не бива да изчезва, защото ти си си тръгнал.
                      Показват се като „изтрит профил".</li>
                <li>· <strong class="text-ink">Фактурите остават</strong> — законът ги изисква пет години
                      (чл. 38 ДОПК). Не са наши, за да ги трием.</li>
                <li>· Съобщенията остават в разговорите, в които си ги писал.</li>
            </ul>

            @if ($blockers)
                @foreach ($blockers as $blocker)
                    <p class="rounded-md bg-warn-soft p-3 text-sm text-warn">{{ $blocker }}</p>
                @endforeach
            @elseif ($confirming)
                <div class="rounded-md border border-line bg-surface-alt p-3">
                    <label class="label" for="confirm">
                        Напиши „{{ auth()->user()->username }}", за да потвърдиш
                    </label>
                    <input id="confirm" type="text" wire:model="confirmUsername" class="mt-1" autocomplete="off">
                    @error('confirmUsername') <p class="error">{{ $message }}</p> @enderror

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="confirmDelete" class="btn-primary btn-sm">
                            Изтрий профила ми
                        </button>
                        <button type="button" wire:click="cancelDelete" class="btn-ghost btn-sm">Назад</button>
                    </div>
                </div>
            @else
                <button type="button" wire:click="startDelete" class="btn-secondary btn-sm">
                    Изтрий профила ми
                </button>
            @endif
        @endif
    </div>

    <p class="hint">
        Повече за това какво пазим и защо — в <a href="{{ route('legal.privacy') }}" wire:navigate
        class="text-accent hover:underline">Политиката за поверителност</a>.
    </p>
</div>
