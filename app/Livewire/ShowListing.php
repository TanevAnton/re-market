<?php

namespace App\Livewire;

use App\Enums\ListingStatus;
use App\Enums\ModerationTrigger;
use App\Enums\RejectionReason;
use App\Models\Listing;
use App\Models\ModerationItem;
use App\Services\Moderation\ModerationService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

class ShowListing extends Component
{
    public Listing $listing;

    /** Admin takedown form. */
    public bool $removing = false;
    public string $reason = '';
    public string $facts  = '';

    public function mount(Listing $listing): void
    {
        // A listing awaiting review is not public - but its owner must still be
        // able to see it, or publishing lands the seller on a 404 immediately
        // after we tell them the listing was submitted.
        $viewer = auth()->user();

        $canView = $listing->status->isPubliclyVisible()
            || $viewer?->id === $listing->user_id
            || $viewer?->is_admin;

        abort_unless($canView, 404);

        $this->listing = $listing->load(['part', 'city', 'user', 'images']);

        // Own views do not count, and there is no reason to touch updated_at.
        if ($viewer?->id !== $listing->user_id) {
            Listing::whereKey($listing->getKey())->increment('view_count');
        }
    }

    /** True when the viewer is seeing something the public cannot. */
    public function isPrivateView(): bool
    {
        return ! $this->listing->status->isPubliclyVisible();
    }

    /**
     * The moderation decision, if this listing was refused.
     *
     * Shown to the seller and to moderators only - isPrivateView already
     * establishes that, since anyone else got a 404 in mount(). The text is
     * read back rather than rebuilt: it is the record of what the person was
     * told, and rebuilding it from today's templates would quietly rewrite
     * history every time the wording changes.
     */
    public function statementOfReasons(): ?string
    {
        if ($this->listing->status !== ListingStatus::Removed) {
            return null;
        }

        return ModerationItem::where('subject_type', $this->listing->getMorphClass())
            ->where('subject_id', $this->listing->getKey())
            ->where('status', 'rejected')
            ->latest('decided_at')
            ->value('statement_of_reasons');
    }

    /**
     * Can this viewer take the listing down from here?
     *
     * Moderators only, and never their own listing - the same rule
     * ModerationService::lockPending enforces. Checking it here as well is not
     * duplication: it decides whether the button is drawn at all, and a button
     * that appears and then refuses is a worse experience than one that was
     * never there. The service still has the final word.
     */
    public function canModerate(): bool
    {
        return (bool) auth()->user()?->is_admin
            && auth()->id() !== $this->listing->user_id
            && $this->listing->status !== ListingStatus::Removed;
    }

    public function startRemove(): void
    {
        $this->removing = true;
        $this->reset(['reason', 'facts']);
        $this->resetErrorBag();
    }

    public function cancelRemove(): void
    {
        $this->reset(['removing', 'reason', 'facts']);
    }

    /**
     * Take it down, from the page rather than the queue.
     *
     * The queue only ever shows what was *flagged*. A moderator who runs into a
     * bad listing while browsing had no way to act on it without waiting for
     * something to report it first, which is the wrong way round.
     *
     * It goes through ModerationService like every other decision, so this
     * produces the same moderation record, the same stored statement of
     * reasons, and the same notification to the seller. A second, quieter
     * delete path would leave the site unable to answer "why was this taken
     * down, by whom, and what were they told" - which is a legal question under
     * DSA Art. 17, not an engineering preference.
     */
    public function remove(): void
    {
        abort_unless($this->canModerate(), 403);

        $this->validate([
            'reason' => ['required', 'string'],
            'facts'  => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'reason.required' => 'Избери причина.',
            'facts.required'  => 'Опиши какво точно е нередно.',
            'facts.min'       => 'Опиши какво точно е нередно - това го чете продавачът.',
        ]);

        $reason = RejectionReason::tryFrom($this->reason);

        if (! $reason) {
            $this->addError('reason', 'Избери причина.');

            return;
        }

        $moderation = app(ModerationService::class);

        try {
            /*
             * Decide whatever is already pending on this listing, whatever
             * flagged it. enqueue() is idempotent only per trigger, so opening
             * a fresh `manual` item on a listing already queued as
             * `new_account` would take the listing down and leave the original
             * item pending forever - a moderator would later open the queue and
             * be asked to review something that is already gone.
             */
            $item = ModerationItem::query()
                ->where('subject_type', $this->listing->getMorphClass())
                ->where('subject_id', $this->listing->getKey())
                ->where('status', 'pending')
                ->orderBy('id')
                ->first()
                ?? $moderation->enqueue($this->listing, ModerationTrigger::Manual, [
                    'from' => 'listing_page',
                ]);

            $moderation->reject($item, auth()->user(), $reason, $this->facts);
        } catch (RuntimeException $e) {
            $this->addError('facts', $e->getMessage());

            return;
        }

        $this->reset(['removing', 'reason', 'facts']);
        $this->listing->refresh();

        // No flash message: session('status') reads null after a Livewire round
        // trip. None is needed anyway - the page now shows the removed banner
        // and the statement of reasons that was just sent, which is better
        // confirmation than a sentence saying it worked.
    }

    /** @return array<string, string> */
    public function rejectionReasons(): array
    {
        return RejectionReason::options();
    }

    /**
     * The specs this category defines, in the order the catalogue schema
     * declares - so a GPU leads with chipset and VRAM rather than whatever
     * order the jsonb happens to serialise in.
     *
     * Listing-level values win over part-level ones: the part says what the
     * model is, the listing says what THIS unit is.
     */
    public function specRows(): array
    {
        if (! $this->listing->part) {
            return [];
        }

        $schema = config("catalog.categories.{$this->listing->category}.specs", []);
        $specs  = array_merge($this->listing->part->specs ?? [], $this->listing->specs ?? []);
        $locale = app()->getLocale();

        $rows = [];
        foreach ($schema as $key => $spec) {
            if (! array_key_exists($key, $specs)) {
                continue;
            }

            $value = $specs[$key];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $rows[] = [
                'label' => $spec['label'][$locale] ?? $spec['label']['en'] ?? $key,
                'value' => match (true) {
                    is_bool($value)  => $value ? 'да' : 'не',
                    is_array($value) => implode(', ', $value),
                    default          => (string) $value,
                },
                'unit'  => $spec['unit'] ?? null,
                'order' => $spec['priority'] ?? 99,
            ];
        }

        usort($rows, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $rows;
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.show-listing');
    }
}
