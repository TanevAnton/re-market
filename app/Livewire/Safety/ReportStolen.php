<?php

namespace App\Livewire\Safety;

use App\Livewire\Concerns\ChecksTurnstile;
use App\Services\Safety\StolenRegistry;
use App\Support\ItemIdentifier;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Reporting that an item with this serial was taken.
 *
 * OPEN TO PEOPLE WITH NO ACCOUNT, deliberately. The person whose graphics card
 * was taken has probably never heard of this site, and a form behind a signup
 * would lose exactly the reports worth having. Turnstile and a required police
 * reference carry the weight instead.
 *
 * THE POLICE REFERENCE IS THE WHOLE GUARD. A „stolen" claim against a serial
 * takes somebody's listing off the site, so it must cost something: the number
 * of a report actually filed means the claimant has put their name to this
 * somewhere that is not here. It is NOT verified — no public register exists to
 * check it against — and this screen says so in as many words, because a form
 * that implies verification is a form that will be used to lie.
 */
class ReportStolen extends Component
{
    use ChecksTurnstile;

    public string $kind      = ItemIdentifier::SERIAL;
    public string $value     = '';
    public string $policeRef = '';
    public string $detail    = '';
    public string $email     = '';

    public ?string $reference = null;

    public function mount(): void
    {
        $this->email = (string) auth()->user()?->email;
    }

    public function submit(StolenRegistry $registry): void
    {
        $rules = [
            'kind'      => ['required', 'string'],
            'value'     => ['required', 'string', 'max:64'],
            'policeRef' => ['required', 'string', 'min:4', 'max:120'],
            // A floor, because a moderator has to be able to match this against
            // a document: „открадната ми е" is not something anybody can act on.
            'detail'    => ['required', 'string', 'min:30', 'max:2000'],
        ];

        if (! auth()->check()) {
            $rules['email'] = ['required', 'email', 'max:255'];
        }

        $this->validate($rules, [
            'value.required'     => 'Въведи серийния номер или IMEI.',
            'policeRef.required' => 'Въведи номера на подадения сигнал в полицията.',
            'detail.required'    => 'Опиши какво е станало.',
            'detail.min'         => 'Опиши малко по-подробно — това го чете човек, който после решава.',
            'email.required'     => 'Трябва ни имейл, за да ти съобщим решението.',
        ]);

        if (! $this->passesTurnstile()) {
            return;
        }

        try {
            $report = $registry->report(
                kind: $this->kind,
                value: $this->value,
                policeRef: $this->policeRef,
                detail: $this->detail,
                reporterEmail: $this->email,
                reporter: auth()->user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('value', $e->getMessage());

            return;
        }

        $this->reference = $report->uuid;
        $this->reset(['value', 'policeRef', 'detail']);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.safety.report-stolen', [
            'kinds' => ItemIdentifier::kinds(),
        ]);
    }
}
