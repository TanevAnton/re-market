<?php

namespace App\Enums;

/**
 * Three states, and only one of them moves.
 *
 * A payment is asked for, and then it either arrived or it did not. There is
 * no „partially paid" and no „failed, retry": a transfer for the wrong amount
 * is cancelled and asked for again, because a payment row that can change its
 * mind about how much it was worth is a payment row nobody can reconcile
 * against a bank statement.
 */
enum PaymentStatus: string
{
    case Pending   = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Чака плащане',
            self::Confirmed => 'Платено',
            self::Cancelled => 'Отказано',
        };
    }

    /** Has this one finished, whichever way? */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
