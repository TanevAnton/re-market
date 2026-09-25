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

    /**
     * Statuses that still render publicly, as a tombstone rather than a 404.
     *
     * Sold and expired only. Removed stays a 404 for everyone but its owner and
     * a moderator: that listing was taken down by a decision, and serving it
     * again - even with a banner over it - publishes the content the decision
     * was about.
     */
    private const TOMBSTONED = [
        ListingStatus::Sold,
        ListingStatus::Expired,
    ];

    public function mount(Listing $listing): void
    {
        // A listing awaiting review is not public - but its owner must still be
        // able to see it, or publishing lands the seller on a 404 immediately
        // after we tell them the listing was submitted.
        $viewer = auth()->user();

        $canView = $listing->status->isPubliclyVisible()
            // Sold and expired listings stay reachable as a tombstone. On a
            // marketplace that grows by word of mouth, every link pasted into a
            // Viber group became a dead end the moment the card sold - and the
            // person following it is somebody actively shopping for exactly
            // this model. Removed is NOT here: a moderator took that down, and
            // re-serving it would walk around the decision.
            || in_array($listing->status, self::TOMBSTONED, true)
            || $viewer?->id === $listing->user_id
            || $viewer?->is_admin;

        abort_unless($canView, 404);

        $this->listing = $listing->load(['part', 'city', 'user', 'images']);

        // Own views do not count, and there is no reason to touch updated_at.
        if ($viewer?->id !== $listing->user_id) {
            Listing::whereKey($listing->getKey())->increment('view_count');
        }
    }

    /**
     * True when the viewer is seeing something the public cannot.
     *
     * A tombstone is excluded: sold and expired listings ARE public now, and
     * showing the owner-only "не е публична" banner over a page every passer-by
     * can read would be both wrong and confusing.
     */
    public function isPrivateView(): bool
    {
        return ! $this->listing->status->isPubliclyVisible()
            && ! $this->isTombstone();
    }

    /** Public, but nothing here is for sale any more. */
    public function isTombstone(): bool
    {
        return in_array($this->listing->status, self::TOMBSTONED, true);
    }

    /**
     * What to show somebody who followed a link to something already gone.
     *
     * Same catalogue part first, because that is the same card; the rest of the
     * category after, because somebody shopping for a 4070 will look at a 4070
     * Super. Without this the page is a dead end with a sentence on it, which
     * is barely better than the 404 it replaced.
     */
    public function similar(): \Illuminate\Support\Collection
    {
        $query = Listing::query()
            ->active()
            ->whereKeyNot($this->listing->getKey())
            ->with(['images', 'city', 'part', ...\App\Support\Boosted::eagerLoad()]);

        $samePart = $this->listing->part_id
            ? (clone $query)->where('part_id', $this->listing->part_id)->latest('bumped_at')->limit(6)->get()
            : collect();

        if ($samePart->count() >= 3) {
            return $samePart;
        }

        // Topped up from the category rather than replaced, so the exact model
        // still leads when there is one of it.
        return $samePart->concat(
            (clone $query)
                ->where('category', $this->listing->category)
                // whereNotIn rather than whereKeyNot: the array-handling of
                // whereKeyNot was not verifiable from here, and an empty array
                // is an explicit no-op for whereNotIn.
                ->whereNotIn('id', $samePart->modelKeys())
                ->latest('bumped_at')
                ->limit(6 - $samePart->count())
                ->get()
        );
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

    /**
     * The sentence under the link in a search result or a shared card.
     *
     * Built from the facts a buyer decides on rather than the seller's own
     * description, which on a used marketplace is as often "спешно!!!" as it is
     * useful. Condition, price, location, and the two things that actually
     * separate two identical cards: warranty and whether you may test it before
     * paying.
     */
    public function metaDescription(): string
    {
        $bits = [
            $this->listing->condition->label(),
            $this->listing->formattedPrice(),
        ];

        if ($city = $this->listing->city?->name()) {
            $bits[] = $city;
        }

        if ($this->listing->isWarrantied()) {
            $bits[] = 'с гаранция';
        }

        if ($this->listing->accepts_inspect_test) {
            $bits[] = 'преглед и тест при получаване';
        }

        // The seller's own words, trimmed, after the facts - so a shared card
        // still reads like a specific item rather than a spec dump.
        $own = trim(preg_replace('/\s+/', ' ', (string) $this->listing->description));

        return implode(' · ', $bits).'. '.mb_substr($own, 0, 110);
    }

    /**
     * The photo that appears when the link is pasted into Viber, Messenger or
     * a Telegram group - which is how a Bulgarian marketplace actually spreads.
     *
     * The full image, not the thumbnail: previews are rendered large and a
     * 400px thumb comes out soft. Null when there is no photo, because a
     * declared og:image that 404s renders as a broken card rather than a
     * plain one.
     */
    public function ogImage(): ?string
    {
        return $this->listing->coverImage()?->url();
    }

    /**
     * Offer, not AggregateOffer: this is one specific item at one price.
     *
     * Emitted only while the listing is actually buyable. Marking a sold or
     * removed listing as InStock would be a claim about availability that the
     * page itself contradicts.
     */
    public function jsonLd(): string
    {
        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Product',
            'name'          => $this->listing->title,
            'description'   => mb_substr(strip_tags((string) $this->listing->description), 0, 500),
            'url'           => route('listing', $this->listing),
            'itemCondition' => 'https://schema.org/UsedCondition',
        ];

        if ($image = $this->ogImage()) {
            $schema['image'] = $image;
        }

        if ($part = $this->listing->part) {
            $schema['brand'] = ['@type' => 'Brand', 'name' => $part->manufacturer];
            $schema['model'] = $part->fullName();
        }

        if ($this->listing->status === ListingStatus::Active) {
            $schema['offers'] = [
                '@type'         => 'Offer',
                'price'         => round($this->listing->price_cents / 100, 2),
                'priceCurrency' => 'EUR',
                'availability'  => 'https://schema.org/InStock',
                'itemCondition' => 'https://schema.org/UsedCondition',
                'url'           => route('listing', $this->listing),
            ];
        }

        return json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.show-listing', [
            // Resolved here rather than in the view: see the note in the blade
            // about what a second @php block in that file would do.
            'similar' => $this->isTombstone() ? $this->similar() : collect(),
        ])->layoutData([
            'title'       => $this->isTombstone()
                ? $this->listing->title.' — '.$this->listing->status->label()
                : $this->listing->title,
            'description' => $this->metaDescription(),

            /*
             * A tombstone is noindex with its canonical pointing at the
             * catalogue page for the same model.
             *
             * The SEO reason the page used to 404 is sound - a site full of
             * "sold" pages competing with its own live listings ranks worse
             * than one without them - and none of it required throwing away
             * the human following a link somebody pasted in a Viber group.
             * noindex keeps it out of the index; the canonical sends whatever
             * authority the link earned to the page that will still be here
             * next year.
             *
             * `follow`, not `nofollow`: the whole point is the links to what
             * IS for sale.
             */
            'noindex'     => $this->isTombstone(),
            'canonical'   => $this->isTombstone() && $this->listing->part
                ? route('part', $this->listing->part)
                : route('listing', $this->listing),
            'ogType'      => 'product',
            'ogImage'     => $this->ogImage(),
            'jsonLd'      => $this->jsonLd(),
        ]);
    }
}
