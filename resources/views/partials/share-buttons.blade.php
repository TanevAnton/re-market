{{-- Share a listing.

     Bulgarian buying and selling happens in Viber and Messenger groups far more
     than it happens in e-mail, so those come first; the phone's own share sheet
     leads wherever the browser offers one, because it already knows which apps
     the person has. Everything here is a plain link or a click handler: no
     third-party script, no tracker, nothing that loads until it is pressed.

     Takes $url (absolute address of the page) and $title. The text sent is the
     title and the address and nothing else - no price, which goes stale the day
     it is dropped, and which the page itself will show.

     (No Blade directives are named in this comment on purpose.) --}}
<div x-data="{
        url: @js($url),
        title: @js($title),
        copied: false,
        canShare: typeof navigator !== 'undefined' && typeof navigator.share === 'function',
        async share() {
            try { await navigator.share({ title: this.title, url: this.url }); } catch (e) { /* closed the sheet */ }
        },
        async copy() {
            try {
                await navigator.clipboard.writeText(this.url);
            } catch (e) {
                const t = document.createElement('textarea');
                t.value = this.url; document.body.appendChild(t); t.select();
                try { document.execCommand('copy'); } catch (e2) {}
                t.remove();
            }
            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
        },
     }"
     class="card-pad">
    <h2 class="label">Сподели обявата</h2>

    <div class="mt-2 flex flex-wrap gap-2">
        <button type="button" x-show="canShare" x-cloak @click="share()"
                class="inline-flex items-center gap-1.5 rounded-md border border-line-strong px-2.5 py-1.5 text-xs font-medium text-ink-muted transition hover:border-accent hover:text-accent">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true" class="h-4 w-4">
                <circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/>
                <path d="m8.2 10.8 7.6-3.6M8.2 13.2l7.6 3.6"/>
            </svg>
            Сподели
        </button>

        <a href="viber://forward?text={{ rawurlencode($title.' - '.$url) }}" rel="noopener"
           class="inline-flex items-center rounded-md border border-line-strong px-2.5 py-1.5 text-xs font-medium text-ink-muted transition hover:border-accent hover:text-accent">
            Viber
        </a>

        <a href="https://wa.me/?text={{ rawurlencode($title.' - '.$url) }}" target="_blank" rel="noopener noreferrer"
           class="inline-flex items-center rounded-md border border-line-strong px-2.5 py-1.5 text-xs font-medium text-ink-muted transition hover:border-accent hover:text-accent">
            WhatsApp
        </a>

        <a href="https://www.facebook.com/sharer/sharer.php?u={{ rawurlencode($url) }}" target="_blank" rel="noopener noreferrer"
           class="inline-flex items-center rounded-md border border-line-strong px-2.5 py-1.5 text-xs font-medium text-ink-muted transition hover:border-accent hover:text-accent">
            Facebook
        </a>

        <a href="https://t.me/share/url?url={{ rawurlencode($url) }}&text={{ rawurlencode($title) }}" target="_blank" rel="noopener noreferrer"
           class="inline-flex items-center rounded-md border border-line-strong px-2.5 py-1.5 text-xs font-medium text-ink-muted transition hover:border-accent hover:text-accent">
            Telegram
        </a>

        <button type="button" @click="copy()"
                class="inline-flex items-center gap-1.5 rounded-md border border-line-strong px-2.5 py-1.5 text-xs font-medium text-ink-muted transition hover:border-accent hover:text-accent">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true" class="h-4 w-4">
                <rect x="9" y="9" width="11" height="11" rx="2"/>
                <path d="M5 15V6a2 2 0 0 1 2-2h9"/>
            </svg>
            <span x-text="copied ? 'Копирано' : 'Копирай линка'">Копирай линка</span>
        </button>
    </div>
</div>
