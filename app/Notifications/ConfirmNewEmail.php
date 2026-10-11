<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * „Потвърди новия адрес" — sent TO the new address, which is the only reason
 * this flow is safe. Until somebody opens this link, users.email is untouched.
 *
 * EXTENDS Notification DIRECTLY, not RemarketNotification, and that is on
 * purpose. RemarketNotification skips an unverified address, returns no
 * channels when the user has email switched off, and prefers Telegram when the
 * bot knows the chat. All three are right for an offer and wrong here: this
 * address is unverified BY DEFINITION, a preference about being pinged must not
 * be able to lock somebody out of their own account, and the one message whose
 * job is to prove control of a mailbox has to go to that mailbox. Same
 * reasoning as ResetPasswordLink and VerifyEmailAddress.
 *
 * Not queued, for the same reason as those two: the person is looking at
 * „изпратихме ти линк" right now, and a queue worker that is not running turns
 * that into a silent lie.
 */
class ConfirmNewEmail extends Notification
{
    public function __construct(
        public readonly string $address,
        public readonly string $url,
        public readonly int $minutes,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Потвърди новия имейл адрес · '.config('app.name'))

            ->line('Поискана е смяна на имейла за профила ти в '.config('app.name').' на този адрес.')
            ->action('Потвърди адреса', $this->url)
            ->line("Линкът важи {$this->minutes} минути.")

            /*
             * The one sentence that matters to somebody who did not ask for
             * this: doing nothing is the safe move. There is no „report" button
             * here on purpose — whoever receives this message does not have the
             * account, so there is nothing for them to defend. The warning that
             * can be acted on went to the address that DOES have it.
             */
            ->line('Ако не си поискал смяната, просто игнорирай това писмо — адресът няма да бъде променен.')

            ->replyTo(config('legal.contact.users'))
            ->salutation('— '.config('app.name'));
    }
}
