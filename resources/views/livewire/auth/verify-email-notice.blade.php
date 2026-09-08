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

    <div class="card-pad mt-6">
        <p class="text-sm font-medium">Докато не потвърдиш, не можеш да:</p>
        <ul class="mt-2 space-y-1 text-sm text-ink-muted">
            <li>· публикуваш обява</li>
            <li>· изпращаш оферти</li>
            <li>· пишеш съобщения</li>
        </ul>
        <p class="hint">Разглеждането на обяви остава свободно.</p>
    </div>

    <p class="hint mt-4">
        Ако писмото не пристигне, провери папката със спам.
        В режим за разработка писмата отиват в
        <code class="font-mono">storage/logs/laravel.log</code>, не в пощата.
    </p>
</div>
