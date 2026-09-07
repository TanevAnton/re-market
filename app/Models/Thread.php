<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function unreadCountFor(User $user): int
    {
        $since = $user->id === $this->buyer_id ? $this->buyer_read_at : $this->seller_read_at;

        return $this->messages()
            ->where('sender_id', '!=', $user->id)
            ->when($since, fn ($q) => $q->where('created_at', '>', $since))
            ->count();
    }
}
