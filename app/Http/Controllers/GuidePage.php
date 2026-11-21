<?php

namespace App\Http\Controllers;

use App\Support\Guides;
use Illuminate\Contracts\View\View;

/**
 * /rakovodstva and /rakovodstva/{guide}.
 *
 * A controller and plain views rather than Livewire components: the pages have
 * no state, should rank, and should keep rendering if every component on the
 * site is broken - the same reasons the sitemap and the legal pages are not
 * components. The text lives in config/guides.php.
 */
class GuidePage
{
    public function index(): View
    {
        return view('guides.index', ['guides' => Guides::all()]);
    }

    public function show(string $guide): View
    {
        $data = Guides::find($guide);

        abort_if($data === null, 404);

        return view('guides.show', [
            'slug'   => $guide,
            'guide'  => $data,
            'jsonLd' => Guides::jsonLd($guide, $data),
        ]);
    }
}
