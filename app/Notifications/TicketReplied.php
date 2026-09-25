<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * „We answered your ticket."
 *
 * NOT A RemarketNotification, and that is a deliberate exception to the rule
 * that everything goes through it. RemarketNotification is typed to a User and
 * routes on that user's channel preferences — and half the tickets here have no
 * user at all. Routing this one on the email address stored on the TICKET means
 * a guest and an account-holder take the same path, rather than two paths that
 * drift apart.
 *
 * MAIL ONLY, and it ignores `notify_email`. A support reply is the answer to a
 * question the person asked and is waiting for; treating it as marketing that
 * can be unsubscribed from would mean an answer nobody ever reads. Their
 * preference governs what the site sends them unasked, which this is not.
 */
class TicketReplied extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Ticket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $last = $this->ticket->messages()->where('from_staff', true)->latest('id')->first();

        $mail = (new MailMessage)
            ->subject('Отговор на запитване '.$this->ticket->reference.' · '.config('app.name'))
            ->greeting('Здравей,')
            ->line('Отговорихме на запитването ти „'.$this->ticket->subject.'".');

        if ($last) {
            /*
             * The answer itself is IN the email, not just a link to it.
             *
             * Most people read support replies in their inbox and never click
             * through. An email that says „you have a reply, log in to read it"
             * is an email that adds a step to getting an answer — and for the
             * commonest ticket of all, „I cannot log in", that step is the
             * exact thing they wrote in about.
             */
            $mail->line('---')->line($last->body)->line('---');
        }

        $mail->action('Отвори запитването', $this->url())
            ->line($this->ticket->status->isOpen()
                ? 'Ако има още нещо, отговори в същото запитване.'
                : 'Запитването е затворено. Отвори ново, ако има нещо друго.')
            ->salutation('Поздрави, екипът на '.config('app.name'));

        return $mail;
    }

    /**
     * Where the link goes.
     *
     * A guest ticket has no account to log into, so the link is signed: the
     * signature is tied to APP_KEY and to this ticket's uuid, and it grants
     * exactly one thing — reading and replying to this one conversation. It
     * does not expire, which is the same trade every support system makes,
     * because a link that dies while somebody is still mid-problem sends them
     * back to the start.
     *
     * A ticket that belongs to an account gets an ordinary link and a login.
     */
    private function url(): string
    {
        return $this->ticket->user_id
            ? route('support.ticket', $this->ticket)
            : \Illuminate\Support\Facades\URL::signedRoute('support.ticket', $this->ticket);
    }
}
