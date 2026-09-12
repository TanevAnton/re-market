{{-- Five stars, one implementation.

     This was written out three times - the profile's rating list, and twice in
     the rate-deal form - each with its own str_repeat and its own colours. A
     score that renders differently in the place you give it and the place it
     is shown is a small thing that makes the whole number look unreliable.

     Expects: $score (int 0-5), optional $size ('sm'|'md'|'lg'). --}}
@php
    $score = max(0, min(5, (int) $score));
    $sizeClass = match ($size ?? 'sm') {
        'lg'    => 'text-xl',
        'md'    => 'text-base',
        default => 'text-sm',
    };
@endphp

<span class="font-mono {{ $sizeClass }} leading-none text-accent"
      role="img" aria-label="{{ $score }} от 5 звезди">
    <span aria-hidden="true">{{ str_repeat('★', $score) }}<span
        class="text-ink-faint">{{ str_repeat('★', 5 - $score) }}</span></span>
</span>
