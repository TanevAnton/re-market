<?php

namespace App\Livewire\Auth;

use App\Enums\SellerType;
use App\Models\City;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Register extends Component
{
    public string $username = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public ?int $city_id = null;
    public string $seller_type = 'private';
    public bool $terms = false;

    protected function rules(): array
    {
        return [
            // alpha_dash keeps usernames URL-safe.
            //
            // Uniqueness is checked case-INSENSITIVELY with a closure, not with
            // Rule::unique()->where(): that ANDs its own "username = ?" check
            // with the closure, so "ivan" would sail past validation when
            // "Ivan" exists and then hit the database's lower(username) unique
            // index as a 500 instead of a form error.
            'username' => [
                'required', 'string', 'min:3', 'max:20', 'alpha_dash',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $taken = User::withTrashed()
                        ->whereRaw('lower(username) = ?', [mb_strtolower((string) $value)])
                        ->exists();

                    if ($taken) {
                        $fail('Това потребителско име е заето.');
                    }
                },
            ],
            'email'       => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'    => ['required', 'confirmed', Password::defaults()],
            'city_id'     => ['nullable', 'exists:cities,id'],
            'seller_type' => ['required', Rule::enum(SellerType::class)],
            'terms'       => ['accepted'],
        ];
    }

    protected function messages(): array
    {
        return [
            'username.alpha_dash' => 'Само букви, цифри, тире и долна черта.',
            'email.unique'       => 'Вече има профил с този имейл.',
            'terms.accepted'     => 'Трябва да приемеш условията.',
        ];
    }

    public function register()
    {
        $data = $this->validate();

        $user = DB::transaction(fn () => User::create([
            'name'        => $data['username'],
            'username'    => $data['username'],
            'email'       => mb_strtolower($data['email']),
            'password'    => $data['password'],   // hashed by the model cast
            'city_id'     => $data['city_id'] ?? null,
            'seller_type' => $data['seller_type'],
        ]));

        event(new Registered($user));
        Auth::login($user, remember: true);

        // Email verification can wait; the phone is the anti-bot gate, so we
        // send them straight at it while intent is high.
        return $this->redirectRoute('phone.verify', navigate: true);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.auth.register', [
            'cities'      => City::orderByDesc('population')->get(),
            'sellerTypes' => SellerType::cases(),
        ]);
    }
}
