<?php

namespace App\Notifications;

use App\Models\Listing;
use App\Models\User;

/**
 * The card somebody shortlisted just got cheaper.
 *
 * These are the warmest buyers on the site: they already looked at this exact
 * item, decided they wanted it, and did not buy at the old number. Nothing
 * told them when the number moved, so the seller's price cut reached everybody
 * except the people it was most likely to convert.
 *
 * Only on a DROP. A rise is not news anyone asked for, and mailing "the thing
 * you wanted costs more now" is a message whose only effect is to make the
 * recipient feel worse about a site they gave their address to.
 */
class PriceDropped extends RemarketNotification
{
    public function __construct(
        private readonly Listing $listing,
        private readonly int $wasCents,
    ) {}

    public function subject(User $user): string
    {
        return 'Поевтиня с '.$this->formattedDifference().' — „'.$this->listing->title.'“';
    }

    public function lines(User $user): array
    {
        return [
            'Обява от списъка ти с харесани смени цената си: '
                .$this->formatted($this->wasCents).' → '
                .$this->listing->formattedPrice()
                .' ('.$this->percent().'% надолу).',

            // The one thing a shortlist notification has to say, because the
            // recipient did not ask for this message and needs to know it is
            // not the start of a stream of them.
            'Получаваш това, защото си запазил обявата. Махни я от харесаните, '
                .'за да спрат известията за нея.',
        ];
    }

    public function url(User $user): string
    {
        return route('listing', $this->listing);
    }

    public function action(User $user): string
    {
        return 'Виж обявата';
    }

    // --- money ------------------------------------------------------------

    private function formattedDifference(): string
    {
        return $this->formatted($this->wasCents - $this->listing->price_cents);
    }

    private function formatted(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' €';
    }

    private function percent(): int
    {
        // Rounded to a whole number on purpose: "12%" is what somebody decides
        // on, and "11.7%" invites them to work out the arithmetic instead.
        return (int) round(($this->wasCents - $this->listing->price_cents) / $this->wasCents * 100);
    }
}
