<?php

namespace App\Console\Commands;

use App\Services\Verification\TelegramBot;
use App\Services\Verification\TelegramBotVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Long-polls Telegram for bot updates and hands each to the verifier.
 *
 * Runs as a systemd service rather than on the scheduler: the user is sitting
 * on the verification page waiting, and a once-a-minute cron would mean up to
 * sixty seconds of staring at nothing. Long polling makes it near-instant.
 *
 * Outbound HTTPS only, so it works from a machine behind NAT with no public
 * address - which webhooks would require.
 *
 * Every update is logged. The first version was silent on success, which meant
 * an empty journal could not be told apart from nothing ever arriving - and
 * that is exactly the moment you need to know which.
 */
class TelegramBotListen extends Command
{
    protected $signature = 'remarket:telegram-bot
                            {--once : Process one batch and exit, for testing or a cron fallback}
                            {--reset : Forget the stored offset and re-read the pending backlog}';

    protected $description = 'Listen for Telegram bot updates and verify phone numbers';

    /**
     * Telegram replays any update that was not acknowledged, and an update is
     * acknowledged by asking for the NEXT one. Losing this offset means
     * replaying the backlog; the verifier is idempotent (a consumed link
     * cannot be consumed twice), so a replay is noisy rather than harmful.
     */
    private const OFFSET_KEY = 'telegram:updates:offset';

    public function handle(TelegramBot $bot, TelegramBotVerifier $verifier): int
    {
        if (! $bot->isConfigured()) {
            $this->error('TELEGRAM_BOT_TOKEN is not set. In production, run config:cache after editing .env.');

            return self::FAILURE;
        }

        $me = $bot->getMe();

        if (! $me) {
            $this->error('Telegram rejected the token - check TELEGRAM_BOT_TOKEN.');

            return self::FAILURE;
        }

        /*
         * A registered webhook makes getUpdates return 409 Conflict forever,
         * and the poller then sits there quietly receiving nothing. Nothing in
         * the log, no error, no updates - identical to "nobody messaged the
         * bot". Check for it rather than let it waste an evening.
         */
        if ($url = $bot->webhookUrl()) {
            $this->warn("A webhook is registered ({$url}), which blocks polling entirely.");

            if ($this->option('once') || $this->confirm('Delete it and use polling?', true)) {
                $bot->deleteWebhook();
                $this->info('Webhook deleted.');
            } else {
                return self::FAILURE;
            }
        }

        if ($this->option('reset')) {
            Cache::forget(self::OFFSET_KEY);
            $this->warn('Offset cleared - any updates Telegram still holds will be re-read.');
        }

        $this->info('Listening as @'.($me['username'] ?? '?').'. Ctrl+C to stop.');

        do {
            $offset  = (int) Cache::get(self::OFFSET_KEY, 0);
            $updates = $bot->getUpdates($offset, timeout: $this->option('once') ? 0 : 25);

            foreach ($updates as $update) {
                $this->describe($update);

                try {
                    $verifier->handle($update);
                } catch (\Throwable $e) {
                    // One malformed update must not take the worker down and
                    // leave every user's verification silently broken.
                    $this->error('  update failed: '.$e->getMessage());
                    report($e);
                }

                Cache::forever(self::OFFSET_KEY, ((int) $update['update_id']) + 1);
            }

            if ($this->option('once')) {
                $this->info(count($updates).' update(s) processed.');
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /**
     * One line per update, so the journal shows the handshake happening.
     * Phone numbers are truncated - this ends up in a log file that is not as
     * private as the database column it is on its way to.
     */
    private function describe(array $update): void
    {
        $message = $update['message'] ?? [];
        $from    = $message['from']['username'] ?? $message['from']['id'] ?? '?';

        if (isset($message['contact'])) {
            $own = ($message['contact']['user_id'] ?? 0) === ($message['from']['id'] ?? -1);

            $this->line(sprintf(
                '  contact from @%s ...%s (%s)',
                $from,
                substr((string) ($message['contact']['phone_number'] ?? ''), -4),
                $own ? 'own number' : 'SOMEONE ELSE - will be refused',
            ));

            return;
        }

        $this->line(sprintf('  message from @%s: %s', $from, $message['text'] ?? '(no text)'));
    }
}
