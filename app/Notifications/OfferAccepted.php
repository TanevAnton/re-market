<?php

namespace App\Notifications;

use App\Models\Deal;
use App\Models\User;

/**
 * Accepted. The listing is now reserved and a 72-hour clock is running on both
 * of them, so this says what happens next rather than only what happened.
 */
class OfferAccepted extends RemarketNotification
{
    public function __construct(private readonly Deal $deal) {}

    public function subject(User $user): string
    {
        return 'Офертата ти е приета — „'.$this->deal->listing->title.'“';
    }

    public function lines(User $user): array
    {
        return [
            'Обявата е запазена за теб до '.$this->deal->expires_at->format('d.m.Y H:i').'.',
            'Уговорете предаването в разговора. И двамата потвърждавате, че сделката се е '
                .'състояла — това е единственото, което влиза в репутацията.',
            'Препоръчваме „преглед и тест“ на куриера: плащаш след като видиш артикула работещ.',
        ];
    }

    public function url(User $user): string
    {
        return route('deals');
    }

    public function action(User $user): string
    {
        return 'Отвори сделката';
    }
}
