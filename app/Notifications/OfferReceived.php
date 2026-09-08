<?php

namespace App\Notifications;

use App\Models\Offer;
use App\Models\User;

/**
 * A buyer made an offer. The seller has 48 hours and, until now, no way of
 * knowing the clock had started.
 *
 * Auto-declined lowballs never reach here - they are excluded from the
 * seller's inbox by design, and notifying about one would hand the buyer the
 * attention the floor exists to deny them.
 */
class OfferReceived extends RemarketNotification
{
    public function __construct(private readonly Offer $offer) {}

    public function subject(User $user): string
    {
        return 'Нова оферта за „'.$this->offer->listing->title.'“';
    }

    public function lines(User $user): array
    {
        $lines = [
            $this->offer->formattedAmount().' при цена '.$this->offer->listing->formattedPrice().'.',
            'Офертата важи до '.$this->offer->expires_at->format('d.m.Y H:i').'. Без отговор изтича сама.',
        ];

        if ($this->offer->note) {
            $lines[] = 'Съобщение от купувача: '.$this->offer->note;
        }

        return $lines;
    }

    public function url(User $user): string
    {
        return route('offers');
    }

    public function action(User $user): string
    {
        return 'Виж офертата';
    }
}
