<?php

namespace App\Models;

use App\Services\Images\ImageProcessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One unfinished listing per seller.
 *
 * The payload is this component's own state, written by the server and read
 * back only for the same user — see ListingDraft::PERSISTED for exactly which
 * keys survive the round trip. It is deliberately an allow-list rather than a
 * blanket fill: a draft is user-influenced data coming back into typed
 * properties, and "restore whatever is in the JSON" is how a stored value ends
 * up somewhere nobody meant it to reach.
 */
class ListingDraft extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'payload', 'step', 'title', 'category'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'step'    => 'integer',
        ];
    }

    /**
     * The wizard properties worth keeping.
     *
     * `photos` is absent on purpose — it holds Livewire temporary upload
     * objects mid-request, which are neither serialisable nor meaningful an
     * hour later. `stored` is what survives, because those files are already
     * on disk.
     */
    public const PERSISTED = [
        'category', 'partId', 'partNotListed', 'customPart', 'partSearch',
        'title', 'description', 'condition', 'quantity', 'specs',
        'mining_use', 'mining_months', 'warranty_until', 'has_receipt',
        'validation_url', 'stored', 'timestampIndex',
        'price', 'offers_enabled', 'min_offer', 'delivery_options',
        'accepts_inspect_test', 'city_id', 'showOptional',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Is there anything here worth offering to restore? */
    public function isSubstantial(): bool
    {
        $p = $this->payload ?? [];

        return filled($p['title'] ?? null)
            || filled($p['description'] ?? null)
            || ! empty($p['stored'])
            || filled($p['price'] ?? null);
    }

    /** How many photos are already on disk behind this draft. */
    public function photoCount(): int
    {
        return count($this->payload['stored'] ?? []);
    }

    public function scopeStale(Builder $q, int $days): Builder
    {
        return $q->where('updated_at', '<', now()->subDays($days));
    }

    /**
     * Delete the draft AND the images it was holding.
     *
     * This is the whole reason drafts pay for themselves twice. The wizard
     * writes every photo to disk the moment it is uploaded, so before drafts
     * existed an abandoned wizard left its images behind permanently, with no
     * row anywhere pointing at them and nothing to find them by. Now they have
     * an owner, and the owner can be swept.
     */
    public function discard(?ImageProcessor $images = null): void
    {
        $images ??= new ImageProcessor();

        foreach ($this->payload['stored'] ?? [] as $image) {
            // Filtered before the call, not inside it: delete() takes
            // `string ...$paths`, so a null thumb is a TypeError rather than
            // the no-op it looks like.
            $paths = array_filter([$image['path'] ?? null, $image['thumb'] ?? null]);

            if ($paths !== []) {
                $images->delete(...$paths);
            }
        }

        $this->delete();
    }
}
