{{--
    The Turnstile widget. THE DIV ONLY - the script that powers it is loaded by
    the layout, next to the scripts stack.

    That split is the whole point and it is not a tidy-up. This file used to
    carry its own script tag, pushed into the layout's stack and guarded so it
    happened only once. That works only when the widget is rendered during the
    INITIAL page render. Render it behind a wizard step, a tab or a modal and
    the push reaches nothing, because a Livewire update cannot add to a stack
    the layout has already resolved. The div then sits there with no
    window.turnstile behind it, waiting for a `turnstile-ready` event that will
    never fire. No widget, no token, and every submit refused for a reason the
    user can neither see nor act on.

    SO: DO NOT PUT A SCRIPT TAG BACK IN THIS FILE. Drop this component anywhere,
    at any time, in any branch, and it works. TurnstileTest pins that.

    Renders nothing at all when no keys are configured, so local development and
    the LAN box are unaffected. That silence is also the risk: a live site with
    a blank key has no bot defence and says nothing about it, which is what
    `php artisan remarket:doctor` exists to shout about.

    The callback writes the token straight into the Livewire component. The
    property it writes to is deliberately NOT locked - a locked one makes
    exactly this callback throw. This note used to claim the opposite and was
    wrong; see the trait for the reasoning, and do not "fix" the property to
    match a comment.
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
@endif
