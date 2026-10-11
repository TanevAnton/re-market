<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * „Някой иска да смени имейла ти" — sent to the OLD address.
 *
 * THIS MAIL IS NOT PREVENTION, IT IS DETECTION, and the difference decides
 * everything about how it is written. Changing the address requires the current
 * password, so anybody who got far enough to trigger this already has it. The
 * question it has to answer is therefore not „do you approve" but „do you know
 * this is happening", and the answer has to arrive somewhere the attacker is
 * not looking: the mailbox they are in the middle of taking the account away
 * from.
 *
 * Hence the cancel link, which works WITHOUT LOGGING IN. Somebody whose
 * password is already known cannot be told to log in to defend themselves - and
 * by the time they read this, the race is for whoever acts first.
 *
 * The new address is shown in full rather than masked. A victim needs to be
 * able to recognise it as not theirs, and masking it protects the attacker.
 *
 * Extends Notification directly rather than RemarketNotification: account
 * security mail ignores notification preferences. Somebody who switched email
 * off still gets told their account is being taken.
 */
class EmailChangeRequested extends Notification
{
    public function __construct(
        public readonly string $currentAddress,
        public readonly string $newAddress,
        public readonly string $cancelUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Поискана е смяна на имейла ти · '.config('app.name'))

            ->line('Получихме заявка профилът ти в '.config('app.name')
                .' да се прехвърли на друг имейл адрес:')
            ->line('**'.$this->newAddress.'**')
            ->line('Докато новият адрес не бъде потвърден, нищо не се променя и този адрес работи както досега.')

            ->line('— — —')

            ->line('**Ако това не си ти**')
            ->line('Някой знае паролата ти. Натисни бутона, за да спреш смяната, '
                .'и веднага след това смени паролата си.')
            ->action('Спри смяната', $this->cancelUrl)
            ->line('Ако пък ти си я поискал — няма какво да правиш тук.')

            ->replyTo(config('legal.contact.users'))
            ->salutation('— '.config('app.name'));
    }
}
