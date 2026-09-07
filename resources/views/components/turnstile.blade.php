{{--
    The Turnstile widget.

    Renders nothing at all when no keys are configured, so local development and
    the LAN box are unaffected. The callback writes the token straight into the
    Livewire component through @this, which is why the property is #[Locked] -
    Cloudflare's script is the only thing that should ever set it.
--}}
@if (\App\Support\Turnstile::enabled())
    <div class="mt-3" wire:ignore>
        <div x-data
             x-init="
                 const render = () => {
                     const id = window.turnstile.render($el, {
                         sitekey: '{{ \App\Support\Turnstile::siteKey() }}',
                         callback: (token) => @this.set('turnstileToken', token, true),
                         'expired-callback': () => @this.set('turnstileToken', '', true),
                         theme: 'auto',
                     });
                     window.addEventListener('turnstile-reset', () => window.turnstile.reset(id));
                 };

                 window.turnstile ? render()
                     : window.addEventListener('turnstile-ready', render, { once: true });
             "></div>
    </div>

    @once
        @push('scripts')
            <script>window.__turnstileReady = () => window.dispatchEvent(new Event('turnstile-ready'));</script>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=__turnstileReady&render=explicit"
                    async defer></script>
        @endpush
    @endonce
@endif
