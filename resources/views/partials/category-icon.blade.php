{{-- One monoline icon per category, on the same 24px grid and the same rounded
     stroke as the theme toggle in the header.

     WHY SVG RATHER THAN THE GENERATED PNG SHEET. These render at 40px in the
     home grid. A raster icon is soft at that size, cannot inherit currentColor
     - so it neither inverts with the theme nor turns accent-ink on hover, the
     way the badge behind it does - and needs a light and a dark copy of every
     file. The PNG sheet is the right asset at 64px and above; this is the right
     one below it.

     DELIBERATELY FEWER LINES THAN THE REFERENCE. The sheet draws a motherboard
     with capacitors and a keyboard with individual keys, which is correct at
     illustration size and becomes grey mush at forty pixels. Each icon here is
     four to eight strokes: the silhouette plus the one or two details that make
     it that object and not its neighbour.

     Takes $category and an optional $class. Falls through to the „other" box
     for anything unknown, so a new category renders an icon rather than a hole
     the day it is added and before anybody draws for it. --}}

<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
     class="{{ $class ?? 'h-5 w-5' }}">
    @switch($category)

        @case('gpu')
            {{-- Board, two fans, bracket, PCIe tabs. --}}
            <rect x="4" y="5.5" width="16.5" height="10.5" rx="1.5"/>
            <circle cx="9.5" cy="10.75" r="2.4"/>
            <circle cx="16" cy="10.75" r="2.4"/>
            <path d="M2.5 3.5v15"/>
            <path d="M7.5 16v2.5M13 16v2.5"/>
            @break

        @case('cpu')
            <rect x="5.5" y="5.5" width="13" height="13" rx="1.5"/>
            <rect x="9.5" y="9.5" width="5" height="5" rx="0.5"/>
            <path d="M9 2.5v3M15 2.5v3M9 18.5v3M15 18.5v3"/>
            <path d="M2.5 9h3M2.5 15h3M18.5 9h3M18.5 15h3"/>
            @break

        @case('motherboard')
            {{-- Socket, memory slots, expansion slots. Three details, no
                 capacitors: at this size they are noise. --}}
            <rect x="3" y="3" width="18" height="18" rx="1.5"/>
            <rect x="6" y="6" width="5" height="5" rx="0.5"/>
            <path d="M14.5 6h3.5M14.5 9h3.5"/>
            <path d="M6 15h11M6 18h7"/>
            @break

        @case('ram')
            <rect x="2" y="7" width="20" height="8" rx="1"/>
            <rect x="5" y="9.5" width="3" height="3"/>
            <rect x="10.5" y="9.5" width="3" height="3"/>
            <rect x="16" y="9.5" width="3" height="3"/>
            <path d="M8 15v2.5M15 15v2.5"/>
            @break

        @case('psu')
            <rect x="2.5" y="5" width="19" height="14" rx="1.5"/>
            <circle cx="9" cy="12" r="4"/>
            <circle cx="9" cy="12" r="1"/>
            <rect x="15.5" y="8.5" width="4" height="3.5" rx="0.5"/>
            <path d="M15.5 15.5h4"/>
            @break

        @case('storage')
            {{-- M.2 stick: body, two packages, contact teeth. --}}
            <rect x="2" y="8.5" width="16.5" height="7" rx="1"/>
            <rect x="5" y="10.5" width="4" height="3"/>
            <rect x="10.5" y="10.5" width="4" height="3"/>
            <path d="M19.5 10.5v3M21.5 10.5v3"/>
            @break

        @case('monitor')
            <rect x="2.5" y="4" width="19" height="12.5" rx="1.5"/>
            <path d="M12 16.5V20"/>
            <path d="M8.5 20h7"/>
            @break

        @case('cooler')
            <rect x="2.5" y="6" width="7" height="12" rx="1"/>
            <path d="M2.5 9.5h7M2.5 12h7M2.5 14.5h7"/>
            <circle cx="16" cy="12" r="5"/>
            <circle cx="16" cy="12" r="1.4"/>
            @break

        @case('case')
            <rect x="5" y="2.5" width="14" height="19" rx="1.5"/>
            <rect x="7.5" y="5" width="6" height="10" rx="0.5"/>
            <circle cx="16.3" cy="6" r="0.9"/>
            <path d="M15 10h2.6M15 12.5h2.6"/>
            @break

        @case('laptop')
            {{-- The chunky one: a visible bezel around the screen and a deeper
                 base. Paired against `macbook` below, which has neither. --}}
            <rect x="4" y="3.5" width="16" height="11" rx="1"/>
            <rect x="6.5" y="6" width="11" height="6" rx="0.5"/>
            <path d="M2 17h20l-1.4 3.2H3.4z"/>
            @break

        @case('keyboard')
            {{-- Rows, not keys. Individual keys vanish below about 64px. --}}
            <rect x="2" y="6.5" width="20" height="11" rx="1.5"/>
            <path d="M5.5 10.3h13M5.5 12.8h13"/>
            <path d="M8.5 15.2h7"/>
            @break

        @case('mouse')
            <rect x="6" y="2.5" width="12" height="19" rx="6"/>
            <path d="M12 3v6.5"/>
            <path d="M6.2 9.5h11.6"/>
            @break

        @case('headset')
            <path d="M4 13.5v-1.5a8 8 0 0 1 16 0v1.5"/>
            <rect x="2" y="12.5" width="4.5" height="6.5" rx="1.8"/>
            <rect x="17.5" y="12.5" width="4.5" height="6.5" rx="1.8"/>
            <path d="M4.25 19v1.3A1.7 1.7 0 0 0 6 22h3"/>
            @break

        @case('console')
            <rect x="2.5" y="7.5" width="19" height="9" rx="4.5"/>
            <path d="M7 10v4M5 12h4"/>
            <circle cx="16" cy="11" r="0.9"/>
            <circle cx="18.3" cy="13.2" r="0.9"/>
            @break

        @case('prebuilt')
            <rect x="2.5" y="4.5" width="6" height="15" rx="1"/>
            <circle cx="5.5" cy="7" r="0.75"/>
            <rect x="11" y="4.5" width="10.5" height="8" rx="1"/>
            <path d="M16.25 12.5v2M13.5 14.5h5.5"/>
            <path d="M11 18h10.5"/>
            @break

        @case('iphone')
            <rect x="7" y="2" width="10" height="20" rx="2.5"/>
            <path d="M10.25 5h3.5"/>
            <path d="M10.5 19.3h3"/>
            @break

        @case('ipad')
            <rect x="3.5" y="2.5" width="12" height="19" rx="2"/>
            <circle cx="9.5" cy="5.2" r="0.5"/>
            <path d="M19 6.5v9.5l1 2.5 1-2.5V6.5a1 1 0 0 0-2 0z"/>
            @break

        @case('macbook')
            {{-- The slim one: one thin screen outline, no inner bezel, a
                 shallower base than `laptop`. --}}
            <rect x="4.5" y="4.5" width="15" height="10" rx="1"/>
            <path d="M2 17.5h20l-1 2.5H3z"/>
            <path d="M10.5 17.5h3"/>
            @break

        @default
            {{-- „Други", and the fallback for a category nobody has drawn yet. --}}
            <path d="M3.5 9h17v10.5a1.2 1.2 0 0 1-1.2 1.2H4.7a1.2 1.2 0 0 1-1.2-1.2z"/>
            <path d="M3.5 9 6 5h12l2.5 4"/>
            <path d="M12 5v4"/>
    @endswitch
</svg>
