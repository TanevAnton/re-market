<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;

/**
 * Send one real message and say what it went through.
 *
 * Exists because email verification is the account gate: if mail does not
 * leave the server, nobody but the developer can finish signing up, and the
 * symptom is a user sitting on "check your email" forever with nothing in any
 * log to explain it.
 *
 * A command rather than a Tinker one-liner because PowerShell mangles both -
 * it eats backslashes in pasted Tinker input and dollars in `php -r`.
 *
 * TWO MODES, AND THEY TEST DIFFERENT THINGS. The default sends plain text,
 * which is the right shape for a transport check: it reaches the inbox with
 * nothing in between that could be blamed. `--design` sends the same content
 * through the real template - header, logo, button, footer - which is the only
 * way to see what a user actually receives. A plain-text test that arrives
 * proves SMTP works and says nothing whatsoever about the branding, which is
 * exactly the confusion this flag exists to end.
 */
class MailTest extends Command
{
    protected $signature = 'remarket:mail-test
                            {to : Where to send it}
                            {--design : Send it through the branded template instead of plain text}';

    protected $description = 'Send a test email and report the transport it used';

    public function handle(): int
    {
        $to     = (string) $this->argument('to');
        $mailer = config('mail.default');
        $name   = config('app.name');

        $this->line('');
        $this->line('  <options=bold>Mail transport</>');
        $this->line('');
        $this->line('  mailer     '.$mailer);
        $this->line('  host       '.(config("mail.mailers.{$mailer}.host") ?: '—'));
        $this->line('  port       '.(config("mail.mailers.{$mailer}.port") ?: '—'));
        $this->line('  scheme     '.(config("mail.mailers.{$mailer}.scheme") ?: '—'));
        $this->line('  username   '.(config("mail.mailers.{$mailer}.username") ?: '—'));
        $this->line('  from       '.config('mail.from.address').' ('.config('mail.from.name').')');
        $this->line('  app url    '.config('app.url'));
        $this->line('');

        if ($mailer === 'log') {
            // Not a failure - it is the local default - but it is the single
            // most likely reason "nobody can register" on a real deployment.
            $this->warn('  MAIL_MAILER=log: this writes to storage/logs/laravel.log and sends nothing.');
            $this->warn('  Verification links will never reach a real inbox.');
            $this->line('');
        }

        try {
            if ($this->option('design')) {
                $this->sendDesigned($to, $name);
            } else {
                $this->sendPlain($to, $name);
            }
        } catch (\Throwable $e) {
            $this->error('  Sending failed: '.$e->getMessage());
            $this->line('');
            $this->line('  <fg=gray>Wrong port/scheme pair is the usual cause:</>');
            $this->line('  <fg=gray>  465 needs MAIL_SCHEME=smtps, 587 needs MAIL_SCHEME=smtp</>');
            $this->line('  <fg=gray>Also check the mailbox password, and that the host allows</>');
            $this->line('  <fg=gray>SMTP auth from this server\'s IP.</>');
            $this->line('');

            return self::FAILURE;
        }

        $this->info("  Sent to {$to}.");

        if ($this->option('design')) {
            /*
             * The logo is fetched by the reader's mail client from a public
             * URL built out of APP_URL. On a laptop that is usually localhost,
             * which no inbox in the world can reach - so a missing logo in a
             * local test is the configuration, not the template.
             */
            $this->line('');
            $this->line('  <fg=gray>The logo is loaded from '.rtrim((string) config('app.url'), '/').'/email-logo.png</>');

            if (! str_starts_with((string) config('app.url'), 'https://')) {
                $this->warn('  APP_URL is not a public https address, so the logo will not load in the inbox.');
                $this->warn('  The wordmark still renders - that is why the header carries both.');
            }

            $this->line('  <fg=gray>Most clients also block remote images until the reader allows them.</>');
        }

        if ($mailer !== 'log') {
            // Arriving in spam is the same as not arriving: the user never
            // finishes signing up, and nothing on our side looks wrong.
            $this->line('  <fg=gray>Check the spam folder too. If it landed there, the sending domain</>');
            $this->line('  <fg=gray>needs SPF and DKIM covering this mailbox.</>');
        }

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * The transport check. Nothing between the server and the inbox, so an
     * arrival means SMTP works and a failure means SMTP does not.
     */
    private function sendPlain(string $to, string $name): void
    {
        Mail::raw(
            "Това е тестово съобщение от {$name}.\n\n"
            ."Ако го четеш, потвърждаването на имейл ще работи.\n"
            .'Изпратено: '.now()->toDateTimeString(),
            fn ($message) => $message->to($to)->subject($name.' — тест на пощата'),
        );
    }

    /**
     * The real template, with the real content shape: a greeting, a paragraph,
     * a button, a closing line and the footer. Built as a MailMessage and
     * rendered through the notification view, so what arrives is exactly what
     * a user receives - not an approximation of it.
     */
    private function sendDesigned(string $to, string $name): void
    {
        $message = (new MailMessage)
            ->subject($name.' — тест на оформлението')
            ->line('Това е тестово съобщение. Ако го виждаш с логото, бутона и подписа отдолу, оформлението работи.')
            ->action('Примерен бутон', config('app.url'))
            ->line('Изпратено: '.now()->toDateTimeString())
            ->line('— — —')
            ->line('**English**')
            ->line('This is a layout test. If you can see the logo, the button and the footer below, the template is working.')
            ->salutation('— '.$name);

        Mail::html(
            (string) $message->render(),
            fn ($mail) => $mail->to($to)->subject($name.' — тест на оформлението'),
        );
    }
}
