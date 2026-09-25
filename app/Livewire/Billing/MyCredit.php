<?php

namespace App\Livewire\Billing;

use App\Models\Boost;
use App\Models\CreditTransaction;
use App\Services\Billing\CreditService;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The seller's balance, and every row that made it.
 *
 * THE HISTORY IS THE POINT, not the number at the top. A seller who paid for
 * a pin and then sold the item has three rows — the top-up, the spend, the
 * partial refund — and without a screen that shows them, the balance is a
 * number the site asserts and the seller has to trust. That is exactly the
 * relationship this marketplace is supposed to be the opposite of.
 *
 * Read-only. Every row is written by CreditService and the table refuses
 * UPDATE at the database level, so there is nothing here to edit.
 */
class MyCredit extends Component
{
    use WithPagination;

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.billing.my-credit', [
            'balance' => app(CreditService::class)->balance(auth()->user()),
            'rows'    => CreditTransaction::where('user_id', auth()->id())
                /*
                 * The morph target is a Boost on every spend and refund, and
                 * the row links to the listing it promoted. morphWith() rather
                 * than `with('source.listing')`: a morphTo cannot eager-load a
                 * nested relation blindly, because not every target has one —
                 * and without this the page is a query per row.
                 */
                ->with(['source' => fn (MorphTo $m) => $m->morphWith([Boost::class => ['listing']])])
                ->latest('id')
                ->paginate(30),
        ]);
    }
}
