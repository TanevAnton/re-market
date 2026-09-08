<?php

namespace App\Notifications;

use App\Models\Listing;
use App\Models\User;

/**
 * A moderator decided. DSA Art. 17 requires the affected user to RECEIVE the
 * statement of reasons - storing it and hoping they reopen the listing does
 * not satisfy the article, which is exactly what was happening until now.
 *
 * The statement is sent whole and unedited. It is the record of what the
 * person was told, and summarising it here would make the copy they read
 * differ from the copy on file.
 */
class ModerationDecision extends RemarketNotification
{
    public function __construct(
        private readonly Listing $listing,
        private readonly bool $approved,
        private readonly ?string $statement = null,
    ) {}

    public function subject(User $user): string
    {
        return $this->approved
            ? 'Обявата ти е одобрена — „'.$this->listing->title.'“'
            : 'Обявата ти е премахната — „'.$this->listing->title.'“';
    }

    public function lines(User $user): array
    {
        if ($this->approved) {
            return ['Обявата вече е публична и приема оферти.'];
        }

        return array_filter([
            $this->statement,
            'Ако смяташ решението за грешно, можеш да го оспориш — виж адреса в текста по-горе.',
        ]);
    }

    public function url(User $user): string
    {
        return route('listing', $this->listing);
    }

    public function action(User $user): string
    {
        return 'Виж обявата';
    }
}
