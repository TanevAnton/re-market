<?php

namespace App\Notifications;

use App\Models\Offer;
use App\Models\User;

/**
 * The seller accepted somebody else.
 *
 * Deliberately not OfferDeclined. That one tells the buyer the seller refused
 * their price and invites them back after the cooldown with a higher figure -
 * advice that is simply false here: the listing is reserved and there is
 * nothing left to bid on. Sending it would produce a stream of "your offer was
 * declined, try again" messages for an item that is gone, which reads as the
 * site being broken and costs exactly the buyers who were willing to pay.
 *
 * The reservation can lapse and the listing return, so this says so rather
 * than closing the door. It does not name the winning price: what someone else
 * agreed to pay is their business, and publishing it turns every lost auction
 * into a lesson in how much to bid next time.
 */
class OfferLost extends RemarketNotification
{
    public function __construct(private readonly Offer $offer) {}

    public function subject(User $user): string
    {
        return 'Артикулът е запазен за друг купувач — „'.$this->offer->listing->title.'“';
    }

    public function lines(User $user): array
    {
        return [
            'Продавачът прие друга оферта, така че твоята за '
                .$this->offer->formattedAmount().' отпада.',
            'Ако сделката не се осъществи, обявата се връща активна и ще можеш '
                .'да предложиш отново.',
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
