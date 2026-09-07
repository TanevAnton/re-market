<?php

namespace App\Livewire\Profile;

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

class ShowProfile extends Component
{
    use WithPagination;

    public User $user;

    public function mount(string $username): void
    {
        $this->user = User::whereRaw('lower(username) = ?', [mb_strtolower($username)])
            ->firstOrFail();

        abort_if($this->user->banned_at !== null && ! auth()->user()?->is_admin, 404);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.profile.show-profile', [
            'listings' => $this->user->listings()
                ->visible()
                ->with(['part', 'city', 'images'])
                ->latest('bumped_at')
                ->paginate(12),
        ]);
    }
}
