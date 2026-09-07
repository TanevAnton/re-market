<?php

namespace App\Livewire\Concerns;

use App\Support\Turnstile;

/**
 * Turnstile for a Livewire component.
 *
 * The widget writes its token into `turnstileToken` through the Blade partial's
 * callback; the component calls passesTurnstile() before doing anything that
 * costs.
 *
 * Deliberately NOT #[Locked]. The token arrives from the browser by definition,
 * so locking it makes Cloudflare's own callback throw - and it would buy
 * nothing anyway: the value is worthless until Cloudflare confirms it, and a
 * forged one simply fails verification.
 */
trait ChecksTurnstile
{
    public string $turnstileToken = '';

    public function turnstileEnabled(): bool
    {
        return Turnstile::enabled();
    }

    /**
     * Adds the error itself, so callers stay a single early return.
     *
     * The token is cleared either way: Turnstile tokens are single use, and
     * leaving a spent one in the component means the second attempt fails for
     * a reason that has nothing to do with what the user did.
     */
    protected function passesTurnstile(string $errorBag = 'turnstile'): bool
    {
        $token = $this->turnstileToken;
        $this->turnstileToken = '';

        if (Turnstile::verify($token, request()->ip())) {
            return true;
        }

        $this->addError($errorBag, 'Проверката за робот не мина. Опитай отново.');
        $this->dispatch('turnstile-reset');

        return false;
    }
}
