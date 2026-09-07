<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'thread_id', 'sender_id', 'body', 'body_clean',
        'had_contact_info', 'had_price_talk',
    ];

    protected function casts(): array
    {
        return [
            'had_contact_info' => 'boolean',
            'had_price_talk'   => 'boolean',
            'read_at'          => 'datetime',
        ];
    }

    public function thread(): BelongsTo { return $this->belongsTo(Thread::class); }
    public function sender(): BelongsTo  { return $this->belongsTo(User::class, 'sender_id'); }

    /**
     * Recipients always see the scrubbed version. The original is retained so
     * that repeated attempts to move a deal off-platform can be evidenced
     * during moderation rather than merely asserted.
     */
    public function visibleBody(): string
    {
        return $this->body_clean;
    }
}
