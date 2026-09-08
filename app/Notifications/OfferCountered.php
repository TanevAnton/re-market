<?php

namespace App\Notifications;

use App\Models\Offer;
use App\Models\User;

/**
 * The seller countered. This one exists because of a real bug: a countered
 * buyer previously got no signal anywhere on the site that it was their move,
 * and the counter sat unanswered until it expired.
 */
class OfferCountered extends RemarketNotification
{
    public function __construct(private readonly Offer $counter) {}

    public function subject(User $user): string
    {
        return 'Насрещна оферта за „'.$this->counter->listing->title.'“';
    }

    public function lines(User $user): array
    {
        return [
            'Продавачът предлага '.$this->counter->formattedAmount().'.',
            'Важи до '.$this->counter->expires_at->format('d.m.Y H:i').'. Ти си на ход.',
        ];
    }

    public function url(User $user): string
    {
        return route('offers');
    }

    public function action(User $user): string
    {
        return 'Отговори';
    }
}
