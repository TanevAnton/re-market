<?php

namespace App\Livewire\Bundles;

use App\Models\Bundle;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The seller's own bundles.
 *
 * Deliberately thin: everything that can be done to a bundle is done on its
 * own management screen, and a second set of action buttons here would be a
 * second set of guards to keep in step with the first.
 */
class MyBundles extends Component
{
    #[Layout('components.layouts.app')]
    public function render()
    {
        $bundles = Bundle::query()
            ->where('user_id', auth()->id())
            ->with(['listings' => fn ($q) => $q->with('images')])
            ->latest()
            ->get();

        return view('livewire.bundles.my-bundles', [
            'bundles' => $bundles,
        ])->layoutData([
            'title'       => 'Моите комплекти',
            'description' => 'Групите обяви, които продаваш заедно.',
        ]);
    }
}
