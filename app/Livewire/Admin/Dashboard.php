<?php

namespace App\Livewire\Admin;

use App\Services\Admin\Pulse;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The first screen to open in the morning.
 *
 * NOT A REPORTING TOOL. It answers three questions and stops: did anything
 * happen, is anybody waiting on me, and is the line going up or flat. Anything
 * beyond that belongs in a query somebody runs on purpose — a dashboard that
 * tries to show everything is one nobody reads, and the figures that matter at
 * launch are small enough to take in at a glance.
 *
 * EVERY NUMBER COMES FROM Pulse, including the ones in the account menu. The
 * two surfaces answer the same questions and must never give different
 * answers; see that class.
 *
 * NO ACCESS CHECK HERE. The route carries `admin`, which answers 404 rather
 * than 403 — an admin screen that announces its own existence to a stranger is
 * a map of where to attack. Pinning that is AdminDashboardTest's job.
 */
class Dashboard extends Component
{
    #[Layout('components.layouts.app')]
    public function render(Pulse $pulse)
    {
        $today = $pulse->startOfToday();
        $week  = $pulse->startOfDays(7);

        $attention = [
            // Order is severity, not alphabet: an unremoved stolen listing is
            // a crime scene, an unverified company is a disappointment.
            ['label' => 'Сигнали за кражба',  'count' => $pulse->stolenClaims(),    'route' => 'stolen'],
            ['label' => 'Модерация',          'count' => $pulse->moderationQueue(), 'route' => 'moderation'],
            ['label' => 'Плащания',           'count' => $pulse->pendingPayments(), 'route' => 'payments'],
            ['label' => 'Запитвания',         'count' => $pulse->openTickets(),     'route' => 'tickets'],
            ['label' => 'Проверка на фирми',  'count' => $pulse->traderRequests(),  'route' => 'traders'],
        ];

        return view('livewire.admin.dashboard', [
            'listingsToday'   => $pulse->listingsPublishedSince($today),
            'listingsWeek'    => $pulse->listingsPublishedSince($week),
            'activeListings'  => $pulse->activeListings(),

            'usersToday'      => $pulse->usersJoinedSince($today),
            'usersWeek'       => $pulse->usersJoinedSince($week),
            'users'           => $pulse->users(),

            'dealsWeek'       => $pulse->dealsOpenedSince($week),
            'dealsDoneWeek'   => $pulse->dealsCompletedSince($week),
            'openDeals'       => $pulse->openDeals(),

            'attention'       => $attention,
            'waiting'         => array_sum(array_column($attention, 'count')),

            // Neutral on purpose: work that is available, not work that is late.
            'uncatalogued'    => $pulse->uncatalogued(),

            'series'          => $pulse->dailySeries(7),
            'hasDemoData'     => $pulse->hasDemoData(),
            'zone'            => $pulse->zone(),
        ]);
    }
}
