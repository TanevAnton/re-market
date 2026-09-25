<?php

namespace App\Services\Billing;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The balance, and the only door that moves it.
 *
 * Every rule about money lives here for the same reason the offer floor lives
 * in a service: a caller must not be able to route around it. There are three
 * of them and they are all about the same failure.
 *
 * THE RACE IS THE WHOLE PROBLEM. „Read the balance, check it is enough, write
 * a spend" is three steps, and a seller who double-clicks — or whose phone
 * retries a request on a flaky connection — can get two boosts for one
 * balance. The window is milliseconds wide and it is somebody's money, so the
 * check and the write happen inside one transaction with the user row locked,
 * not inside one method that reads quickly.
 */
class CreditService
{
    /**
     * What this user can spend, right now.
     *
     * Summed, never read from a column. On an indexed integer this is a few
     * microseconds; a balance that has drifted from its history is an
     * afternoon with a spreadsheet and an angry seller.
     */
    public function balance(User $user): int
    {
        return (int) CreditTransaction::where('user_id', $user->id)->sum('amount_cents');
    }

    public function canAfford(User $user, int $cents): bool
    {
        return $this->balance($user) >= $cents;
    }

    /**
     * Take money out, refusing if it is not there.
     *
     * THE LOCK IS LOAD-BEARING. `lockForUpdate()` on the user row serialises
     * every spend by the same person, so two concurrent requests queue instead
     * of both reading the same balance and both deciding it is enough. It
     * locks the USER rather than the ledger because the ledger only grows —
     * there is no row to lock that both requests would agree on.
     *
     * @throws RuntimeException when the balance will not cover it
     */
    public function spend(User $user, int $cents, string $note, ?Model $source = null): CreditTransaction
    {
        if ($cents <= 0) {
            throw new RuntimeException('A spend has to be for something.');
        }

        return DB::transaction(function () use ($user, $cents, $note, $source) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($this->balance($user) < $cents) {
                throw new RuntimeException('Нямаш достатъчно кредит.');
            }

            return $this->post($user, -$cents, CreditTransaction::SPEND, $note, $source);
        });
    }

    /** Money in from a real payment. */
    public function topUp(User $user, int $cents, string $note, ?Model $source = null): CreditTransaction
    {
        return $this->post($user, $cents, CreditTransaction::TOPUP, $note, $source);
    }

    /**
     * Money back when a boost is cut short.
     *
     * Credit, not cash. The seller has not lost anything — the site failed to
     * deliver what was bought, so the balance goes back and they can spend it
     * on something that will run. Returning it to the card would mean a
     * payment reversal for €0.40, which costs more in fees than it returns.
     */
    public function refund(User $user, int $cents, string $note, ?Model $source = null): CreditTransaction
    {
        return $this->post($user, $cents, CreditTransaction::REFUND, $note, $source);
    }

    /**
     * Free credit, given deliberately.
     *
     * This is the one that matters before launch: the first sellers are worth
     * more than the few euros a boost costs, so they get it free and the
     * feature is exercised by real people before anybody is charged. Kept as
     * its own `kind` so „what did we give away" is a query rather than an
     * archaeology exercise.
     */
    public function grant(User $user, int $cents, string $note): CreditTransaction
    {
        return $this->post($user, $cents, CreditTransaction::GRANT, $note);
    }

    private function post(User $user, int $cents, string $kind, string $note, ?Model $source = null): CreditTransaction
    {
        $row = new CreditTransaction();

        $row->fill([
            'user_id'      => $user->id,
            'amount_cents' => $cents,
            'kind'         => $kind,
            'note'         => mb_substr($note, 0, 160),
        ]);

        if ($source) {
            $row->source()->associate($source);
        }

        $row->save();

        return $row;
    }
}
