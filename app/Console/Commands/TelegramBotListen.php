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
 * Outbound HTTPS only, so it works from a LAN box with no public address -
 * which webhooks would require.
 */
class TelegramBotListen extends Command
{
    protected $signature = 'remarket:telegram-bot
                            {--once : Process one batch and exit, for testing or a cron fallback}';

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
            $this->error('TELEGRAM_BOT_TOKEN is not set.');

            return self::FAILURE;
        }

        if ($me = $bot->getMe()) {
            $this->info('Listening as @'.($me['username'] ?? '?').'. Ctrl+C to stop.');
        } else {
            $this->error('Telegram rejected the token - check TELEGRAM_BOT_TOKEN.');

            return self::FAILURE;
        }

        do {
            $offset  = (int) Cache::get(self::OFFSET_KEY, 0);
            $updates = $bot->getUpdates($offset, timeout: $this->option('once') ? 0 : 25);

            foreach ($updates as $update) {
                try {
                    $verifier->handle($update);
                } catch (\Throwable $e) {
                    // One malformed update must not take the worker down and
                    // leave every user's verification silently broken.
                    $this->error('update failed: '.$e->getMessage());
                    report($e);
                }

                Cache::forever(self::OFFSET_KEY, ((int) $update['update_id']) + 1);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
