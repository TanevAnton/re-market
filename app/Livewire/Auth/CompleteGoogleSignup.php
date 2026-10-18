<?php

namespace App\Livewire\Auth;

use App\Enums\SellerType;
use App\Http\Controllers\GoogleAuth;
use App\Models\City;
use App\Models\User;
use App\Services\Verification\PhoneNumber;
use App\Support\Profanity;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The second half of „влез с Google", and the only place that writes the row.
 *
 * Google gives an address, a name and a picture. This screen asks for the three
 * things it cannot give and this site cannot do without:
 *
 *   - a USERNAME, because it is permanent, public, on every listing and in
 *     every conversation. Generating one („ivan-7f2a") to save a field hands
 *     somebody a name they did not choose and cannot change.
 *   - a PHONE, because registration requires one and a signup path that quietly
 *     skips it is how „required" stops being true.
 *   - the TERMS, because accepting a contract is an act. A click on a Google
 *     button is consent to share a profile with this site; it is not agreement
 *     to the terms of use, and treating it as such would make the acceptance
 *     unprovable precisely when it mattered.
 *
 * Everything else has a sane default and can be changed later in settings.
 *
 * NO TURNSTILE HERE, deliberately. The challenge exists to make a bot's first
 * account expensive; a working Google account that survived Google's own abuse
 * checks already costs more than solving one. Adding a second puzzle on the
 * last screen of a signup is friction spent against the wrong person.
 */
class CompleteGoogleSignup extends Component
{
    public string $username = '';
    public string $phone = '';
    public ?int $city_id = null;
    public string $seller_type = 'private';
    public bool $terms = false;

    /** Straight from Google, shown but never editable. */
    public string $email = '';

    public function mount(): void
    {
        $handoff = GoogleAuth::handoff();

        /*
         * Nothing parked, or parked too long ago. Not an error page: the
         * honest reading is „start again", and a 404 on a screen somebody
         * reached by pressing a Google button reads as the site being broken.
         */
        if ($handoff === null) {
            // Called, then return - not `return $this->redirect(...)`. A
            // redirect handed back out of mount() is what produced „Invalid
            // Livewire snapshot structure" on the next round trip once before.
            $this->redirect(route('register'), navigate: true);

            return;
        }

        $this->email = $handoff['email'];

        /*
         * A suggestion, not a decision. Google's display name is „Иван
         * Йоркишев", which is not a username, so this is the ASCII of it with
         * the spaces taken out - and it is pre-filled rather than imposed
         * precisely because it is a guess. Blank if the guess is unusable.
         */
        $this->username = $this->suggestUsername($handoff['name']);
    }

    protected function rules(): array
    {
        return [
            // Deliberately identical to Register's. Two signup paths with two
            // sets of username rules is how one of them ends up allowing
            // something the other refuses.
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
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! Profanity::clean((string) $value)) {
                        $fail('Избери друго потребителско име.');
                    }
                },
            ],

            'phone' => [
                'required', 'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $e164 = PhoneNumber::normalize((string) $value);

                    if ($e164 === null) {
                        $fail('Това не е валиден български мобилен номер.');

                        return;
                    }

                    // Same lenient rule as registration: only a PROVEN number
                    // blocks, so an unconfirmed claim cannot lock a real owner
                    // out for ever. See Register for the long version.
                    $taken = User::withTrashed()
                        ->where('phone_hash', User::hashPhone($e164))
                        ->whereNotNull('phone_verified_at')
                        ->exists();

                    if ($taken) {
                        $fail('Този номер вече е свързан с друг профил.');
                    }
                },
            ],

            'city_id'     => ['nullable', 'exists:cities,id'],
            'seller_type' => ['required', Rule::enum(SellerType::class)],
            'terms'       => ['accepted'],
        ];
    }

    protected function messages(): array
    {
        return [
            'username.alpha_dash' => 'Само букви, цифри, тире и долна черта.',
            'phone.required'      => 'Въведи телефонен номер.',
            'terms.accepted'      => 'Трябва да приемеш условията.',
        ];
    }

    public function complete()
    {
        $handoff = GoogleAuth::handoff();

        // Re-read rather than trusting a property: the payload may have expired
        // while this form sat open, and the row must not be written from a
        // stale one.
        if ($handoff === null) {
            return $this->redirect(route('register'), navigate: true);
        }

        $data = $this->validate();
        $e164 = PhoneNumber::normalize($data['phone']);

        /*
         * CHECKED AGAIN, HERE. Minutes passed while this form was open, and in
         * them somebody may have registered this address or linked this Google
         * account. users.email and users.google_id are both unique, so without
         * this the submit dies as a constraint violation on the last screen of
         * a signup.
         */
        $clash = User::whereRaw('lower(email) = ?', [$handoff['email']])
            ->orWhere('google_id', $handoff['id'])
            ->exists();

        if ($clash) {
            session()->forget('google.signup');

            return $this->redirect(route('login'), navigate: true);
        }

        $user = DB::transaction(function () use ($data, $e164, $handoff) {
            // Release any unproven claim on the number, exactly as Register
            // does - the unique index means the claim has to move rather than
            // be duplicated.
            User::where('phone_hash', User::hashPhone($e164))
                ->whereNull('phone_verified_at')
                ->update(['phone_hash' => null, 'phone_last4' => null, 'phone_country' => null]);

            $user = User::create([
                'name'     => $handoff['name'] !== '' ? $handoff['name'] : $data['username'],
                'username' => $data['username'],
                'email'    => $handoff['email'],

                /*
                 * A LONG RANDOM PASSWORD NOBODY KNOWS, not an empty column.
                 *
                 * The column is NOT NULL and every query that touches it - the
                 * login attempt, `current_password` on the settings page -
                 * assumes a hash is there. A random one keeps all of that
                 * working and simply never matches anything, so the account has
                 * exactly one way in until its owner chooses to add another.
                 *
                 * They add one through „забравена парола", which works because
                 * the address is confirmed. That is said on the screen rather
                 * than left to be discovered.
                 */
                'password'    => Str::random(64),
                'city_id'     => $data['city_id'] ?? null,
                'seller_type' => $data['seller_type'],
            ]);

            $user->setPhone($e164);

            $user->forceFill([
                'google_id' => $handoff['id'],

                // Google vouched for the mailbox; the account opens now. No
                // verification mail is sent, because there is nothing left to
                // prove and a mail asking them to confirm what they just
                // confirmed is how a new account's first message is noise.
                'email_verified_at' => now(),
            ])->save();

            return $user;
        });

        /*
         * Fired with the address ALREADY marked verified, so Laravel's own
         * listener sends nothing. It is dispatched anyway because it is the
         * seam every later onboarding step hangs off, and a signup path that
         * quietly skips it is one that gets forgotten when the first such step
         * is written.
         */
        event(new Registered($user));

        Auth::login($user, remember: true);
        session()->regenerate();
        session()->forget('google.signup');

        return $this->redirect(route('browse'), navigate: true);
    }

    /** „Иван Йоркишев" -> „ivan-yorkishev", or '' when nothing usable is left. */
    private function suggestUsername(string $name): string
    {
        $slug = Str::slug(Str::ascii($name), '-');
        $slug = mb_substr($slug, 0, 20);

        if (mb_strlen($slug) < 3) {
            return '';
        }

        // Only offered if it is actually free - a pre-filled field that fails
        // validation the moment it is submitted is worse than an empty one.
        $taken = User::withTrashed()
            ->whereRaw('lower(username) = ?', [$slug])
            ->exists();

        return $taken ? '' : $slug;
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.auth.complete-google-signup', [
            'cities'      => City::orderByDesc('population')->get(),
            'sellerTypes' => SellerType::cases(),
        ]);
    }
}
