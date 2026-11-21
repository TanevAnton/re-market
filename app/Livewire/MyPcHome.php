<?php

namespace App\Livewire;

use App\Support\MyPc;
use Livewire\Component;

/**
 * „Моят компютър" on the home page.
 *
 * The full form has eight fields and lives on its own page. This is the door to
 * it: three questions that switch on most of the check (platform, memory,
 * power), answered in place, and once there is anything saved the panel turns
 * into a summary of what the site now knows and what one more fact would add.
 *
 * It saves through MyPc, the same cleaning the full form uses, and it MERGES
 * into whatever is already stored - answering three questions here must never
 * wipe the five that were given on the full page.
 */
class MyPcHome extends Component
{
    /** The three that make the first answer worth having. */
    public const QUICK = ['socket', 'ram_type', 'psu_w'];

    /** @var array<string, string|int|null> */
    public array $quick = [];

    public function mount(): void
    {
        $this->quick = array_fill_keys(self::QUICK, '');
    }

    public function save(): void
    {
        // Only the three keys this form owns, whatever the request carries.
        $input = array_intersect_key($this->quick, array_flip(self::QUICK));

        $merged = array_merge(MyPc::current(), array_filter($input, fn ($v) => $v !== null && $v !== ''));

        MyPc::save($merged);

        $this->quick = array_fill_keys(self::QUICK, '');
    }

    public function forget(): void
    {
        MyPc::forget();
    }

    public function render()
    {
        $pc     = MyPc::current();
        $fields = MyPc::fields();

        return view('livewire.my-pc-home', [
            'pc'      => $pc,
            'fields'  => $fields,
            'filled'  => count($pc),
            'total'   => count($fields),
            'missing' => array_values(array_diff(array_keys($fields), array_keys($pc))),
        ]);
    }
}
