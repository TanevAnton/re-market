<?php

namespace App\Livewire\Profile;

use App\Enums\SellerType;
use App\Models\City;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

class EditProfile extends Component
{
    public ?int $city_id = null;
    public string $seller_type = 'private';
    public array $trader_details = ['company' => '', 'uic' => '', 'vat' => '', 'address' => ''];
    public string $locale = 'bg';

    public bool $notify_email = true;
    public bool $notify_telegram = true;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = auth()->user();

        $this->city_id        = $user->city_id;
        $this->seller_type    = $user->seller_type->value;
        $this->locale         = $user->locale;
        $this->trader_details = array_merge($this->trader_details, $user->trader_details ?? []);

        $this->notify_email    = (bool) $user->notify_email;
        $this->notify_telegram = (bool) $user->notify_telegram;
    }

    /**
     * Saved on its own rather than folded into save(): a half-filled trader
     * form must not be what stands between someone and turning off email.
     *
     * There is deliberately no "turn everything off" guard. Transactional mail
     * a user has switched off is mail they did not consent to, and the
     * consequence of silence - a missed offer - is theirs to accept.
     */
    public function saveNotifications(): void
    {
        auth()->user()->update([
            'notify_email'    => $this->notify_email,
            'notify_telegram' => $this->notify_telegram,
        ]);

        session()->flash('status', 'Настройките за известия са запазени.');
    }

    public function save(): void
    {
        $data = $this->validate([
            'city_id'     => ['nullable', 'exists:cities,id'],
            'seller_type' => ['required', Rule::enum(SellerType::class)],
            'locale'      => ['required', 'in:bg,en'],
            // A trader must be identifiable - ЗЗП and ЗЕТ both require it, and
            // it is what lets buyers tell a business from a private seller.
            'trader_details.company' => [Rule::requiredIf($this->seller_type === 'trader'), 'nullable', 'string', 'max:150'],
            'trader_details.uic'     => [Rule::requiredIf($this->seller_type === 'trader'), 'nullable', 'digits_between:9,13'],
            'trader_details.vat'     => ['nullable', 'string', 'max:20'],
            'trader_details.address' => [Rule::requiredIf($this->seller_type === 'trader'), 'nullable', 'string', 'max:255'],
        ], [
            'trader_details.company.required' => 'Търговците трябва да посочат фирма.',
            'trader_details.uic.required'     => 'Търговците трябва да посочат ЕИК.',
            'trader_details.uic.digits_between' => 'ЕИК е 9 или 13 цифри.',
            'trader_details.address.required' => 'Търговците трябва да посочат адрес.',
        ]);

        auth()->user()->update([
            'city_id'        => $data['city_id'],
            'seller_type'    => $data['seller_type'],
            'locale'         => $data['locale'],
            'trader_details' => $data['seller_type'] === 'trader' ? $data['trader_details'] : null,
        ]);

        session()->flash('status', 'Профилът е обновен.');
    }

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', Password::defaults()],
        ], [
            'current_password.current_password' => 'Текущата парола е грешна.',
        ]);

        auth()->user()->update(['password' => $this->password]);

        $this->reset(['current_password', 'password', 'password_confirmation']);
        session()->flash('status', 'Паролата е сменена.');
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.profile.edit-profile', [
            'cities'      => City::orderByDesc('population')->get(),
            'sellerTypes' => SellerType::cases(),
        ]);
    }
}
