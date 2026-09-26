<?php

namespace App\Livewire\Safety;

use App\Services\Safety\StolenRegistry;
use App\Support\ItemIdentifier;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * „Is this serial known here?" — the check a buyer does before handing over
 * €600 in a car park.
 *
 * WHAT IT CAN HONESTLY SAY, and the wording is the feature. Three answers:
 *
 *   „Има подаден сигнал"    — somebody filed a police report naming this serial.
 *                             Not proof of anything; a reason to ask for papers.
 *   „Номерът е записан"     — a seller on this site recorded it. Weak but real:
 *                             a scammer copying a listing rarely has the object.
 *   „Не знаем нищо"         — and this is the one most easily misread. Silence
 *                             is not a clean bill of health, and the screen says
 *                             so, because a buyer who reads „no result" as „not
 *                             stolen" is worse off than one who never checked.
 *
 * SIGNED IN AND RATE-LIMITED. An open endpoint that answers „is this serial
 * reported" is also how a thief checks whether their haul is hot before listing
 * it. The buyer's need is larger, so the lookup exists — but it costs an
 * account and it stops after a few dozen an hour, which is plenty for a person
 * with a box of parts and useless for a sweep.
 */
class CheckSerial extends Component
{
    public string $kind  = ItemIdentifier::SERIAL;
    public string $value = '';

    /** @var array{known: bool, reported: bool, listings: int, last4: string}|null */
    public ?array $result = null;

    public function check(StolenRegistry $registry): void
    {
        $this->result = null;

        $this->validate([
            'kind'  => ['required', 'string'],
            'value' => ['required', 'string', 'max:64'],
        ], [
            'value.required' => 'Въведи номера.',
        ]);

        if ($problem = ItemIdentifier::problem($this->kind, $this->value)) {
            $this->addError('value', $problem);

            return;
        }

        /*
         * Counted per user, not per IP: an IP is shared by a whole office and
         * by every mobile subscriber behind a carrier NAT, so limiting on it
         * would lock out the wrong people while a determined sweep just changes
         * networks. The account is the thing that costs something to make.
         */
        $key = 'serial-check:'.auth()->id();
        $max = (int) config('remarket.identifiers.lookups_per_hour', 30);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $this->addError('value', 'Твърде много проверки. Опитай отново след час.');

            return;
        }

        RateLimiter::hit($key, 3600);

        try {
            $this->result = $registry->check($this->kind, $this->value);
        } catch (RuntimeException $e) {
            $this->addError('value', $e->getMessage());
        }
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.safety.check-serial', [
            'kinds' => ItemIdentifier::kinds(),
        ]);
    }
}
