<?php

namespace App\Notifications;

use App\Enums\Courier;
use App\Models\Deal;
use App\Models\User;

/**
 * „The buyer has said where to send it."
 *
 * Goes to the SELLER, and it is the one notification on the site that unblocks
 * work rather than asking for a decision: until this arrives there is nothing
 * they can do, and after it there is a waybill to create. A seller who does not
 * hear about it waits for a message the buyer thinks they already sent, which is
 * exactly how a deal quietly becomes an abandoned one.
 *
 * NO ADDRESS IN THE BODY. It is a Telegram message and an email — neither is a
 * place to put somebody's home address and phone number, and both get forwarded,
 * screenshotted and left open on shared screens. The link goes to the panel.
 */
class DeliveryDetailsSet extends RemarketNotification
{
    public function __construct(private readonly Deal $deal) {}

    public function subject(User $user): string
    {
        return 'Купувачът попълни данните за доставка';
    }

    public function lines(User $user): array
    {
        $courier = Courier::tryFrom((string) $this->deal->courier);

        return array_filter([
            'Купувачът на „'.$this->deal->listing?->title.'" попълни данните за доставка.',

            $courier?->ships()
                ? 'Начин на доставка: '.$courier->label().'. Отвори сделката — там са '
                    .'всички полета за товарителницата, готови за копиране.'
                : 'Договорихте лично предаване, така че няма товарителница.',

            $this->deal->inspect_test_selected && $courier?->inspectTestName()
                ? 'Купувачът е избрал „'.$courier->inspectTestName().'" — отваря и пробва '
                    .'пратката, преди да плати.'
                : null,

            // Said here rather than only on the panel, because this is the
            // message that gets read on a phone and acted on later.
            'Товарителницата я правиш ти, от своя профил в куриера или на място. '
                .'Наложеният платеж отива директно при теб — RIGO не участва в плащането.',
        ]);
    }

    public function url(User $user): string
    {
        return route('deals');
    }

    public function action(User $user): string
    {
        return 'Виж данните за доставка';
    }
}
