<?php

namespace App\Enums;

/**
 * Four states, and deliberately NOT five.
 *
 * There is no „withdrawn" here, although the rule says the package price is
 * withdrawn the moment a member stops being for sale. That is not a state a
 * bundle is put into — it is a fact about its members, so `Bundle::isComplete()`
 * derives it and `packagePrice()` returns null when it is false.
 *
 * Storing it would mean something had to remember to write it: when a member
 * sells, when one expires, when a moderator removes one, when the seller edits
 * a member back to Active. Five places to get right and one to forget, and the
 * failure mode is a machine advertised at a package price with no graphics card
 * in it — a price that is not just stale but wrong in the seller's favour.
 */
enum BundleStatus: string
{
    case Draft         = 'draft';
    case PendingReview = 'pending_review';
    case Active        = 'active';
    case Removed       = 'removed';

    public function isPubliclyVisible(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft         => 'Чернова',
            self::PendingReview => 'За преглед',
            self::Active        => 'Активен',
            self::Removed       => 'Премахнат',
        };
    }
}
