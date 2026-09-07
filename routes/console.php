<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Offers carry a TTL so a seller who never logs back in cannot leave buyers
 * hanging indefinitely. Every ten minutes is fine: OfferService already treats
 * an over-TTL offer as dead the moment anyone acts on it, so this sweep only
 * keeps the inbox honest.
 *
 * Nothing runs until a scheduler is actually running. In production that is
 * `php artisan schedule:work` under a supervisor, or a one-line cron calling
 * `schedule:run` every minute.
 */
Schedule::command('remarket:expire-offers')
    ->everyTenMinutes()
    ->withoutOverlapping();

/*
 * Deals that nobody confirmed inside the reservation window become abandoned,
 * and the listing goes back on sale. Hourly is enough - the window is measured
 * in days - and DealService refuses to act on a lapsed deal anyway, so a late
 * sweep cannot let one through.
 *
 * If this never runs, "accepted then ghosted" stays invisible and the
 * completion rate on every profile quietly becomes a lie.
 */
Schedule::command('remarket:lapse-deals')
    ->hourly()
    ->withoutOverlapping();
