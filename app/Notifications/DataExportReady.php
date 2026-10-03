<?php

namespace App\Notifications;

use App\Models\DataExport;
use App\Models\User;

/**
 * „Your copy is ready."
 *
 * NO LINK STRAIGHT TO THE FILE. The archive is the person's entire account in
 * one zip, and a notification is a thing that gets forwarded, screenshotted and
 * left open on a shared screen. The link goes to the page, which requires being
 * signed in; the download is one more click from there.
 */
class DataExportReady extends RemarketNotification
{
    public function __construct(private readonly DataExport $export) {}

    public function subject(User $user): string
    {
        return 'Данните ти са готови за сваляне';
    }

    public function lines(User $user): array
    {
        return [
            'Архивът с твоите данни е готов.',

            'Пази го внимателно — вътре е всичко наведнъж: профил, обяви, сделки, '
                .'съобщения и снимки.',

            'Линкът работи '.app(\App\Services\Privacy\PersonalDataExport::class)->lifetimeHours()
                .' часа, след което файлът се изтрива автоматично. Можеш да поискаш нов по всяко време.',
        ];
    }

    public function url(User $user): string
    {
        return route('privacy.data');
    }

    public function action(User $user): string
    {
        return 'Свали данните си';
    }
}
