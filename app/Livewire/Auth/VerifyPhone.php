<?php

namespace App\Livewire\Auth;

use App\Models\TelegramLink;
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

    /** The pending Telegram handshake, once the user asks for one. */
    public ?string $telegramUrl = null;

    public function mount(): void
    {
        if (auth()->user()?->phone_verified_at) {
            $this->redirectRoute('browse', navigate: true);
        }
    }

    /**
     * The token alone decides whether we offer this, because it is the only
     * part we cannot work out for ourselves - the bot's @name comes from
     * Telegram when we ask. Deliberately no network call here: this runs on
     * every render of the page, and a slow or unreachable api.telegram.org
     * must not turn into a slow verification page.
     */
    public function telegramAvailable(): bool
    {
        return filled(config('remarket.verify.telegram_bot_token'));
    }

    /**
     * Hand out a fresh deep link. No phone number is asked for here on purpose:
     * the whole point is that the user does not type one, Telegram supplies the
     * number it already verified.
     */
    public function startTelegram(): void
    {
        if (! $this->telegramAvailable()) {
            return;
        }

        $link = TelegramLink::issueFor(
            auth()->user(),
            (int) config('remarket.verify.telegram_link_ttl', 15),
        );

        $this->telegramUrl = $link->deepLink();

        if ($this->telegramUrl === null) {
            // Token set but Telegram would not tell us the bot's name - almost
            // always a bad token or no outbound network. Say so, rather than
            // handing out a link that goes nowhere.
            $this->addError('telegram', 'Telegram не отговаря в момента. Използвай кода по-долу.');
        }
    }

    /**
     * Polled by the page while the user is over in Telegram. The listener does
     * the actual verifying out of band, so the browser only has to notice that
     * it happened.
     */
    public function checkTelegram()
    {
        if (auth()->user()?->fresh()?->phone_verified_at) {
            session()->flash('status', 'Номерът ти е потвърден.');

            return $this->redirectRoute('browse', navigate: true);
        }

        return null;
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
