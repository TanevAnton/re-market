<?php

namespace App\Services\Deals;

use App\Enums\Courier;
use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\User;
use App\Notifications\DeliveryDetailsSet;
use App\Notifications\ParcelSent;

/**
 * Who may say where the parcel goes, and who may say it is on its way.
 *
 * THE TWO SIDES OWN DIFFERENT HALVES, and that is the whole of the authorisation
 * model here:
 *
 *   - the BUYER says where it goes. It is their address and their phone; a seller
 *     who could edit the delivery details of a deal could redirect a parcel the
 *     buyer is about to pay for.
 *   - the SELLER says it has been sent. They are the one holding the receipt with
 *     the number on it, and a buyer who could type a tracking number could make a
 *     deal look shipped in order to push the other side toward confirming.
 *
 * Neither of those is a hypothetical: mutual confirmation is what every rating on
 * the site hangs off, so anything that lets one party manufacture the appearance
 * of progress is an attack on the trust signal rather than a UI bug.
 *
 * NOTHING HERE TALKS TO A COURIER. See App\Support\WaybillDraft for why that is a
 * deliberate ceiling and not a missing feature.
 */
class DeliveryService
{
    /**
     * The buyer fills in the delivery details.
     *
     * Editable for as long as the deal is open, because a buyer who mistypes an
     * office number needs to fix it, and the seller has not necessarily printed
     * anything yet. Once the parcel is on its way the tracking number is the
     * record and this stops mattering.
     *
     * @param  array{kind: string, courier: string, office?: ?string, city_id?: ?int, address?: ?string, name: string, phone: string, note?: ?string, inspect_test?: bool}  $data
     */
    public function setDelivery(Deal $deal, User $actor, array $data): Deal
    {
        if ($deal->buyer_id !== $actor->id) {
            // Deliberately the same sentence as any other wrong-party refusal:
            // a message that says „only the buyer can do this" tells a prober
            // which party they are not.
            throw DealException::notYours();
        }

        $this->assertOpen($deal);

        $courier = Courier::tryFrom($data['courier'] ?? '');

        if (! $courier) {
            throw DealException::unknownCourier();
        }

        /*
         * The courier has to be one the SELLER offered on the listing. The form
         * only draws those, but the form is a client-supplied list and this is
         * the server. Picking a courier the seller does not use produces a
         * waybill they cannot create.
         */
        $offered = (array) ($deal->listing->delivery_options ?? []);

        if ($offered !== [] && ! in_array($courier->value, $offered, true)) {
            throw DealException::courierNotOffered($courier->label());
        }

        if (! $courier->ships()) {
            // Meeting in person: no address, no phone, no waybill. Recorded so
            // the screen stops asking, and so the seller is not shown a panel
            // telling them to go to a courier office.
            $deal->forceFill([
                'courier'               => $courier->value,
                'inspect_test_selected' => false,
                'delivery_kind'         => null,
                'delivery_set_at'       => now(),
            ])->save();

            return $deal;
        }

        $kind = in_array($data['kind'] ?? null, ['office', 'address'], true)
            ? $data['kind']
            : throw DealException::deliveryIncomplete();

        /*
         * „преглед и тест" is only available if the SELLER accepts it — it is
         * their parcel that gets opened before they are paid. A buyer ticking it
         * on a listing that said no would produce a waybill the seller refuses
         * to create, which is worse than the box being absent.
         */
        $inspect = ($data['inspect_test'] ?? false)
            && (bool) $deal->listing->accepts_inspect_test;

        $deal->forceFill([
            'courier'               => $courier->value,
            'inspect_test_selected' => $inspect,
            'delivery_kind'         => $kind,
            'delivery_office'       => $kind === 'office' ? $this->trim($data['office'] ?? null, 120) : null,
            'delivery_address'      => $kind === 'address' ? $this->trim($data['address'] ?? null, 255) : null,
            'delivery_city_id'      => $data['city_id'] ?? null,
            'delivery_name'         => $this->trim($data['name'] ?? null, 120),
            'delivery_phone'        => $this->trim($data['phone'] ?? null, 32),
            'delivery_note'         => $this->trim($data['note'] ?? null, 255),
            'delivery_set_at'       => now(),
            // Re-filling after a purge un-purges: the row holds data again, and
            // a screen that still said „изтрити" would be lying.
            'delivery_purged_at'    => null,
        ])->save();

        /*
         * The seller is told, because this is the moment their part of the work
         * becomes possible and there is nothing else on the site that would tell
         * them. Outside any transaction, like every other dispatch here.
         */
        $deal->seller->notify(new DeliveryDetailsSet($deal));

        return $deal;
    }

    /**
     * The seller says it is sent, and hands over the number.
     *
     * Not validated against the courier's format. Econt and Speedy both have
     * house formats, neither publishes them as a contract, and a regex that
     * rejects a real waybill number is a seller who cannot record a parcel that
     * is already moving — which is strictly worse than storing a typo they can
     * see and fix.
     */
    public function setTracking(Deal $deal, User $actor, string $number): Deal
    {
        if ($deal->seller_id !== $actor->id) {
            throw DealException::notYours();
        }

        $this->assertOpen($deal);

        $courier = Courier::tryFrom((string) $deal->courier);

        if (! $courier?->ships()) {
            throw DealException::nothingToTrack();
        }

        $number = $this->trim($number, 40);

        if ($number === null) {
            throw DealException::trackingRequired();
        }

        $isNew = $deal->tracking_number !== $number;

        $deal->forceFill([
            'tracking_number' => $number,
            'tracking_set_at' => now(),
        ])->save();

        // Only on a change: a seller correcting a typo twice should not send the
        // buyer three „парцелът е изпратен" messages.
        if ($isNew) {
            $deal->buyer->notify(new ParcelSent($deal));
        }

        return $deal;
    }

    /**
     * Erase the personal half, keeping the deal.
     *
     * Called by `remarket:purge-delivery-details` once a deal has been finished
     * long enough that nobody needs to reprint a label. What survives is the
     * courier, the inspect-and-test flag and the tracking number — the facts
     * about the transaction. What goes is the name, phone, address and note —
     * the facts about a person.
     *
     * A delivery address kept forever is a list of where everybody who ever
     * bought a graphics card lives, and it is a list with no reader.
     */
    public function purge(Deal $deal): Deal
    {
        $deal->forceFill([
            'delivery_office'    => null,
            'delivery_address'   => null,
            'delivery_name'      => null,
            'delivery_phone'     => null,
            'delivery_note'      => null,
            'delivery_city_id'   => null,
            'delivery_purged_at' => now(),
        ])->save();

        return $deal;
    }

    private function assertOpen(Deal $deal): void
    {
        if ($deal->status !== DealStatus::Open) {
            throw DealException::notOpen();
        }
    }

    /** Livewire skips TrimStrings, so nothing arriving here has been trimmed. */
    private function trim(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
