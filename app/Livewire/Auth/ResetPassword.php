<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Set the new password, from the link in the mail.
 */
class ResetPassword extends Component
{
    /**
     * Both arrive in the URL and neither may be edited by the browser after
     * that. The token is the whole of the proof; the address is what it is
     * checked against, and a changeable one would let a valid token be pointed
     * at somebody else's account.
     */
    #[Locked]
    public string $token = '';

    #[Locked]
    public string $email = '';

    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function save()
    {
        $this->validate([
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ], [
            'password.required'  => 'Напиши нова парола.',
            'password.confirmed' => 'Двете пароли не съвпадат.',
        ]);

        $status = Password::reset(
            [
                'email'                 => $this->email,
                'password'              => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token'                 => $this->token,
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,   // hashed by the model's cast
                    /*
                     * A new remember token, so the "remember me" cookies of
                     * whoever the password was being reset away from stop
                     * working. Without this, someone recovering a compromised
                     * account changes the password and the intruder stays
                     * logged in indefinitely.
                     */
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            /*
             * Here the reason IS said out loud, unlike on the request form.
             * There is no enumeration left to protect - whoever is holding
             * this link already has the address in it - and "invalid or
             * expired" is the difference between trying again and giving up.
             */
            $this->addError('password', match ($status) {
                Password::INVALID_TOKEN => 'Линкът е невалиден или изтекъл. Поискай нов.',
                Password::INVALID_USER  => 'Линкът е невалиден или изтекъл. Поискай нов.',
                default                 => 'Нещо се обърка. Опитай пак.',
            });

            return null;
        }

        /*
         * Not logged in automatically. Whoever just set this password should
         * prove it works while they still have it in hand, and a reset that
         * ends in a session is a reset that also ends any doubt about whether
         * the mailbox or the browser was the thing being trusted.
         */
        session()->flash('status', 'Паролата е сменена. Влез с новата.');

        return $this->redirectRoute('login', navigate: true);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
