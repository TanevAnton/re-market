<?php

namespace App\Notifications;

use App\Models\Thread;
use App\Models\User;

/**
 * Someone wrote. Sent once per unread streak, not once per message - see
 * ThreadService, which only fires this when the recipient has nothing unread
 * in the thread already. Ten messages in a row is one notification.
 *
 * The body is deliberately NOT included. Before a deal exists the recipient
 * sees a scrubbed version of the text, and mailing the raw message would route
 * a phone number straight around the scrubber.
 */
class NewMessage extends RemarketNotification
{
    public function __construct(
        private readonly Thread $thread,
        private readonly string $senderName,
    ) {}

    public function subject(User $user): string
    {
        return 'Ново съобщение от '.$this->senderName;
    }

    public function lines(User $user): array
    {
        return [
            'Относно „'.$this->thread->listing->title.'“.',
        ];
    }

    public function url(User $user): string
    {
        return route('thread', $this->thread);
    }

    public function action(User $user): string
    {
        return 'Отвори разговора';
    }
}
