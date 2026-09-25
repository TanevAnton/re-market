<?php

namespace App\Livewire\Support;

use App\Enums\TicketTopic;
use App\Livewire\Concerns\ChecksTurnstile;
use App\Models\Ticket;
use App\Services\Support\SupportService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * The support page: how to get help, and the form for when the page does not
 * answer it.
 *
 * REACHABLE WITHOUT AN ACCOUNT, and that is the whole point of the design. The
 * commonest first-week support request on any site is „I cannot log in" or „the
 * confirmation email never arrived", and a help form behind a login is useless
 * to precisely the person sending it. Guests give an address and clear a
 * Turnstile challenge; signed-in users skip both.
 *
 * WHAT THIS PAGE MUST NOT SWALLOW. Two other routes exist and they are not
 * interchangeable with this one: `/signali` is the DSA Art. 16 notice form,
 * legally clocked and owed a statement of reasons, and the report button on a
 * listing is how a specific scam gets looked at. Both are named on the page,
 * above the form, because a notice filed here would lose its clock and its
 * appeal and nobody would find out for months.
 */
class SupportCentre extends Component
{
    use ChecksTurnstile;

    public string $topic   = '';
    public string $subject = '';
    public string $body    = '';
    public string $email   = '';

    /** Set after a successful send, so the page can show the reference. */
    public ?string $reference = null;

    public function mount(): void
    {
        $this->email = (string) auth()->user()?->email;
    }

    public function submit(SupportService $support): void
    {
        $rules = [
            'topic'   => ['required', 'string'],
            'subject' => ['required', 'string', 'min:4', 'max:160'],
            // A floor rather than a formality: „не работи" is not something
            // anybody can answer, and the round trip asking what they mean
            // costs the person a day.
            'body'    => ['required', 'string', 'min:20', 'max:5000'],
        ];

        if (! auth()->check()) {
            $rules['email'] = ['required', 'email', 'max:255'];
        }

        $this->validate($rules, [
            'topic.required'   => 'Избери за какво се отнася.',
            'subject.required' => 'Напиши накратко за какво става дума.',
            'body.required'    => 'Опиши какво се случва.',
            'body.min'         => 'Опиши малко по-подробно — така отговорът идва от първия път.',
            'email.required'   => 'Трябва ни имейл, за да ти отговорим.',
            'email.email'      => 'Имейлът не изглежда валиден.',
        ]);

        if (! $this->passesTurnstile()) {
            return;
        }

        $topic = TicketTopic::tryFrom($this->topic);

        if (! $topic) {
            $this->addError('topic', 'Избери за какво се отнася.');

            return;
        }

        try {
            $ticket = $support->open(
                user: auth()->user(),
                email: $this->email,
                topic: $topic,
                subject: $this->subject,
                body: $this->body,
            );
        } catch (RuntimeException $e) {
            // The open-ticket cap lands here, and it is a sentence the person
            // can act on rather than a failure.
            $this->addError('body', $e->getMessage());

            return;
        }

        $this->reference = $ticket->reference;
        $this->reset(['topic', 'subject', 'body']);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.support.support-centre', [
            'topics' => TicketTopic::options(),

            /*
             * „My tickets", for signed-in users only. A guest's tickets are
             * reachable from the signed link in their email and deliberately
             * not listed here: showing them would mean looking tickets up by
             * an email address anybody can type, which is a way to read
             * somebody else's support conversation.
             */
            'mine' => auth()->check()
                ? Ticket::where('user_id', auth()->id())->latest('id')->limit(10)->get()
                : collect(),
        ]);
    }
}
