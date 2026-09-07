<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class PhoneVerification extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'phone_hash', 'channel', 'code_hash', 'ip', 'expires_at'];

    protected $hidden = ['code_hash', 'phone_hash'];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** The OTP is never stored in the clear, not even for ten minutes. */
    public function matches(string $code): bool
    {
        return Hash::check($code, $this->code_hash);
    }

    public function isUsable(): bool
    {
        return $this->verified_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < config('remarket.verify_max_attempts', 5);
    }
}
