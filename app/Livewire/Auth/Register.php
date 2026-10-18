<?php

namespace App\Livewire\Auth;

use App\Enums\SellerType;
use App\Livewire\Concerns\ChecksTurnstile;
use App\Models\City;
use App\Models\User;
use App\Services\Verification\PhoneNumber;
use App\Services\Verification\PhoneVerifier;
use App\Support\Profanity;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Register extends Component
{
    use ChecksTurnstile;

    public string $username = '';
    public string $email = '';
    public string $phone = '';
    public string $password = '';
    public string $password_confirmation = '';
    public ?int $city_id = null;
    public string $seller_type = 'private';

    /**
     * 'email' | 'sms' - which proof the person wants to give now.
     *
     * Defaults to email because it is free and instant. SMS costs EUR 0.076 a
     * code, and a default nobody chose is the most expensive kind of default.
     *
     * WHILE remarket.verify.phone_enabled IS FALSE this is not a choice at all:
     * the radios do not render, validation pins it to „email", and register()
     * never calls PhoneVerifier. The property stays rather than being deleted,
     * because the flag is meant to be flipped back and a feature that has to be
     * rebuilt to be re-enabled is a feature that will not be.
     */
    public string $verify_via = 'email';

    /** Whether the site is currently asking anybody to prove a phone number. */
    public function phoneVerificationEnabled(): bool
    {
        return (bool) config('remarket.verify.phone_enabled');
    }

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

                /*
                 * REFUSED OUTRIGHT HERE, unlike a listing, which is only held
                 * for review. A username is permanent, it appears on every
                 * listing and in every conversation, and there is no honest
                 * version of the ones on the list — so there is nothing for a
                 * moderator to weigh up.
                 *
                 * The message does not say which word matched. Naming it would
                 * hand over a free hint for the next attempt, and it would mean
                 * the site repeating a slur back at whoever typed it.
                 */
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! Profanity::clean((string) $value)) {
                        $fail('Избери друго потребителско име.');
                    }
                },
            ],

            'email' => ['required', 'email', 'max:255', 'unique:users,email'],

            /*
             * REQUIRED, AND CHECKED AGAINST VERIFIED NUMBERS ONLY.
             *
             * The obvious rule - refuse any number already on file - hands
             * anybody a way to lock a stranger out of the site for good: sign
             * up with their number, never prove it, and the real owner can
             * never register. So an unproven claim blocks nothing, and the
             * moment somebody does prove a number, PhoneVerifier wipes every
             * other account's unproven claim on it.
             *
             * withTrashed() is the ban-evasion half: a deleted account keeps
             * its phone hash precisely so a ban cannot be shed by deleting the
             * profile and signing up again (Art. 17(3)(b) - erasure does not
             * extend to defeating an enforcement).
             *
             * WHILE PHONE VERIFICATION IS OFF, nothing is ever PROVEN, so this
             * check matches nothing and no number is ever refused. The claim
             * simply moves to whoever registered last. That is the intended
             * behaviour of the lenient rule, not a hole opened by the flag -
             * but it does mean „one profile per number" is currently a
             * convention rather than something the site enforces, which is why
             * the front page no longer says otherwise.
             */
            'phone' => [
                'required', 'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $e164 = PhoneNumber::normalize((string) $value);

                    if ($e164 === null) {
                        $fail('Това не е валиден български мобилен номер.');

                        return;
                    }

                    $taken = User::withTrashed()
                        ->where('phone_hash', User::hashPhone($e164))
                        ->whereNotNull('phone_verified_at')
                        ->exists();

                    if ($taken) {
                        $fail('Този номер вече е свързан с друг профил.');
                    }
                },
            ],

            /*
             * The allowed set SHRINKS when phone verification is off, rather
             * than the rule being dropped. A hand-crafted POST carrying
             * verify_via=sms would otherwise walk straight into PhoneVerifier
             * and spend EUR 0.076 on a channel the site is not offering.
             */
            'verify_via'  => ['required', Rule::in(
                $this->phoneVerificationEnabled() ? ['email', 'sms'] : ['email'],
            )],
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
            'email.unique'        => 'Вече има профил с този имейл.',
            'phone.required'      => 'Въведи телефонен номер.',
            'terms.accepted'      => 'Трябва да приемеш условията.',
        ];
    }

    public function register()
    {
        $data = $this->validate();

        // Before the account exists, not after: a bot that gets a row written
        // and then an error has still cost us a username.
        if (! $this->passesTurnstile()) {
            return null;
        }

        $e164 = PhoneNumber::normalize($data['phone']);

        $user = DB::transaction(function () use ($data, $e164) {
            /*
             * RELEASE ANY UNPROVEN CLAIM ON THIS NUMBER FIRST.
             *
             * users.phone_hash carries a UNIQUE index, so two rows can never
             * hold the same number - which means the lenient rule above ("an
             * unproven claim blocks nobody") cannot be implemented by simply
             * allowing a duplicate. It is implemented here instead: the claim
             * moves, inside the same transaction, so no duplicate ever exists.
             *
             * Validation has already refused the number if somebody PROVED it,
             * so the only rows this can touch are claims nobody ever confirmed.
             * A claim nobody proved is not evidence of anything, and leaving it
             * in place is what would lock a real owner out for good.
             *
             * Last registrant holds the claim; the first to verify keeps it for
             * ever. Two people registering the same number in the same instant
             * is the one case this does not cover - the unique index turns that
             * into an error rather than a wrong answer, which is the right way
             * round.
             */
            User::where('phone_hash', User::hashPhone($e164))
                ->whereNull('phone_verified_at')
                ->update(['phone_hash' => null, 'phone_last4' => null, 'phone_country' => null]);

            $user = User::create([
                'name'        => $data['username'],
                'username'    => $data['username'],
                'email'       => mb_strtolower($data['email']),
                'password'    => $data['password'],   // hashed by the model cast
                'city_id'     => $data['city_id'] ?? null,
                'seller_type' => $data['seller_type'],
            ]);

            /*
             * Stored as a hash and a last-four, never in clear, and
             * phone_verified_at stays NULL. A number on file is a claim; only
             * the code turns it into proof. setPhone() is a method rather than
             * mass assignment because none of those three columns is fillable,
             * deliberately.
             */
            $user->setPhone($e164);
            $user->save();

            return $user;
        });

        /*
         * Fired whichever proof they chose, so the verification email always
         * goes out. That address is where every later notification lands - an
         * offer, a message, a deal - so it needs confirming eventually even for
         * somebody who unlocks the account by SMS today. It costs nothing.
         */
        event(new Registered($user));
        Auth::login($user, remember: true);

        if ($data['verify_via'] === 'sms' && $this->phoneVerificationEnabled()) {
            $result = (new PhoneVerifier(request()->ip()))->send($user, $e164);

            if ($result['sent']) {
                /*
                 * Hand the number to the verify page. It cannot recover it from
                 * the account - the number is stored only as a hash - and
                 * asking for it again on the very screen that exists because
                 * they just gave it reads as the site having forgotten.
                 * Cleared the moment it is confirmed, or if they ask for a
                 * different one.
                 */
                session([
                    'verify.phone'   => $e164,
                    'verify.channel' => $result['channel'],
                ]);

                return $this->redirectRoute('phone.verify', navigate: true);
            }

            /*
             * The SMS did not go. The account exists and they are logged in, so
             * the only real failure here would be a dead end - send them to the
             * email notice, which is a path they can finish, and say why.
             */
            session()->flash('status', 'Не успяхме да изпратим SMS. Потвърди през имейла — линкът вече е изпратен.');
        }

        return $this->redirectRoute('verification.notice', navigate: true);
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
