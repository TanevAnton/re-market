{{-- The RIGO mark: the O from the wordmark, on its own.

     A ring cut on the 4:30–10:30 diagonal into two halves that do not quite
     meet. It reads as the letter O first, which is the whole point — an earlier
     version used a square here and the word came out as „RIG + icon", pointing
     people at rig.bg, which belongs to somebody else.

     WHY THIS IS SVG AND NOT AN IMAGE. Same reason as the category icons: it
     renders at 32px in the header and 16px in a tab, where raster goes soft,
     and it inherits `currentColor` so it follows the theme instead of needing
     a light copy and a dark copy.

     WHY THE STROKE IS HEAVIER THAN THE ICON SET. The category icons are 1.6 on
     a 24 grid. This sits next to `font-extrabold` text at 15px, and 1.6 reads
     as thin and unfinished beside it. 2.2 is the same drawing doing a different
     job, not an inconsistency.

     Optional: $class for sizing, $stroke to override the weight. --}}

<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="{{ $stroke ?? 2.2 }}" stroke-linecap="round"
     class="{{ $class ?? 'h-7 w-7' }}" aria-hidden="true">

    {{-- Two 166° arcs, gaps of 14° centred on 45° and 225°.

         THE GAP WIDTH WAS SETTLED BY LOOKING, NOT BY TASTE. At 30° the mark
         renders as a loading spinner the moment it is bigger than about 60px —
         the eye reads two racing arcs rather than a broken letter. At 14° it
         reads as an O at every size from 16px to a poster, and the break still
         looks deliberate rather than like a rendering fault.

         Arcs rather than a filled donut with wedges cut out of it, so the
         weight is one number to change instead of a shape to redraw. --}}
    <path d="M16.62 17.91 A7.5 7.5 0 0 1 6.09 7.38"/>
    <path d="M7.38 6.09 A7.5 7.5 0 0 1 17.91 16.62"/>
</svg>
