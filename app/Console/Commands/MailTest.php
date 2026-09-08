<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
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
 */
class MailTest extends Command
{
    protected $signature = 'remarket:mail-test {to : Where to send it}';

    protected $description = 'Send a test email and report the transport it used';

    public function handle(): int
    {
        $to     = (string) $this->argument('to');
        $mailer = config('mail.default');

        $this->line('');
        $this->line('  <options=bold>Mail transport</>');
        $this->line('');
        $this->line('  mailer     '.$mailer);
        $this->line('  host       '.(config("mail.mailers.{$mailer}.host") ?: '—'));
        $this->line('  port       '.(config("mail.mailers.{$mailer}.port") ?: '—'));
        $this->line('  scheme     '.(config("mail.mailers.{$mailer}.scheme") ?: '—'));
        $this->line('  username   '.(config("mail.mailers.{$mailer}.username") ?: '—'));
        $this->line('  from       '.config('mail.from.address').' ('.config('mail.from.name').')');
        $this->line('');

        if ($mailer === 'log') {
            // Not a failure - it is the local default - but it is the single
            // most likely reason "nobody can register" on a real deployment.
            $this->warn('  MAIL_MAILER=log: this writes to storage/logs/laravel.log and sends nothing.');
            $this->warn('  Verification links will never reach a real inbox.');
            $this->line('');
        }

        try {
            Mail::raw(
                "Това е тестово съобщение от RE-MARKET.\n\n"
                ."Ако го четеш, потвърждаването на имейл ще работи.\n"
                .'Изпратено: '.now()->toDateTimeString(),
                fn ($message) => $message->to($to)->subject('RE-MARKET — тест на пощата'),
            );
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

        if ($mailer !== 'log') {
            // Arriving in spam is the same as not arriving: the user never
            // finishes signing up, and nothing on our side looks wrong.
            $this->line('  <fg=gray>Check the spam folder too. If it landed there, re-tech.bg</>');
            $this->line('  <fg=gray>needs SPF and DKIM covering this mailbox.</>');
        }

        $this->line('');

        return self::SUCCESS;
    }
}
