<div class="mx-auto max-w-md">
    <h1 class="text-xl font-semibold tracking-tight">Потвърди имейла си</h1>
    <p class="mt-2 text-sm text-ink-muted">
        Изпратихме линк на <strong class="text-ink">{{ auth()->user()->email }}</strong>.
        Отвори го, за да потвърдиш адреса.
    </p>

    @if (session('status'))
        <p class="mt-4 rounded-md bg-good-soft px-3 py-2 text-sm text-good">{{ session('status') }}</p>
    @endif

    <button type="button" wire:click="resend" class="btn-secondary mt-5">
        Изпрати линка отново
    </button>

    <p class="hint mt-4">
        В режим за разработка писмата отиват в <code class="font-mono">storage/logs/laravel.log</code>,
        не в пощата.
    </p>
</div>
