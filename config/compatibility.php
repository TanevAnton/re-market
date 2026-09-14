<?php

/*
|--------------------------------------------------------------------------
| Cross-category compatibility links
|--------------------------------------------------------------------------
|
| „Тази карта иска захранване от 750 W нагоре" — with the words as a link to
| the PSUs on this site that qualify.
|
| This is the part of the catalogue that no general classifieds site can copy,
| because it needs typed specs on both ends of the link. OLX knows a listing is
| called „RTX 4070"; it does not know the card is 200 W, so it cannot walk from
| there to a power supply. We already store both numbers on the part rows, and
| the facet filters already know how to query them - so a compatibility link is
| just those two facts pointed at each other.
|
| It is also the honest version of an upsell. Nobody is being told to buy
| anything; they are being told what their own hardware needs, with the answer
| one click away. A buyer who learns here that their 550 W unit will not run
| this card has been saved a return, which is cheaper for everyone than the
| dispute that would otherwise arrive.
|
| RULE SHAPE
|
|   'to'        target category (a key of config/catalog.php categories)
|   'from'      spec on the SOURCE part that the rule reads; part-scoped only,
|               because a model page has no listing to read from. A rule whose
|               source spec is missing is skipped silently - most parts do not
|               carry every spec, and a link built from a null is a link to
|               everything.
|   'facet'     spec on the TARGET category to filter by. Must be facetable, or
|               the browse page will ignore it and the link will quietly return
|               unfiltered results.
|   'op'        'min' / 'max' for range facets, 'is' for terms facets.
|   'transform' optional arithmetic on a numeric source value:
|                 add   — headroom, positive or negative
|                 ceil  — round UP to a multiple of this (750 reads better
|                         than 731, and nobody sells a 731 W unit)
|                 floor — never propose less than this
|   'from_min'  skip the rule entirely when the source number is below this.
|               Guards the rules that subtract: a 300 W unit minus headroom is
|               not a sensible ceiling to advertise, it is nonsense.
|   'bg'        the link text. :from is the source value, :value the
|               transformed one; they are the same when there is no transform.
|   'why'       one line under it, same placeholders. Optional but usually the
|               reason the link is worth reading.
|
| Order within a category is the order they are shown, so the most useful edge
| goes first.
|
*/

return [

    /*
     * The card decides the power supply and the case, in that order: a
     * too-small PSU is a machine that will not boot, a too-small case is an
     * afternoon of measuring.
     */
    'gpu' => [
        [
            'to'        => 'psu',
            'from'      => 'tdp_w',
            // 200 W of headroom, not 100: the CPU, drives and fans are on the
            // same rail, and transient spikes on modern cards are far above
            // the rated draw. Rounding up to the next 50 W lands on wattages
            // that are actually sold.
            'transform' => ['add' => 200, 'ceil' => 50, 'floor' => 450],
            'facet'     => 'wattage',
            'op'        => 'min',
            'bg'        => 'Захранвания от :value W нагоре',
            'why'       => 'Картата е :from W, а останалата система и пиковете искат резерв.',
        ],
        [
            'to'    => 'case',
            'from'  => 'length_mm',
            'facet' => 'max_gpu_mm',
            'op'    => 'min',
            'bg'    => 'Кутии, в които влиза',
            'why'   => 'Картата е :from мм дълга.',
        ],
    ],

    'cpu' => [
        [
            'to'    => 'motherboard',
            'from'  => 'socket',
            'facet' => 'socket',
            'op'    => 'is',
            'bg'    => 'Дънни платки със socket :from',
        ],
        [
            'to'    => 'cooler',
            'from'  => 'socket',
            // `sockets` on a cooler is a multiselect, and the terms filter
            // tests containment rather than equality - so one socket value
            // correctly matches a cooler that lists six.
            'facet' => 'sockets',
            'op'    => 'is',
            'bg'    => 'Охладители за :from',
            'why'   => 'Кутията на процесора често идва без охладител втора употреба.',
        ],
    ],

    'motherboard' => [
        [
            'to'    => 'cpu',
            'from'  => 'socket',
            'facet' => 'socket',
            'op'    => 'is',
            'bg'    => 'Процесори за socket :from',
        ],
        [
            'to'    => 'ram',
            'from'  => 'ram_type',
            'facet' => 'type',
            'op'    => 'is',
            'bg'    => 'Памет :from',
            'why'   => 'Платката приема само :from — друг тип физически не влиза.',
        ],
        [
            'to'    => 'cooler',
            'from'  => 'socket',
            'facet' => 'sockets',
            'op'    => 'is',
            'bg'    => 'Охладители за :from',
        ],
        [
            'to'    => 'case',
            'from'  => 'form_factor',
            'facet' => 'form_factor',
            'op'    => 'is',
            'bg'    => 'Кутии за :from',
        ],
    ],

    'ram' => [
        [
            'to'    => 'motherboard',
            'from'  => 'type',
            'facet' => 'ram_type',
            'op'    => 'is',
            'bg'    => 'Дънни платки с :from',
        ],
    ],

    'psu' => [
        [
            'to'        => 'gpu',
            'from'      => 'wattage',
            // The mirror of the GPU rule, same 200 W of headroom. from_min
            // keeps a 300 W office unit from advertising "cards up to 100 W",
            // which is true and useless.
            'transform' => ['add' => -200],
            'from_min'  => 450,
            'facet'     => 'tdp_w',
            'op'        => 'max',
            'bg'        => 'Видеокарти, които ще издържи (до :value W)',
            'why'       => 'Захранването е :from W, с резерв за останалата система.',
        ],
    ],

    'cooler' => [
        [
            'to'    => 'cpu',
            // A multiselect source: the value is a list of sockets, and the
            // terms filter turns a list into an OR, which is exactly right.
            'from'  => 'sockets',
            'facet' => 'socket',
            'op'    => 'is',
            'bg'    => 'Процесори, които държи',
        ],
        [
            'to'    => 'case',
            'from'  => 'height_mm',
            'facet' => 'max_cooler_mm',
            'op'    => 'min',
            'bg'    => 'Кутии, в които влиза',
            'why'   => 'Охладителят е :from мм висок.',
        ],
    ],

    'case' => [
        [
            'to'    => 'motherboard',
            'from'  => 'form_factor',
            'facet' => 'form_factor',
            'op'    => 'is',
            'bg'    => 'Дънни платки, които пасват',
        ],
        [
            'to'    => 'gpu',
            'from'  => 'max_gpu_mm',
            'facet' => 'length_mm',
            'op'    => 'max',
            'bg'    => 'Видеокарти, които влизат (до :from мм)',
        ],
    ],
];
