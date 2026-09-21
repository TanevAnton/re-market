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

    {{-- Two 128° arcs, gaps of 52° centred on 45° and 225°.

         THE GAP IS THE WHOLE IDEA, so it is sized to be seen rather than to be
         safe. Two parts not quite meeting is what the mark is about, and at
         14° — where this started — that reading survived only if somebody told
         you it was there.

         52° IS THE OUTER LIMIT AND IT IS MEANT TO BE. It was picked off a
         rendered ladder of 14 / 28 / 40 / 52 / 64 at 96px, 40px, 28px and
         16px, not off a number. At 64° the arcs stop closing into a circle and
         read as a pair of brackets, the word falls back to „RIG + something",
         and that is the failure the square version had — it pointed people at
         rig.bg, which belongs to somebody else.

         So: do not open this further without rendering the header lockup at
         28px and looking at it. The letter is load-bearing.

         Arcs rather than a filled donut with wedges cut out of it, so the
         weight is one number to change instead of a shape to redraw. --}}
    <path d="M14.44 19.09 A7.5 7.5 0 0 1 4.91 9.56"/>
    <path d="M9.56 4.91 A7.5 7.5 0 0 1 19.09 14.44"/>
</svg>
