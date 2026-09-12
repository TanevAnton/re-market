<?php

namespace App\Livewire\Messages;

use App\Models\Thread;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Inbox extends Component
{
    /** @return Collection<int, Thread> */
    public function threads(): Collection
    {
        return Thread::query()
            ->with(['listing.images', 'buyer', 'seller', 'messages' => fn ($q) => $q->latest('id')->limit(1)])
            ->where(fn ($q) => $q->where('buyer_id', auth()->id())->orWhere('seller_id', auth()->id()))
            // A thread nobody has written in yet has a null last_message_at and
            // belongs at the bottom, not the top - Postgres sorts NULLs first
            // on DESC unless told otherwise.
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->limit(100)
            ->get();
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $threads = $this->threads();

        return view('livewire.messages.inbox', [
            'threads' => $threads,
            // Batched here rather than asked per row: the view was calling
            // unreadCountFor() inside the loop, which is a query per thread.
            'unread'  => Thread::unreadCountsFor(auth()->id(), $threads->modelKeys()),
        ]);
    }
}
