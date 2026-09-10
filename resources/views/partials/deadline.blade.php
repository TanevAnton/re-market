{{-- A countdown that gets louder as it runs out.

     Both the offer window (48h) and the deal window (72h) were rendered as
     `diffForHumans()` in text-ink-faint - the smallest, quietest thing on the
     card. That is exactly backwards. An offer that expires costs both sides a
     deal that had already been agreed on price, and a deal that lapses marks
     somebody abandoned, which follows their profile permanently. It is the
     most consequential fact on the screen and it looked like a footnote.

     "след 2 дни" is also the wrong resolution for a 48-hour window. Hours are
     what someone can act on; days are what they round down and forget.

     Expects: $at (Carbon), $prefix (string), optional $done (bool). --}}
@php
    $expired = ! $at->isFuture();

    /*
     * Minutes, and rounded UP.
     *
     * Carbon 3 returns a float from these, so working in hours prints
     * "остават 2.9999 ч" for something three hours away. Minutes are the
     * honest unit to measure in and rounding up is the honest direction to
     * round: telling someone they have 2 hours when they have 2h59m makes
     * them hurry, telling them 3 when they have 2h01m makes them late.
     */
    $minutesLeft = $expired ? 0 : (int) ceil(now()->diffInMinutes($at, false));

    $tone = match (true) {
        ($done ?? false)       => 'neutral',
        $expired               => 'bad',
        $minutesLeft < 6 * 60  => 'bad',
        $minutesLeft < 24 * 60 => 'warn',
        default                => 'neutral',
    };

    // Under a day, count in hours; over it, in days. Nobody needs "остават 47 ч".
    $remaining = match (true) {
        $expired               => 'изтече',
        $minutesLeft < 60      => 'под час',
        $minutesLeft < 24 * 60 => 'остават '.(int) ceil($minutesLeft / 60).' ч',
        default                => 'остават '.(int) ceil($minutesLeft / 1440).' дни',
    };
@endphp

<span @class([
    'inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium',
    'bg-bad-soft text-bad'          => $tone === 'bad',
    'bg-warn-soft text-warn'        => $tone === 'warn',
    'bg-surface-alt text-ink-muted' => $tone === 'neutral',
])>
    @if ($tone === 'bad' && ! ($done ?? false))
        {{-- A dot rather than an icon set: it reads at any size and needs no
             second colour to mean "now". --}}
        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-current"></span>
    @endif
    {{ $prefix }} <span class="font-mono tabular">{{ $remaining }}</span>
</span>
