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

/*
 * The numbers on every part landing page - listing counts and the price band.
 *
 * Nightly rather than on write: the figures are a market summary, nobody is
 * harmed by them being a few hours old, and recomputing percentiles on every
 * publish would put a full table scan in the path of posting an ad.
 *
 * If this never runs, every part page reports zero listings and no price band,
 * which is precisely the content those pages exist to carry. 04:10 rather than
 * 04:00 so it is not fighting whatever else the box does on the hour.
 */
Schedule::command('remarket:refresh-part-stats')
    ->dailyAt('04:10')
    ->withoutOverlapping();

/*
 * Saved-search alerts. Hourly is the compromise: often enough that a buyer
 * hears about a card while it is still for sale, rare enough that nobody gets
 * a message every time someone posts.
 *
 * The command itself is what stops it becoming spam - it only ever reports
 * listings published since that search last notified.
 */
Schedule::command('remarket:notify-saved-searches')
    ->hourly()
    ->withoutOverlapping();
