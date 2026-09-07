<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Login extends Component
{
    public string $login = '';      // email OR username
    public string $password = '';
    public bool $remember = true;

    public function authenticate()
    {
        $this->validate([
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited();

        // One field for both, because nobody remembers which they used.
        $field = filter_var($this->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if (! Auth::attempt([$field => $this->login, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => 'Грешни данни за вход.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        if (auth()->user()->isSuspended()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'login' => 'Профилът е ограничен. Свържи се с нас.',
            ]);
        }

        return $this->redirectIntended(route('browse'), navigate: true);
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => "Твърде много опити. Опитай пак след {$seconds} секунди.",
        ]);
    }

    private function throttleKey(): string
    {
        return 'login:'.mb_strtolower($this->login).'|'.request()->ip();
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.auth.login');
    }
}
