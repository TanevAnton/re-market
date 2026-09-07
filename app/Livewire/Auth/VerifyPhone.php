<?php

namespace App\Livewire\Auth;

use App\Services\Verification\PhoneNumber;
use App\Services\Verification\PhoneVerifier;
use Livewire\Attributes\Layout;
use Livewire\Component;

class VerifyPhone extends Component
{
    public string $phone = '';
    public string $code = '';
    public bool $sent = false;
    public ?string $channel = null;
    public ?string $status = null;

    public function mount(): void
    {
        if (auth()->user()?->phone_verified_at) {
            $this->redirectRoute('browse', navigate: true);
        }
    }

    public function sendCode(): void
    {
        $this->validate(
            ['phone' => ['required', 'string']],
            ['phone.required' => 'Въведи телефонен номер.']
        );

        if (! PhoneNumber::isValid($this->phone)) {
            $this->addError('phone', 'Това не е валиден български мобилен номер.');

            return;
        }

        $result = (new PhoneVerifier(request()->ip()))->send(auth()->user(), $this->phone);

        if (! $result['sent']) {
            $this->addError('phone', match ($result['reason']) {
                'number_in_use' => 'Този номер вече е свързан с друг профил.',
                'rate_limited'  => 'Твърде много опити. Опитай отново по-късно.',
                'invalid_number' => 'Това не е валиден български мобилен номер.',
                default          => 'В момента не можем да изпратим код. Опитай пак.',
            });

            return;
        }

        $this->sent    = true;
        $this->channel = $result['channel'];
        $this->status  = match ($result['channel']) {
            'telegram' => 'Изпратихме код в Telegram.',
            'viber'    => 'Изпратихме код във Viber.',
            'sms'      => 'Изпратихме код по SMS.',
            default    => 'Кодът е записан в лога (режим за разработка).',
        };
    }

    public function confirm()
    {
        $this->validate(
            ['code' => ['required', 'digits:6']],
            ['code.digits' => 'Кодът е 6 цифри.']
        );

        $result = (new PhoneVerifier(request()->ip()))->verify(auth()->user(), $this->phone, $this->code);

        if (! $result['verified']) {
            $this->addError('code', match ($result['reason']) {
                'wrong_code' => 'Грешен код.',
                'expired'    => 'Кодът е изтекъл. Поискай нов.',
                default      => 'Проверката не успя.',
            });

            return;
        }

        return $this->redirectRoute('browse', navigate: true);
    }

    public function startOver(): void
    {
        $this->reset(['sent', 'code', 'channel', 'status']);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.auth.verify-phone');
    }
}
