<?php

namespace App\Notifications;

use App\Models\Listing;
use App\Models\User;
use App\Models\WantedAd;

/**
 * A seller answered your request.
 *
 * Separate from WantedMatchFound on purpose, even though both are about a
 * listing and a wanted ad. That one is the site guessing; this one is a person
 * who read the request and chose to respond. They deserve different words —
 * and the recipient, who asked for this by posting, deserves to be able to
 * tell which is which.
 */
class WantedAnswered extends RemarketNotification
{
    public function __construct(
        private readonly WantedAd $ad,
        private readonly Listing $listing,
    ) {}

    public function subject(User $user): string
    {
        return 'Предложиха ти обява по „'.$this->ad->title.'“';
    }

    public function lines(User $user): array
    {
        return [
            'Продавач предложи обява по търсенето ти „'.$this->ad->title.'“: '
                .$this->listing->title.' — '.$this->listing->formattedPrice().'.',

            // The buyer has to know the ball is in their court and how to play
            // it: the offer system, not a message, is where a price is agreed.
            'Ако те устройва, отвори обявата и изпрати оферта. Ако не — махни '
                .'предложението и продавачът не научава.',
        ];
    }

    public function url(User $user): string
    {
        return route('wanted.show', $this->ad);
    }

    public function action(User $user): string
    {
        return 'Виж предложението';
    }
}
