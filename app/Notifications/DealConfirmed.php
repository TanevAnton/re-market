<?php

namespace App\Notifications;

use App\Models\Deal;
use App\Models\User;

/**
 * The other side confirmed. Sent to whoever has not confirmed yet, because a
 * deal that stalls does so almost entirely from one side forgetting - and the
 * cost of forgetting is an abandonment mark on a profile.
 */
class DealConfirmed extends RemarketNotification
{
    public function __construct(private readonly Deal $deal) {}

    public function subject(User $user): string
    {
        return 'Другата страна потвърди сделката';
    }

    public function lines(User $user): array
    {
        return [
            'Относно „'.$this->deal->listing->title.'“.',
            'Остава и ти да потвърдиш, че сделката се е състояла. Ако не го направиш до '
                .$this->deal->expires_at->format('d.m.Y H:i')
                .', сделката се отчита като неизпълнена и това се вижда в профила ти.',
        ];
    }

    public function url(User $user): string
    {
        return route('deals');
    }

    public function action(User $user): string
    {
        return 'Потвърди';
    }
}
