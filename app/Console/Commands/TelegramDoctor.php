<?php

namespace App\Console\Commands;

use App\Models\TelegramLink;
use App\Services\Verification\TelegramBot;
use Illuminate\Console\Command;

/**
 * Answers "why is Telegram verification not working" in one run.
 *
 * This exists because the failure it diagnoses cost an evening: the button was
 * simply absent from the page, with nothing in any log, and the three possible
 * causes - missing config, stale config cache, old code on the box - all look
 * exactly the same from the outside. Each check below is one of those.
 */
class TelegramDoctor extends Command
{
    protected $signature = 'remarket:telegram-doctor';

    protected $description = 'Check that Telegram bot verification is correctly wired up';

    public function handle(TelegramBot $bot): int
    {
        $ok = true;

        $this->line('');
        $this->line('  <options=bold>Telegram bot verification</>');
        $this->line('');

        // 1. The token. Everything else depends on it and nothing can be
        //    inferred without it.
        $token = (string) config('remarket.verify.telegram_bot_token');

        if ($token === '') {
            $this->bad('TELEGRAM_BOT_TOKEN is not set.');
            $this->hint('Add it to .env, then: php artisan config:cache');

            // No point continuing - every later check would just repeat this.
            $this->line('');

            return self::FAILURE;
        }

        $this->good('TELEGRAM_BOT_TOKEN is set ('.substr($token, 0, 8).'…, '.strlen($token).' chars).');

        // 2. Does Telegram accept it? Separates "wrong token" from "no
        //    outbound network", which are otherwise the same silence.
        $me = $bot->getMe();

        if (! $me) {
            $this->bad('Telegram did not accept the token, or the server cannot reach api.telegram.org.');
            $this->hint('Try: curl -s https://api.telegram.org/bot<token>/getMe');
            $ok = false;
        } else {
            $this->good('Telegram accepts it: @'.($me['username'] ?? '?').' (id '.($me['id'] ?? '?').').');
        }

        // 3. The username. Configured wins; otherwise we discover it. This is
        //    the check that would have caught the original bug immediately.
        $configured = (string) config('remarket.verify.telegram_bot_username');
        $resolved   = $bot->username();

        if ($configured === '') {
            $resolved
                ? $this->good("TELEGRAM_BOT_USERNAME is not set - discovered @{$resolved} from Telegram.")
                : $this->bad('TELEGRAM_BOT_USERNAME is not set and could not be discovered.');
        } else {
            $this->good("TELEGRAM_BOT_USERNAME = @{$resolved}.");

            if ($me && isset($me['username']) && strcasecmp($resolved ?? '', $me['username']) !== 0) {
                $this->bad("It does not match the bot the token belongs to (@{$me['username']}).");
                $this->hint('The deep link would open the wrong bot. Fix or remove TELEGRAM_BOT_USERNAME.');
                $ok = false;
            }
        }

        // 4. A webhook makes getUpdates return 409 forever, in silence.
        if ($url = $bot->webhookUrl()) {
            $this->bad("A webhook is registered ({$url}) - polling receives nothing while it exists.");
            $this->hint('Fix: php artisan remarket:telegram-bot --once (offers to delete it)');
            $ok = false;
        } else {
            $this->good('No webhook registered, so long polling will work.');
        }

        // 5. Does the page actually offer the button? The thing being
        //    diagnosed, stated directly rather than inferred.
        $this->line('');

        $ok
            ? $this->good('The verification page will show "Потвърди с Telegram".')
            : $this->bad('The verification page will not work correctly until the above is fixed.');

        // 6. Is anything listening? A perfectly configured bot that nobody
        //    polls verifies nobody.
        $pending = TelegramLink::usable()->count();
        $this->line("  <fg=gray>Pending handshakes right now: {$pending}</>");
        $this->line('  <fg=gray>Listener status: systemctl status remarket-telegram-bot</>');
        $this->line('');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function good(string $m): void
    {
        $this->line("  <fg=green>OK</>   {$m}");
    }

    private function bad(string $m): void
    {
        $this->line("  <fg=red>FAIL</> {$m}");
    }

    private function hint(string $m): void
    {
        $this->line("       <fg=gray>{$m}</>");
    }
}
