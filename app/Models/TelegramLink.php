<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TelegramLink extends Model
{
    protected $fillable = ['user_id', 'nonce', 'telegram_chat_id', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A fresh handshake for this user, replacing any earlier one.
     *
     * Superseding rather than accumulating matters: a user who clicks the
     * button three times should end up with one live nonce, not three, or the
     * two stale ones stay valid for as long as their TTL and each is a second
     * way into the same account.
     */
    public static function issueFor(User $user, int $minutes = 15): self
    {
        static::where('user_id', $user->id)->whereNull('consumed_at')->delete();

        return static::create([
            'user_id'    => $user->id,
            // 32 hex chars. This travels in a URL the user may paste anywhere,
            // so it has to be unguessable rather than merely unique.
            'nonce'      => bin2hex(random_bytes(16)),
            'expires_at' => now()->addMinutes($minutes),
        ]);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    public function scopeUsable(Builder $q): Builder
    {
        return $q->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    /** The link the user actually clicks. */
    public function deepLink(): string
    {
        $bot = Str::of((string) config('remarket.verify.telegram_bot_username'))->ltrim('@');

        return "https://t.me/{$bot}?start={$this->nonce}";
    }
}
