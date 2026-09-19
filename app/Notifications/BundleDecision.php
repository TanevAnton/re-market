<?php

namespace App\Notifications;

use App\Models\Bundle;
use App\Models\User;

/**
 * The same DSA Art. 17 obligation as ModerationDecision, for the other thing
 * a moderator can now remove.
 *
 * A separate class rather than a second constructor signature on that one:
 * every line of copy here differs — the noun, the link, the action label —
 * and the one sentence that must NOT differ is the statement of reasons, which
 * is passed through whole and unedited in both.
 *
 * The sentence that only exists here is the last one. A seller whose bundle is
 * taken down will assume their eight listings went with it, and being told
 * otherwise by an email rather than by counting them is the difference between
 * a moderation decision and a scare.
 */
class BundleDecision extends RemarketNotification
{
    public function __construct(
        private readonly Bundle $bundle,
        private readonly bool $approved,
        private readonly ?string $statement = null,
    ) {}

    public function subject(User $user): string
    {
        return $this->approved
            ? 'Комплектът ти е одобрен — „'.$this->bundle->title.'“'
            : 'Комплектът ти е премахнат — „'.$this->bundle->title.'“';
    }

    public function lines(User $user): array
    {
        if ($this->approved) {
            return ['Комплектът вече е публичен. Обявите в него се продават и поотделно.'];
        }

        return array_filter([
            $this->statement,
            'Обявите в комплекта остават активни — премахнат е само комплектът.',
        ]);
    }

    public function url(User $user): string
    {
        return $this->approved
            ? route('bundle', $this->bundle)
            : route('bundles.mine');
    }

    public function action(User $user): string
    {
        return $this->approved ? 'Виж комплекта' : 'Моите комплекти';
    }
}
