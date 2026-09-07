<?php

namespace App\Services\Moderation;

use App\Enums\ListingStatus;
use App\Enums\ModerationTrigger;
use App\Enums\RejectionReason;
use App\Models\Listing;
use App\Models\ModerationItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Every moderation decision goes through here.
 *
 * Scattering `$listing->status = Removed` through controllers is how a platform
 * ends up unable to answer "why was this taken down, by whom, and what were
 * they told" - which is a legal question under the DSA, not only an engineering
 * one. One door in, one record out.
 */
class ModerationService
{
    /**
     * Put something in the queue, at most once.
     *
     * Idempotent by design: publishing twice, or an automated check running
     * again, must not ask a moderator the same question twice. The database
     * enforces this too (partial unique index) - this is the friendly path and
     * the index is the one that holds under a race.
     */
    public function enqueue(Model $subject, ModerationTrigger $trigger, array $context = []): ModerationItem
    {
        $pending = fn () => ModerationItem::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('trigger', $trigger->value)
            ->where('status', 'pending');

        if ($existing = $pending()->first()) {
            return $existing;
        }

        try {
            // forceFill, not create(): `status` is deliberately NOT fillable, so
            // that nothing which takes user input can ever write a row that is
            // already decided. Same rule as Listing::status, same reason.
            $item = new ModerationItem();

            $item->forceFill([
                'subject_type' => $subject->getMorphClass(),
                'subject_id'   => $subject->getKey(),
                'trigger'      => $trigger,
                'priority'     => $trigger->priority(),
                'context'      => $context,
                'status'       => 'pending',
            ])->save();

            return $item;
        } catch (UniqueConstraintViolationException) {
            // Two requests got past the check above at the same moment. The
            // index did its job; read back whichever one won.
            return $pending()->firstOrFail();
        }
    }

    /**
     * The listing is fine. Publish it.
     *
     * The expiry clock restarts here rather than at submission: a listing that
     * waited three days in the queue should not be three days closer to
     * expiring because we were slow.
     */
    public function approve(ModerationItem $item, User $moderator): ModerationItem
    {
        return DB::transaction(function () use ($item, $moderator) {
            $item = $this->lockPending($item, $moderator);

            $subject = $item->subject;

            if ($subject instanceof Listing && $subject->status === ListingStatus::PendingReview) {
                $subject->forceFill([
                    'status'     => ListingStatus::Active,
                    'bumped_at'  => now(),
                    'expires_at' => now()->addDays(config('remarket.listings.expire_after_days', 60)),
                ])->save();
            }

            $item->forceFill([
                'status'     => 'approved',
                'decided_by' => $moderator->id,
                'decided_at' => now(),
            ])->save();

            Log::info('[moderation] approved', [
                'item' => $item->id, 'by' => $moderator->id,
            ]);

            return $item;
        });
    }

    /**
     * The listing is not fine, and the seller has to be told why.
     *
     * `$facts` is required and cannot be a restatement of the category. A
     * seller who is told "Забранен артикул" learns nothing they can act on or
     * argue with; "обявата предлага лицензен ключ, а не хардуер" is a claim
     * they can dispute. DSA Art. 17(3)(b) calls this the facts and
     * circumstances relied on, and it is the part that cannot be templated.
     */
    public function reject(
        ModerationItem $item,
        User $moderator,
        RejectionReason $reason,
        string $facts,
    ): ModerationItem {
        $facts = trim($facts);

        if (mb_strlen($facts) < 10) {
            throw new RuntimeException('A rejection needs the facts it relies on.');
        }

        return DB::transaction(function () use ($item, $moderator, $reason, $facts) {
            $item = $this->lockPending($item, $moderator);

            $subject   = $item->subject;
            $statement = $this->statementOfReasons($item, $reason, $facts);

            if ($subject instanceof Listing) {
                $subject->forceFill(['status' => ListingStatus::Removed])->save();
            }

            $item->forceFill([
                'status'               => 'rejected',
                'decided_by'           => $moderator->id,
                'decision_reason'      => $reason->value,
                'statement_of_reasons' => $statement,
                'decided_at'           => now(),
            ])->save();

            Log::info('[moderation] rejected', [
                'item' => $item->id, 'by' => $moderator->id, 'reason' => $reason->value,
            ]);

            return $item;
        });
    }

    /**
     * What the seller reads. Composed once and stored verbatim, because the
     * record of what someone was actually told is worth more than the ability
     * to regenerate it later from templates that will have changed by then.
     */
    private function statementOfReasons(ModerationItem $item, RejectionReason $reason, string $facts): string
    {
        $subject = $item->subject;
        $title   = $subject instanceof Listing ? $subject->title : '—';

        // Art. 17(3)(c): whether automation was involved. The DETECTION often
        // is; the decision never is. Saying so plainly is the honest answer and
        // also the one that survives being quoted back at us.
        $detection = in_array($item->trigger, [
            ModerationTrigger::PhashCollision,
            ModerationTrigger::PriceOutlier,
            ModerationTrigger::ContactInfo,
        ], true)
            ? 'Обявата беше отбелязана за проверка автоматично ('.$item->trigger->label()
                .'), но решението е взето от човек.'
            : 'Решението е взето от човек, без автоматизирани средства.';

        $redress = ($email = config('remarket.support_email'))
            ? "Ако смяташ, че решението е грешно, можеш да го оспориш на {$email} в срок от 6 месеца. "
                ."Имаш право и да се обърнеш към извънсъдебен орган за решаване на спорове или към съд."
            : 'Ако смяташ, че решението е грешно, можеш да го оспориш през формата за контакт в срок '
                .'от 6 месеца. Имаш право и да се обърнеш към извънсъдебен орган за решаване на спорове '
                .'или към съд.';

        $repost = $reason->isFixable()
            ? 'Можеш да коригираш описаното и да публикуваш обявата отново.'
            : 'Повторно публикуване на същия артикул ще доведе до ограничаване на профила.';

        return implode("\n\n", [
            'Решение: обявата „'.$title.'“ е премахната от платформата. '
                .'Ограничението важи за всички потребители и е безсрочно.',
            'Причина: '.$reason->label(),
            'Установени факти: '.$facts,
            'Основание: '.$reason->ground(),
            $detection,
            $repost,
            $redress,
        ]);
    }

    /**
     * Take the row, check it is still ours to decide, and refuse self-review.
     *
     * The lock matters: two moderators opening the queue together would
     * otherwise both act on the same listing, and the second decision would
     * silently overwrite the first - including its statement of reasons.
     */
    private function lockPending(ModerationItem $item, User $moderator): ModerationItem
    {
        $fresh = ModerationItem::whereKey($item->getKey())->lockForUpdate()->firstOrFail();

        if ($fresh->status !== 'pending') {
            throw new RuntimeException('Тази обява вече е прегледана.');
        }

        $subject = $fresh->subject;

        // An admin approving their own listing is the check reviewing itself.
        if ($subject instanceof Listing && $subject->user_id === $moderator->id) {
            throw new RuntimeException('Не можеш да прегледаш собствената си обява.');
        }

        return $fresh;
    }
}
