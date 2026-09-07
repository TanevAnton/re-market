<?php

namespace App\Models;

use App\Enums\DealStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Thread extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['listing_id', 'buyer_id', 'seller_id'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'buyer_read_at'   => 'datetime',
            'seller_read_at'  => 'datetime',
            'is_locked'       => 'boolean',
        ];
    }

    public function uniqueIds(): array        { return ['uuid']; }
    public function getRouteKeyName(): string { return 'uuid'; }

    public function listing(): BelongsTo  { return $this->belongsTo(Listing::class); }
    public function buyer(): BelongsTo    { return $this->belongsTo(User::class, 'buyer_id'); }
    public function seller(): BelongsTo   { return $this->belongsTo(User::class, 'seller_id'); }
    public function messages(): HasMany   { return $this->hasMany(Message::class)->oldest(); }

    /**
     * Whether these two may swap phone numbers and handles in this thread.
     *
     * Before they have agreed anything, contact details are stripped: a
     * marketplace whose first move is always "пиши ми на вайбър" has no record
     * of any offer, deal or reputation, and no way to tell a scam from a
     * misunderstanding afterwards.
     *
     * Once a deal exists that changes completely. They have to arrange a
     * handover, the platform stores phone numbers only as an HMAC and so
     * cannot hand one over itself, and a masked message at that point is the
     * app obstructing the transaction it just helped create.
     */
    public function allowsContactExchange(): bool
    {
        return Deal::query()
            ->where('listing_id', $this->listing_id)
            ->where('buyer_id', $this->buyer_id)
            ->where('seller_id', $this->seller_id)
            ->whereIn('status', [DealStatus::Open, DealStatus::Completed])
            ->exists();
    }

    public function counterparty(User $viewer): User
    {
        return $viewer->id === $this->buyer_id ? $this->seller : $this->buyer;
    }

    /**
     * Everything unread by this user, across every thread, in one query.
     *
     * The obvious version - loop the threads and call unreadCountFor() - is an
     * N+1 on a header badge that renders on every page in the site.
     */
    public static function unreadTotalFor(int $userId): int
    {
        return DB::table('messages')
            ->join('threads', 'threads.id', '=', 'messages.thread_id')
            ->where('messages.sender_id', '!=', $userId)
            ->where(function ($q) use ($userId) {
                $q->where(function ($b) use ($userId) {
                    $b->where('threads.buyer_id', $userId)
                        ->where(fn ($x) => $x->whereNull('threads.buyer_read_at')
                            ->orWhereColumn('messages.created_at', '>', 'threads.buyer_read_at'));
                })->orWhere(function ($s) use ($userId) {
                    $s->where('threads.seller_id', $userId)
                        ->where(fn ($x) => $x->whereNull('threads.seller_read_at')
                            ->orWhereColumn('messages.created_at', '>', 'threads.seller_read_at'));
                });
            })
            ->count();
    }

    public function unreadCountFor(User $user): int
    {
        $since = $user->id === $this->buyer_id ? $this->buyer_read_at : $this->seller_read_at;

        return $this->messages()
            ->where('sender_id', '!=', $user->id)
            ->when($since, fn ($q) => $q->where('created_at', '>', $since))
            ->count();
    }
}
