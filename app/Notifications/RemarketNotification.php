<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every notification the site sends.
 *
 * Subclasses supply three things - a subject, some lines, and a link - and this
 * renders both channels from them. That is deliberate: the moment each
 * notification writes its own email AND its own Telegram text, the two drift,
 * and the version a user sees depends on which channel they happen to use.
 *
 * Queued, because a slow SMTP handshake must not sit in the middle of accepting
 * an offer. That means a queue worker has to be running - see
 * deploy/remarket-queue.service. Without one these are written to the jobs
 * table and never sent, which is the quietest possible failure, so the
 * deployment notes say so twice.
 */
abstract class RemarketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** One line, no full stop - it becomes the mail subject. */
    abstract public function subject(User $user): string;

    /** @return list<string> body paragraphs, in the order they should read */
    abstract public function lines(User $user): array;

    /** Where the notification is asking them to go. Absolute. */
    abstract public function url(User $user): string;

    public function action(User $user): string
    {
        return 'Виж в сайта';
    }

    /**
     * Telegram first when it is available: it is free, instant, and the user
     * already proved they read it. Email is the fallback and the archive.
     *
     * Unverified email is skipped rather than queued and bounced - the address
     * has not been shown to work, and bouncing mail is how a sending domain
     * loses the reputation the next thousand messages depend on.
     */
    public function via(User $notifiable): array
    {
        $channels = [];

        if ($notifiable->notify_telegram && $notifiable->telegram_chat_id) {
            $channels[] = TelegramChannel::class;
        }

        if ($notifiable->notify_email && $notifiable->hasVerifiedEmail()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject($notifiable).' · '.config('app.name'));

        foreach ($this->lines($notifiable) as $line) {
            $mail->line($line);
        }

        return $mail
            ->action($this->action($notifiable), $this->url($notifiable))
            ->salutation('— '.config('app.name'));
    }

    /**
     * Plain text. No Markdown parse mode: a listing title is user input, and
     * an unescaped underscore in "RTX_4070" would either break the message or,
     * worse, silently swallow part of it.
     */
    public function toTelegram(User $notifiable): string
    {
        return $this->subject($notifiable)."\n\n"
            .implode("\n\n", $this->lines($notifiable))."\n\n"
            .$this->url($notifiable);
    }
}
