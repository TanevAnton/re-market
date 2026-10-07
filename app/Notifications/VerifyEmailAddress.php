<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * „Потвърди имейла си" — the first thing anybody ever receives from RIGO.
 *
 * REPLACING LARAVEL'S OWN, which was shipping in English to a Bulgarian
 * marketplace: „Verify Email Address", „Please click the button below", „If you
 * did not create an account, no further action is required." A first impression
 * in the wrong language, carrying a footer that said Laravel.
 *
 * BILINGUAL, UNLIKE ALMOST ANYTHING ELSE ON THE SITE, and the reason is that
 * this message is the gate. Everything else a user reads, they read after
 * choosing to be here and after the interface has already shown them what
 * language it speaks. This one arrives cold, in a mailbox, and if its reader
 * cannot parse it they cannot get in to discover that the site was not for
 * them. Bulgarian first because that is who the site is for; English second
 * because the cost of being wrong about that is somebody locked out.
 *
 * NOT QUEUED, inherited from the parent and worth keeping: the person is
 * looking at „изпратихме ти линк" right now, and a queue worker that is not
 * running turns that into a silent lie. ResetPasswordLink is synchronous for
 * exactly the same reason.
 */
class VerifyEmailAddress extends VerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            // Both languages in the subject, because the subject line is the
            // only part a reader sees before deciding whether to open it.
            ->subject('Потвърди имейла си / Confirm your email · '.config('app.name'))

            ->line('Остава една стъпка: потвърди, че този имейл адрес е твой.')
            ->action('Потвърди имейла', $this->verificationUrl($notifiable))
            ->line("Линкът важи {$minutes} минути.")
            ->line('Ако не си създавал профил в '.config('app.name').', просто игнорирай това писмо — нищо няма да се случи.')

            // The separator is a line of its own rather than a rule, because a
            // horizontal rule is the one piece of mail markup that renders
            // differently in every client that has ever existed.
            ->line('— — —')

            ->line('**English**')
            ->line('One step left: confirm that this email address is yours. Use the button above.')
            ->line("The link is valid for {$minutes} minutes.")
            ->line('If you did not create a '.config('app.name').' account, you can ignore this message — nothing will happen.')

            // no-reply@ sends it; support@ is where an answer should land.
            ->replyTo(config('legal.contact.users'))
            ->salutation('— '.config('app.name'));
    }
}
