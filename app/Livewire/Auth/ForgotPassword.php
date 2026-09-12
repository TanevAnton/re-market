<?php

namespace App\Livewire\Auth;

use App\Livewire\Concerns\ChecksTurnstile;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ask for a reset link.
 *
 * Until now there was no way back into an account at all: forget the password
 * and the account was gone, with email verification - the site's only proof of
 * who you are - wired to nothing that could recover it.
 */
class ForgotPassword extends Component
{
    use ChecksTurnstile;

    public string $email = '';

    /** Set once the link has gone out, so the form is replaced rather than re-offered. */
    public bool $sent = false;

    public function send(): void
    {
        /*
         * Normalised BEFORE validation, which is the whole point.
         *
         * A Livewire property arrives from a JSON payload and never passes
         * through the TrimStrings middleware that cleans ordinary form input.
         * So an address pasted with a trailing space - which is most addresses
         * pasted out of anything - failed the `email` rule outright, and the
         * person was told their own address is not an address, on the one
         * screen they reached because they were already stuck.
         *
         * Doing it here rather than at each use also means validation, the
         * throttle key and the broker lookup all see one canonical form.
         */
        $this->email = mb_strtolower(trim($this->email));

        $this->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Напиши имейла си.',
            'email.email'    => 'Това не прилича на имейл адрес.',
        ]);

        $this->ensureIsNotRateLimited();

        if (! $this->passesTurnstile()) {
            return;
        }

        RateLimiter::hit($this->throttleKey(), 900);

        /*
         * The status is ignored on purpose.
         *
         * sendResetLink() distinguishes "sent" from "no such user" and from
         * "asked again too soon", and reporting any of that turns this form
         * into an oracle: type an address, learn whether it has an account
         * here. On a marketplace that is a list of people who own expensive
         * hardware and are willing to meet strangers - worth more to the wrong
         * person than the accounts themselves.
         *
         * So every outcome produces the same screen. The throttle above is
         * what stops the form being used to mail somebody repeatedly; the
         * broker's own 60-second throttle stops it being used to mail them
         * from several browsers at once.
         */
        Password::sendResetLink(['email' => $this->email]);

        $this->sent = true;
    }

    private function ensureIsNotRateLimited(): void
    {
        // Keyed on the address AND the source, so one person hammering the
        // form cannot lock a stranger out of their own recovery.
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => 'Твърде много опити. Опитай пак след няколко минути.',
        ]);
    }

    private function throttleKey(): string
    {
        return 'pwreset:'.$this->email.'|'.request()->ip();
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
