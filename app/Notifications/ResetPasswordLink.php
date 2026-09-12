<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The password reset link.
 *
 * Deliberately NOT a RemarketNotification, which is the obvious place to put
 * it and the wrong one. That base class answers "how does this person like to
 * be told things", and every one of its answers is wrong here:
 *
 * - It skips an unverified address. Someone who registered, never clicked the
 *   verification link and forgot their password is exactly who needs this.
 * - It returns no channels at all when the user has turned email off. A
 *   notification preference is about offers and messages; it must not be able
 *   to lock somebody out of their own account permanently.
 * - It would send this over Telegram when the bot knows the chat. A reset link
 *   is the one message whose whole purpose is to prove control of the mailbox,
 *   so it goes to the mailbox and nowhere else.
 *
 * Also NOT queued, unlike every other mail on the site. The rest can wait for
 * a worker; this one cannot fail silently into the jobs table while the person
 * stares at "we sent you a link". Laravel's own verification mail is
 * synchronous for the same reason.
 */
class ResetPasswordLink extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        $url = route('password.reset', [
            'token' => $this->token,
            // Carried in the URL because the broker needs both halves to
            // verify, and asking someone to retype the address they just
            // typed is how a working link gets abandoned.
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Нова парола · '.config('app.name'))
            ->line('Поискана е нова парола за профила ти в '.config('app.name').'.')
            ->action('Задай нова парола', $url)
            ->line("Линкът важи {$minutes} минути.")
            // The reassurance matters more than it looks: without it, somebody
            // who did not request this has no idea whether they need to act.
            ->line('Ако не си ти, няма нужда да правиш нищо — паролата ти остава същата.')
            ->salutation('— '.config('app.name'));
    }
}
