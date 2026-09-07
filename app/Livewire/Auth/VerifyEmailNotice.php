<?php

namespace App\Livewire\Auth;

use Livewire\Attributes\Layout;
use Livewire\Component;

class VerifyEmailNotice extends Component
{
    public function mount()
    {
        if (auth()->user()?->hasVerifiedEmail()) {
            return $this->redirectRoute('browse', navigate: true);
        }
    }

    public function resend(): void
    {
        auth()->user()->sendEmailVerificationNotification();

        session()->flash('status', 'Изпратихме нов линк за потвърждение.');
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.auth.verify-email-notice');
    }
}
