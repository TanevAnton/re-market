<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Verification\TelegramBot;
use Illuminate\Notifications\Notification;

/**
 * Delivers a notification through the bot that already verified the user.
 *
 * No new credentials, no per-message cost, and no new consent to ask for: the
 * user opened this conversation themselves to prove their number, so the
 * channel exists precisely because they chose it.
 *
 * Named for its namespace rather than shortened, because
 * App\Services\Verification\Channels\TelegramChannel already exists and does
 * something entirely different - it sends one-time codes through the paid
 * Gateway API. Two Telegram channels, two purposes, deliberately distinct.
 */
class TelegramChannel
{
    public function __construct(
        private readonly TelegramBot $bot,
    ) {}

    public function send(User $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toTelegram')) {
            return;
        }

        $chatId = (int) ($notifiable->telegram_chat_id ?? 0);

        if ($chatId === 0) {
            return;
        }

        // TelegramBot swallows its own transport failures and logs them: one
        // unreachable chat - a user who blocked the bot - must not fail the
        // queued job and hold up everyone else's notifications behind retries.
        $this->bot->say($chatId, $notification->toTelegram($notifiable));
    }
}
