<?php

namespace App\Livewire;

use App\Support\BuildPlanner;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * „Сглоби компютър от втора употреба" — the site's own argument, as a page.
 *
 * WHY A PAGE AND NOT JUST THE LINKS. The compatibility links have existed on
 * every listing and model page for a while and nobody sees them, because they
 * sit under a listing somebody arrived at already knowing what they wanted.
 * They answer a question the visitor did not come with. This page asks it for
 * them: it is possible to buy a whole working machine here, second-hand, and
 * here is one, with today's prices.
 *
 * It is also the only surface on the site that can rank for „сглоби компютър
 * втора употреба" and „компютър на части". A listing cannot — it is one part
 * and it disappears when it sells. A model page cannot — it is one model. This
 * page is about the idea, so it survives every listing on it selling.
 *
 * A COMPONENT RATHER THAN A CONTROLLER, because the builds are assembled from
 * live listings and the empty states need the same Blade the rest of the site
 * uses. There is no interaction yet — the honest version of a configurator
 * needs supply this site does not have, and shipping a builder whose slots are
 * all empty would argue against the idea rather than for it.
 */
#[Layout('components.layouts.app')]
#[Title('Сглоби компютър от втора употреба')]
class BuildGuide extends Component
{
    public function render()
    {
        /*
         * Cached, and the cache is the reason this page can be linked from the
         * header. Eight constrained queries per machine times three machines is
         * a lot to pay on every visit for a page whose contents change when
         * somebody posts a graphics card, which is not often.
         *
         * Ten minutes matches the facet cache on browse, so the two cannot
         * disagree for long about what is on the site.
         */
        $builds = Cache::remember(
            'build-guide:showcase',
            now()->addMinutes(10),
            fn () => BuildPlanner::showcase(3),
        );

        return view('livewire.build-guide', [
            'builds' => $builds,

            /*
             * The edges themselves, as the explanation of how this works. Not
             * marketing copy: it is generated from config/compatibility.php, so
             * the page cannot claim a rule the site does not actually apply.
             */
            'rules'  => collect(config('compatibility', []))
                ->flatMap(fn (array $rules, string $from) => collect($rules)
                    ->map(fn (array $r) => [
                        'from' => $from,
                        'to'   => $r['to'],
                        'why'  => $r['why'] ?? $r['bg'],
                    ]))
                ->values()
                ->all(),

            'ogImage' => null,
        ]);
    }
}
