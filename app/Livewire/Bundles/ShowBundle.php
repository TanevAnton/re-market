<?php

namespace App\Livewire\Bundles;

use App\Models\Bundle;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * „Цялата машина, или на части."
 *
 * The page a package price lives on. Every member is a real listing with its
 * own page, its own photographs and its own moderation history — this page
 * adds the grouping and the discount, and never replaces them.
 *
 * WHO CAN SEE IT: an Active bundle, to anyone. A draft or one waiting for
 * review, to its owner and to moderators only. 404 rather than 403 for
 * everyone else, for the reason every other owner-only screen here does it:
 * a 403 confirms the URL is real.
 */
class ShowBundle extends Component
{
    public Bundle $bundle;

    public function mount(Bundle $bundle): void
    {
        $user = auth()->user();

        $mine = $user && ($user->id === $bundle->user_id || $user->is_admin);

        abort_unless($bundle->status->isPubliclyVisible() || $mine, 404);

        /*
         * An empty bundle is a dead end with a price on it. Members leave on
         * their own — a listing sells, or the seller detaches it — so this is
         * reachable without anybody deleting anything.
         */
        abort_if($bundle->listings()->doesntExist() && ! $mine, 404);

        $this->bundle = $bundle;
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $this->bundle->load(['listings.images', 'listings.city', 'listings.part', 'user']);

        $title = $this->bundle->title.' — комплект';

        return view('livewire.bundles.show-bundle')->layoutData([
            'title'       => $title,
            'description' => $this->description(),
            // Only a public bundle gets a canonical URL. Telling a crawler the
            // canonical address of a page it is about to be 404'd from is the
            // kind of signal that takes weeks to undo.
            'canonical'   => $this->bundle->status->isPubliclyVisible()
                ? route('bundle', $this->bundle)
                : null,
        ]);
    }

    private function description(): string
    {
        $count = $this->bundle->listings->count();
        $price = $this->bundle->formattedPackagePrice();

        return $price
            ? "{$count} части, заедно за {$price}. Всяка се продава и поотделно."
            : "{$count} части от една машина, от един продавач.";
    }
}
