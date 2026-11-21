<?php

namespace App\Livewire;

use App\Support\MyPc;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * „Моят компютър" - the form behind the fit check.
 *
 * Eight optional fields. Nothing is required, because every one of them only
 * switches on one more line of the check, and a form that demands all eight
 * before it will say anything is a form that gets closed.
 *
 * The values are cleaned by MyPc::save(), not here: a select that is posted a
 * value that was never an option, or a wattage of nine million, is dropped
 * there, so the rule exists once and a forged request meets the same one.
 */
class MyComputer extends Component
{
    /** @var array<string, string|int|null> */
    public array $pc = [];

    public bool $saved = false;

    public function mount(): void
    {
        $this->pc = MyPc::current() + array_fill_keys(array_keys(MyPc::fields()), '');
    }

    public function save(): void
    {
        $this->pc = MyPc::save($this->pc) + array_fill_keys(array_keys(MyPc::fields()), '');
        $this->saved = true;
    }

    public function clear(): void
    {
        MyPc::forget();

        $this->pc    = array_fill_keys(array_keys(MyPc::fields()), '');
        $this->saved = false;
    }

    /** A change after saving means the „запазено" line is no longer true. */
    public function updatedPc(): void
    {
        $this->saved = false;
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.my-computer', [
            'fields' => MyPc::fields(),
            'groups' => MyPc::groups(),
        ])->layoutData([
            'title'       => 'Моят компютър',
            'description' => 'Кажи ни какво има в компютъра ти и ще проверяваме дали всяка част пасва.',
            // A personal settings page has nothing to offer a search engine.
            'noindex'     => true,
        ]);
    }
}
