{{-- „Обикновено отговаря до 2 часа."

     Absent far more often than present, and that is the design: one-sided like
     the deal badge, withheld below a sample floor, and withheld again when the
     seller answers too few of the threads they get — see App\Support\ReplySpeed.

     Assignment inside the @if rather than a raw PHP block, matching
     `@if ($rate = $listing->user->completionRate())` a few lines above the
     first caller. Several views in here declare „no raw PHP in this file, in
     either form" for a compiler reason worth not relearning.

     Takes $seller, and optionally $class. --}}

@if ($speed = \App\Support\ReplySpeed::forSeller($seller))
    <p class="{{ $class ?? 'mt-2' }} flex items-center gap-1.5 text-xs text-ink-muted">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
             class="h-3.5 w-3.5 shrink-0 text-accent">
            <circle cx="12" cy="12" r="9"/>
            <path d="M12 7v5l3 2"/>
        </svg>
        Обикновено отговаря
        <span class="font-medium text-ink">{{ \App\Support\ReplySpeed::label($speed['seconds']) }}</span>
    </p>
@endif
