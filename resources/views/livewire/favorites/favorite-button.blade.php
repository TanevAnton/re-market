{{-- A filled heart is the state, not the action. Users read the icon as
     "this is saved", so it must never show the opposite of what is true. --}}
<button type="button" wire:click="toggle"
        aria-pressed="{{ $saved ? 'true' : 'false' }}"
        aria-label="{{ $saved ? 'Премахни от запазени' : 'Запази обявата' }}"
        title="{{ $saved ? 'Премахни от запазени' : 'Запази обявата' }}"
        @class([
            'inline-flex items-center justify-center gap-1.5 rounded-md transition',
            'h-8 w-8 bg-canvas/85 backdrop-blur hover:bg-canvas'      => $compact,
            'btn-secondary w-full'                                    => ! $compact,
            'text-bad'                                                => $saved,
            'text-ink-muted hover:text-bad'                           => ! $saved,
        ])>
    <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0"
         fill="{{ $saved ? 'currentColor' : 'none' }}"
         stroke="currentColor" stroke-width="2" stroke-linejoin="round">
        <path d="M12 20.5 4.2 12.9a4.8 4.8 0 0 1 0-6.8 4.8 4.8 0 0 1 6.8 0l1 1 1-1a4.8 4.8 0 0 1 6.8 0
                 4.8 4.8 0 0 1 0 6.8Z"/>
    </svg>

    @unless ($compact)
        <span>{{ $saved ? 'Запазена' : 'Запази' }}</span>
    @endunless
</button>
