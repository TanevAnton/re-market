<?php

namespace App\Console\Commands;

use App\Support\Turnstile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Is this deployment actually ready to face the public?
 *
 * Every check here is a setting whose wrong value fails SILENTLY. Turnstile
 * with no keys does not error, it just waves every bot through. A queue with
 * no worker does not error, it writes jobs to a table nobody reads - including
 * the statement of reasons that DSA Art. 17 requires the user to receive. A
 * wrong APP_URL sends verification mail that arrives and then 403s. None of
 * these appear in a log, and the site looks like it works in all of them.
 *
 * "Production" here is `SEO_INDEXABLE`, not APP_ENV.
 *
 * APP_ENV is production on the LAN test box too, because that is how you test
 * production behaviour. SEO_INDEXABLE is the one setting that means "this
 * deployment is the real one, index it" - so it is the honest answer to
 * "should this box be held to launch standards", and using it means the test
 * box is not nagged about settings that are correct for a test box.
 */
class Doctor extends Command
{
    protected $signature = 'remarket:doctor {--strict : treat warnings as failures}';

    protected $description = 'Check this deployment for silent misconfiguration';

    private bool $failed = false;
    private int $warnings = 0;

    /** Does this deployment claim to be the public site? */
    private bool $live = false;

    public function handle(): int
    {
        $this->live = (bool) config('remarket.seo.indexable', false);

        $this->line('');
        $this->line('  <options=bold>RE-MARKET deployment check</>');
        $this->line('  <fg=gray>'.($this->live
            ? 'SEO_INDEXABLE is on — held to launch standards.'
            : 'SEO_INDEXABLE is off — treated as a test deployment.').'</>');
        $this->line('');

        $this->checkBotDefence();
        $this->checkUrls();
        $this->checkMail();
        $this->checkQueue();
        $this->checkPosture();
        $this->checkSecrets();

        $this->line('');

        if ($this->failed) {
            $this->line('  <fg=red;options=bold>Not ready.</> Fix the FAILs above.');
            $this->line('  <fg=gray>After editing .env: php artisan config:cache</>');
            $this->line('');

            return self::FAILURE;
        }

        $this->warnings > 0
            ? $this->line("  <fg=yellow;options=bold>Ready, with {$this->warnings} warning(s).</>")
            : $this->line('  <fg=green;options=bold>All clear.</>');
        $this->line('');

        return ($this->warnings > 0 && $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }

    // --- the checks -------------------------------------------------------

    /**
     * The headline. Turnstile disables itself when unconfigured, on purpose -
     * otherwise local development and the LAN box could not sign anyone up -
     * and the cost of that kindness is that a live site with an empty key has
     * no bot defence and says nothing about it.
     */
    private function checkBotDefence(): void
    {
        $this->section('Bot defence');

        if (Turnstile::enabled()) {
            /*
             * Cloudflare's published dummy keys work on any hostname, which is
             * what makes them useful before the domain exists - and dangerous
             * after. The "always passes" pair renders a real-looking widget
             * that every bot clears, so a live site running them is worse off
             * than one with no keys at all: it looks protected. Nothing else
             * can tell the difference, because to every other part of the
             * codebase a key is a key.
             *
             * Matched on the documented prefixes rather than the exact strings,
             * so the fail/invisible/interactive variants are caught too.
             */
            $site   = (string) config('remarket.turnstile.site_key');
            $secret = (string) config('remarket.turnstile.secret_key');
            $dummy  = preg_match('/^[123]x0{20,}/', $site) || preg_match('/^[123]x0{30,}/', $secret);

            if ($dummy && $this->live) {
                $this->bad('Turnstile is running on Cloudflare TEST keys. The widget renders and verifies nothing.');
                $this->hint('Replace with the real keys from dash.cloudflare.com → Turnstile.');
            } elseif ($dummy) {
                $this->good('Turnstile is wired up on Cloudflare test keys — correct for a box with no domain.');
            } else {
                $this->good('Turnstile is configured and active on register, login and password reset.');
            }

            return;
        }

        $missing = collect([
            'TURNSTILE_SITE_KEY'   => config('remarket.turnstile.site_key'),
            'TURNSTILE_SECRET_KEY' => config('remarket.turnstile.secret_key'),
        ])->filter(fn ($v) => blank($v))->keys()->implode(' and ');

        if ($this->live) {
            $this->bad("Turnstile is OFF — {$missing} blank. Every signup form is unprotected.");
            $this->hint('Both keys are required: one blank key disables it entirely.');
            $this->hint('Get them at dash.cloudflare.com → Turnstile → Add site.');
        } else {
            $this->warn_("Turnstile is off ({$missing} blank). Fine here; must be set before launch.");
        }
    }

    private function checkUrls(): void
    {
        $this->section('Addresses');

        $url = (string) config('app.url');

        // APP_URL signs the email verification link and now the password reset
        // link too. Wrong value: the mail arrives, the link 403s, and the user
        // concludes the site is broken.
        if (blank($url)) {
            $this->bad('APP_URL is empty. Verification and password-reset links will not work.');
        } elseif (str_contains($url, 'localhost') || preg_match('~://\d+\.\d+\.\d+\.\d+~', $url)) {
            $this->live
                ? $this->bad("APP_URL is {$url} — a live site cannot sign links with a LAN address.")
                : $this->good("APP_URL = {$url} (LAN address, correct for a test box).");
        } else {
            $this->good("APP_URL = {$url}");
        }

        if ($this->live && ! str_starts_with($url, 'https://')) {
            $this->bad('APP_URL is not https. Signed links and session cookies both depend on it.');
        }

        if (config('app.debug')) {
            $this->live
                ? $this->bad('APP_DEBUG is on. Stack traces would be public.')
                : $this->warn_('APP_DEBUG is on.');
        } else {
            $this->good('APP_DEBUG is off.');
        }
    }

    private function checkMail(): void
    {
        $this->section('Mail');

        $mailer = (string) config('mail.default');
        $from   = (string) config('mail.from.address');

        // Email is the account gate AND now the only password recovery path.
        // A dead mailer means nobody can register and nobody locked out can
        // get back in.
        if ($mailer === 'log' || $mailer === 'array') {
            $this->live
                ? $this->bad("MAIL_MAILER is '{$mailer}'. No mail leaves the server, so signup and password reset are both dead ends.")
                : $this->warn_("MAIL_MAILER is '{$mailer}' — mail goes to the log, not the inbox.");
        } else {
            $this->good("MAIL_MAILER = {$mailer}");
        }

        blank($from)
            ? $this->bad('MAIL_FROM_ADDRESS is empty.')
            : $this->good("Sending as {$from}");

        // Two published addresses carry legal duties, and the statement of
        // reasons falls back to the Art. 12 one for appeals. An empty value
        // here means a legal page naming a mailbox that does not exist.
        foreach (['users' => 'Art. 12 user contact', 'authorities' => 'Art. 11 authority contact'] as $key => $label) {
            blank(config("legal.contact.{$key}"))
                ? $this->bad("{$label} is not configured.")
                : $this->good("{$label}: ".config("legal.contact.{$key}"));
        }
    }

    /**
     * The one check that cannot be done by reading .env.
     *
     * A queue worker that has died leaves everything looking healthy: the site
     * responds, nothing throws, and jobs quietly pile up in a table. The only
     * visible symptom is that the oldest one stops being recent.
     */
    private function checkQueue(): void
    {
        $this->section('Queue');

        if (config('queue.default') === 'sync') {
            $this->warn_('QUEUE_CONNECTION=sync — notifications send inline, so a slow SMTP blocks the request.');

            return;
        }

        if (! Schema::hasTable('jobs')) {
            $this->bad('QUEUE_CONNECTION is database but the jobs table is missing. Run migrations.');

            return;
        }

        $pending = DB::table('jobs')->count();
        $oldest  = DB::table('jobs')->min('available_at');

        if ($pending === 0) {
            $this->good('No jobs waiting.');
        } else {
            $ageMinutes = (int) floor((time() - (int) $oldest) / 60);

            // A worker that is alive drains the table continuously, so a job
            // older than a few minutes means nothing is consuming them.
            if ($ageMinutes >= 5) {
                $this->bad("{$pending} job(s) waiting, oldest {$ageMinutes} min — the queue worker is not running.");
                $this->hint('systemctl status remarket-queue   ·   one of these carries a DSA Art. 17 obligation.');
            } else {
                $this->good("{$pending} job(s) waiting, oldest {$ageMinutes} min — being worked through.");
            }
        }

        if (Schema::hasTable('failed_jobs') && ($failed = DB::table('failed_jobs')->count()) > 0) {
            $this->warn_("{$failed} failed job(s). php artisan queue:failed");
        }
    }

    /** Settings that are choices rather than mistakes — reported, not judged. */
    private function checkPosture(): void
    {
        $this->section('Gates');

        $logChannel = (bool) config('remarket.verify.allow_log_channel_in_production');

        // Right on a test box, dangerous on the real one: it writes phone
        // verification codes to a file instead of sending them.
        if ($logChannel && $this->live) {
            $this->bad('VERIFY_ALLOW_LOG_CHANNEL is on. Verification codes are written to the log instead of sent.');
        } elseif ($logChannel) {
            $this->good('VERIFY_ALLOW_LOG_CHANNEL is on — codes go to the log. Correct for testing.');
        } else {
            $this->good('VERIFY_ALLOW_LOG_CHANNEL is off.');
        }

        $moderated = (int) config('remarket.antispam.moderated_listings_for_new_accounts');
        $moderated > 0
            ? $this->good("First {$moderated} listing(s) from a new account go through moderation.")
            : $this->warn_('NEW_ACCOUNT_MODERATED_LISTINGS is 0 — nothing from a new account is reviewed.');

        config('remarket.listings.require_timestamp_photo_for_private')
            ? $this->good('The handwritten-note photo is required.')
            : $this->good('The handwritten-note photo is optional — more load on moderation, by choice.');

        $this->live
            ? $this->good('SEO_INDEXABLE is on: pages are indexable and the sitemap is served.')
            : $this->good('SEO_INDEXABLE is off: every page is noindex and the sitemap 404s.');
    }

    private function checkSecrets(): void
    {
        $this->section('Secrets');

        // Rotating this orphans every stored hash and lets banned numbers walk
        // back in, so an empty one at launch is a decision made by accident.
        blank(config('remarket.phone_hash_salt'))
            ? ($this->live
                ? $this->bad('PHONE_HASH_SALT is empty. Phone hashes would be unsalted, and setting it later orphans every existing one.')
                : $this->warn_('PHONE_HASH_SALT is empty. Set it before any real user verifies a number.'))
            : $this->good('PHONE_HASH_SALT is set.');

        blank(config('app.key'))
            ? $this->bad('APP_KEY is empty.')
            : $this->good('APP_KEY is set.');
    }

    // --- output -----------------------------------------------------------

    private function section(string $title): void
    {
        $this->line("  <options=bold>{$title}</>");
    }

    private function good(string $m): void
    {
        $this->line("  <fg=green>OK</>   {$m}");
    }

    private function bad(string $m): void
    {
        $this->failed = true;
        $this->line("  <fg=red>FAIL</> {$m}");
    }

    /** Named with a trailing underscore: Command::warn() already exists. */
    private function warn_(string $m): void
    {
        $this->warnings++;
        $this->line("  <fg=yellow>WARN</> {$m}");
    }

    private function hint(string $m): void
    {
        $this->line("       <fg=gray>{$m}</>");
    }
}
