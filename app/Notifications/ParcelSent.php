<?php

namespace App\Notifications;

use App\Enums\Courier;
use App\Models\Deal;
use App\Models\User;

/**
 * „It is on its way, and here is the number."
 *
 * Goes to the BUYER. Sent only when the number actually changes, so a seller
 * correcting a typo does not send this three times.
 *
 * The inspect-and-test reminder rides along deliberately. It is the one thing the
 * buyer has to do at the counter and the last moment anything is still fixable —
 * once they have paid and left, a dead card is an argument instead of a refusal.
 * The listing page says it, the checklist says it, and this says it at the moment
 * it stops being advice and becomes a thing to do tomorrow.
 */
class ParcelSent extends RemarketNotification
{
    public function __construct(private readonly Deal $deal) {}

    public function subject(User $user): string
    {
        return 'Пратката е изпратена';
    }

    public function lines(User $user): array
    {
        $courier = Courier::tryFrom((string) $this->deal->courier);

        return array_filter([
            'Продавачът изпрати „'.$this->deal->listing?->title.'".',

            $courier
                ? $courier->label().', товарителница '.$this->deal->tracking_number
                : 'Товарителница '.$this->deal->tracking_number,

            $this->deal->inspect_test_selected && $courier?->inspectTestName()
                ? 'Поискай „'.$courier->inspectTestName().'" в офиса: отваряш и пробваш '
                    .'вещта ПРЕДИ да платиш. Ако не е както е описана, не я приемаш.'
                : 'Прегледай пратката в офиса, преди да платиш.',

            'Като получиш вещта, потвърди сделката в сайта — без потвърждение и от '
                .'двама ви никой не получава оценка.',
        ]);
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
