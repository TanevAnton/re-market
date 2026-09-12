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
        return $this->currentDeal() !== null;
    }

    /**
     * The live deal between these two on this listing, if there is one.
     *
     * The rule for "are these two past the handshake" lives here and nowhere
     * else: contact masking asks it as a yes/no through allowsContactExchange(),
     * and the thread view asks it for the object so it can show what was agreed.
     * Two copies of the status list is how the banner ends up saying one thing
     * and the scrubber doing another.
     *
     * Deliberately NOT memoised on the instance. It is tempting - the thread
     * view and the scrubber both ask - but this is the check that decides
     * whether a phone number gets stripped out of somebody's message, and a
     * cached "no deal yet" on a model instance that outlives the moment a deal
     * is struck is a masking bug that only shows up in production. One query
     * per ask, every time.
     */
    public function currentDeal(): ?Deal
    {
        return Deal::query()
            ->where('listing_id', $this->listing_id)
            ->where('buyer_id', $this->buyer_id)
            ->where('seller_id', $this->seller_id)
            ->whereIn('status', [DealStatus::Open, DealStatus::Completed])
            ->latest('id')
            ->first();
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
        return self::unreadQuery($userId)->count();
    }

    /**
     * Unread counts for a page of threads, keyed by thread id, in one query.
     *
     * The inbox was calling unreadCountFor() inside the row loop - one query
     * per conversation, up to a hundred of them, on a page that is already
     * loading a listing and its images for every row. The comment above is
     * about exactly this mistake; the inbox made it anyway, because there was
     * no batched version to reach for.
     *
     * @param  list<int>  $threadIds
     * @return array<int, int>
     */
    public static function unreadCountsFor(int $userId, array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        // get(), not pluck(): pluck replaces the select list with the two column
        // names it was given, which would throw away the count(*) this depends
        // on and leave the aggregate ungrouped.
        $rows = self::unreadQuery($userId)
            ->whereIn('threads.id', $threadIds)
            ->groupBy('threads.id')
            ->selectRaw('threads.id as thread_id, count(*) as unread')
            ->get();

        return collect($rows)
            ->mapWithKeys(fn ($row) => [(int) $row->thread_id => (int) $row->unread])
            ->all();
    }

    /**
     * Messages this user has not read yet. One definition of "unread", shared
     * by the header badge and the inbox - two places that must never be able
     * to disagree about whether there is something waiting.
     */
    private static function unreadQuery(int $userId): \Illuminate\Database\Query\Builder
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
            });
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
