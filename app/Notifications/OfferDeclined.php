<?php

namespace App\Notifications;

use App\Models\Offer;
use App\Models\User;

/**
 * Declined. Says the cooldown out loud, because a buyer who does not know
 * about it reads the next refusal as the site being broken.
 */
class OfferDeclined extends RemarketNotification
{
    public function __construct(private readonly Offer $offer) {}

    public function subject(User $user): string
    {
        return 'Офертата ти е отказана — „'.$this->offer->listing->title.'“';
    }

    public function lines(User $user): array
    {
        $hours = (int) config('remarket.offers.decline_cooldown', 24);

        return [
            'Продавачът отказа '.$this->offer->formattedAmount().'.',
            'Можеш да предложиш отново след '.$hours.' часа, и новата оферта трябва да е по-висока.',
        ];
    }

    public function url(User $user): string
    {
        return route('listing', $this->offer->listing);
    }

    public function action(User $user): string
    {
        return 'Виж обявата';
    }
}
