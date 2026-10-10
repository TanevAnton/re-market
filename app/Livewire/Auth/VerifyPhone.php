<?php

namespace App\Livewire\Auth;

use App\Models\PhoneVerification;
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

    /**
     * Seconds until another code may be sent. Drives a live countdown in the
     * view, so the person watches it tick rather than guessing what „по-късно"
     * means and hammering the button.
     */
    public ?int $retryAfter = null;

    /** The pending Telegram handshake, once the user asks for one. */
    public ?string $telegramUrl = null;

    public function mount(): void
    {
        if (auth()->user()?->phone_verified_at) {
            $this->redirectRoute('browse', navigate: true);

            return;
        }

        /*
         * ARRIVING FROM REGISTRATION: THE CODE IS ALREADY SENT.
         *
         * Register::register() sends it and puts the number in the session, so
         * this page opens straight on the code box. Asking for the number a
         * second time, on the screen that exists because they just gave it,
         * reads as the site having forgotten - and every retype is a chance to
         * mistype.
         *
         * The number cannot be recovered from the account: it is stored only as
         * a hash. The session is what carries it, and it is cleared the moment
         * the number is confirmed or the person asks for a different one.
         */
        if ($e164 = session('verify.phone')) {
            $this->phone      = $e164;
            $this->sent       = true;
            $this->channel    = session('verify.channel');
            $this->status     = $this->statusFor($this->channel);
            $this->retryAfter = $this->cooldownLeft();
        }
    }

    /** How long before another code may be sent, for a page opened mid-cooldown. */
    private function cooldownLeft(): int
    {
        $cooldown = (int) config('remarket.verify.resend_cooldown', 90);

        if ($cooldown <= 0) {
            return 0;
        }

        $last = PhoneVerification::where('user_id', auth()->id())
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        return $last
            ? max(0, $cooldown - (int) $last->created_at->diffInSeconds(now()))
            : 0;
    }

    private function statusFor(?string $channel): string
    {
        return match ($channel) {
            'telegram' => 'Изпратихме код в Telegram.',
            'viber'    => 'Изпратихме код във Viber.',
            'sms'      => 'Изпратихме код по SMS.',
            default    => 'Кодът е записан в лога (режим за разработка).',
        };
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

        /*
         * Not an error. The previous code is still live, so show the entry form
         * and say so. Telling somebody „опитай по-късно" when the thing they
         * need is already sitting in their messages is how a person gives up
         * two steps from the end.
         */
        if (! $result['sent'] && $result['reason'] === 'cooldown') {
            $this->sent       = true;
            $this->channel    = $result['channel'];
            $this->retryAfter = $result['retry_after'];
            $this->status     = 'Вече ти изпратихме код — провери съобщенията си.';

            $this->dispatch('cooldown-started', seconds: $this->retryAfter);

            return;
        }

        if (! $result['sent']) {
            $this->addError('phone', match ($result['reason']) {
                'number_in_use' => 'Този номер вече е свързан с друг профил.',
                'rate_limited'  => 'Твърде много опити. Опитай отново по-късно.',
                'invalid_number' => 'Това не е валиден български мобилен номер.',
                default          => 'В момента не можем да изпратим код. Опитай пак.',
            });

            return;
        }

        $this->sent       = true;
        $this->channel    = $result['channel'];
        $this->status     = $this->statusFor($result['channel']);
        $this->retryAfter = (int) config('remarket.verify.resend_cooldown', 90);

        // Remember it for a refresh: the number is only on the account as a
        // hash, so without this the page would ask for it again.
        session(['verify.phone' => $this->phone, 'verify.channel' => $result['channel']]);

        $this->dispatch('cooldown-started', seconds: $this->retryAfter);
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

        session()->forget(['verify.phone', 'verify.channel']);

        return $this->redirectRoute('browse', navigate: true);
    }

    /** „Друг номер" - forget the one we were given and ask again. */
    public function startOver(): void
    {
        session()->forget(['verify.phone', 'verify.channel']);

        $this->reset(['sent', 'code', 'channel', 'status', 'retryAfter', 'phone']);
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.auth.verify-phone');
    }
}
