<?php

namespace App\Livewire\SavedSearches;

use App\Models\TelegramLink;
use Livewire\Component;

/**
 * „Получавай известията в Telegram".
 *
 * Saved-search alerts are the one message a buyer wants within minutes, and
 * Telegram is the one channel that is free, instant and already open on their
 * phone. Until this existed the only way to connect it was to verify a phone
 * number through the bot - which is switched off - so every alert went to
 * e-mail, and an hourly e-mail about a card that sells in an hour is a slow way
 * to find out.
 *
 * Three states, decided from the account rather than from component state so a
 * page reload cannot show the wrong one:
 *  - not configured : renders nothing. A button that cannot work is worse than
 *    no button.
 *  - not connected  : the button, then the deep link and a poll for the result.
 *  - connected      : one quiet line, and where to switch it off.
 */
class ConnectTelegram extends Component
{
    public ?string $url = null;

    /** Inside another card already: no box of its own. */
    public bool $bare = false;

    public ?string $error = null;

    public function configured(): bool
    {
        return filled(config('remarket.verify.telegram_bot_token'));
    }

    public function connected(): bool
    {
        return filled(auth()->user()?->fresh()?->telegram_chat_id);
    }

    /** A fresh single-use link. Nothing is stored about the person but the nonce. */
    public function start(): void
    {
        $this->error = null;

        if (! $this->configured() || ! auth()->check()) {
            return;
        }

        $link = TelegramLink::issueFor(
            auth()->user(),
            (int) config('remarket.verify.telegram_link_ttl', 15),
            TelegramLink::PURPOSE_ALERTS,
        );

        $this->url = $link->deepLink();

        if ($this->url === null) {
            $this->error = 'Telegram не отговаря в момента. Опитай пак след малко.';
        }
    }

    public function render()
    {
        return view('livewire.saved-searches.connect-telegram', [
            'configured' => $this->configured(),
            'connected'  => $this->connected(),
            'enabled'    => (bool) auth()->user()?->notify_telegram,
        ]);
    }
}
