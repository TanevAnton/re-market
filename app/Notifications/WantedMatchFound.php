<?php

namespace App\Notifications;

use App\Models\Listing;
use App\Models\User;
use App\Models\WantedAd;

/**
 * Somebody is looking for what you have — or: what you were looking for is
 * here.
 *
 * ONE CLASS FOR BOTH DIRECTIONS, because it is one fact seen from two sides.
 * Splitting it into two notifications would mean two subjects, two bodies and
 * two links to keep in step, and the day they drift is the day one side of the
 * match stops being told anything.
 *
 * THE STRONGEST MESSAGE THIS SITE SENDS. „Търсят точно това, което продаваш"
 * is the only notification here that is about money the recipient has not
 * earned yet, and it is the reason a seller with a card in a drawer opens the
 * site at all. It is also the one most easily ruined by sending it twice — see
 * `WantedService::announce()` and the `matched_at` stamp.
 */
class WantedMatchFound extends RemarketNotification
{
    public function __construct(
        private readonly WantedAd $ad,
        private readonly Listing $listing,
        private readonly bool $forBuyer = false,
    ) {}

    public function subject(User $user): string
    {
        return $this->forBuyer
            ? 'Появи се това, което търсиш — „'.$this->listing->title.'“'
            : 'Търсят това, което продаваш — „'.$this->ad->title.'“';
    }

    public function lines(User $user): array
    {
        if ($this->forBuyer) {
            return [
                'Нова обява отговаря на търсенето ти „'.$this->ad->title.'“: '
                    .$this->listing->title.' — '.$this->listing->formattedPrice().'.',

                'Получаваш това, защото имаш активно търсене. Затвори го от '
                    .'„Моите търсения", за да спрат известията.',
            ];
        }

        return [
            'Купувач търси „'.$this->ad->title.'“'
                .($this->ad->formattedBudget() ? ' ('.$this->ad->formattedBudget().')' : '')
                .', а ти имаш подходяща обява: '.$this->listing->title.'.',

            // Said plainly, because the seller is about to be asked to do
            // something and should know exactly what it costs.
            'Ако я предложиш, купувачът вижда обявата ти с цена, снимки и '
                .'оценките ти. Безплатно е.',
        ];
    }

    public function url(User $user): string
    {
        // The seller is sent to the REQUEST, because that is where the button
        // they need is. The buyer is sent to the LISTING, because that is the
        // thing they have been waiting for.
        return $this->forBuyer
            ? route('listing', $this->listing)
            : route('wanted.show', $this->ad);
    }

    public function action(User $user): string
    {
        return $this->forBuyer ? 'Виж обявата' : 'Виж търсенето';
    }
}
