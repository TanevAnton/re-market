<?php

namespace App\Support;

use App\Enums\Courier;
use App\Models\Deal;

/**
 * The five minutes of a sale that nothing on the site helped with.
 *
 * Two people agree a price, and then the seller stands in front of a courier
 * form — or a counter clerk — needing a name, a phone, an office, a
 * cash-on-delivery amount, a content description and a declared value, scattered
 * across a chat thread they now have to scroll. This class is that form, in the
 * order the courier asks for it, ready to copy.
 *
 * IT IS NOT AN API CLIENT AND MUST NOT BECOME ONE. RIGO holds no contract with
 * either courier, and the reason is not cost: whoever creates the waybill is the
 * sender of record, and the sender of record is who the cash-on-delivery is
 * remitted to. The platform never touching the money is the entire regulatory
 * position — it is why there is no escrow, no payment licence question and no
 * DAC7 amount to report — and it is written into `DealService`, the deals
 * migration and the `Deal` model. A convenience feature must not be the thing
 * that quietly undoes it. So: the seller creates the waybill, in their own
 * account or at the counter, and this tells them what to put in it.
 *
 * WHAT IT REFUSES TO STATE is who pays the shipping. Nothing on the platform
 * captures that, the two of them may well have agreed it in the chat, and a panel
 * that guessed „получателят плаща" would be inventing a term of somebody else's
 * contract. It says so instead.
 */
class WaybillDraft
{
    public function __construct(private readonly Deal $deal) {}

    public static function for(Deal $deal): self
    {
        return new self($deal);
    }

    public function courier(): ?Courier
    {
        return Courier::tryFrom((string) $this->deal->courier);
    }

    /** Nothing to draft for a handshake in a car park, or before the buyer fills the form. */
    public function isReady(): bool
    {
        return $this->courier()?->ships() === true
            && $this->deal->delivery_set_at !== null
            && $this->deal->delivery_purged_at === null;
    }

    /**
     * The waybill, field by field, in courier order.
     *
     * Returned as a list of [label, value, hint] rather than a formatted string
     * so the screen can lay it out and the copy button can flatten it, without
     * either one owning the wording.
     *
     * @return list<array{label: string, value: string, hint: ?string}>
     */
    public function fields(): array
    {
        if (! $this->isReady()) {
            return [];
        }

        $deal    = $this->deal;
        $courier = $this->courier();

        $destination = $deal->delivery_kind === 'office'
            ? trim(($deal->deliveryCity?->name() ?? '').' · офис '.$deal->delivery_office, ' ·')
            : trim(($deal->deliveryCity?->name() ?? '').', '.$deal->delivery_address, ', ');

        $fields = [
            [
                'label' => 'Получател',
                'value' => (string) $deal->delivery_name,
                'hint'  => null,
            ],
            [
                'label' => 'Телефон',
                'value' => (string) $deal->delivery_phone,
                'hint'  => 'Куриерът звъни на този номер.',
            ],
            [
                'label' => $deal->delivery_kind === 'office' ? 'До офис' : 'До адрес',
                'value' => $destination,
                'hint'  => null,
            ],
            [
                /*
                 * The number the buyer hands over at the counter, and the one
                 * place where getting it wrong costs real money. Printed as the
                 * agreed price and nothing else — no fee, no rounding, no
                 * platform cut, because there is none.
                 */
                'label' => 'Наложен платеж',
                'value' => $deal->formattedAgreedPrice(),
                'hint'  => 'Точно тази сума. Платформата не взима нищо от нея.',
            ],
            [
                // Insurance is priced off this, and a courier that loses a
                // €600 card pays out on what the waybill says it was worth.
                'label' => 'Обявена стойност',
                'value' => $deal->formattedAgreedPrice(),
                'hint'  => 'Оттук се определя обезщетението при загуба или щета.',
            ],
            [
                'label' => 'Съдържание',
                'value' => $this->contents(),
                'hint'  => 'Опиши вещта — куриерът не приема „техника" като описание.',
            ],
        ];

        if ($deal->inspect_test_selected && $courier?->inspectTestName()) {
            $fields[] = [
                'label' => 'Допълнителна услуга',
                // Each courier's own name for it. „преглед и тест" asked for at
                // a Speedy counter gets a blank look, and this is the one
                // instruction on the site that has to survive contact with a
                // courier employee.
                'value' => '„'.$courier->inspectTestName().'"',
                'hint'  => 'Купувачът отваря и пробва пратката, преди да плати.',
            ];
        }

        if (filled($deal->delivery_note)) {
            $fields[] = [
                'label' => 'Бележка от купувача',
                'value' => (string) $deal->delivery_note,
                'hint'  => null,
            ];
        }

        return $fields;
    }

    /**
     * One block of text for the copy button.
     *
     * Tab-separated label and value: it pastes readably into a courier form, a
     * notes app and a chat window, which are the three places it actually goes.
     */
    public function asText(): string
    {
        $lines = array_map(
            fn (array $f) => $f['label'].': '.$f['value'],
            $this->fields(),
        );

        return implode("\n", $lines);
    }

    /**
     * What is in the box, in words a courier will accept.
     *
     * The listing title plus the category, because „RTX 4070" alone means
     * nothing to a clerk filling in a customs-style content field, and because a
     * refused parcel at the counter is the failure this whole panel exists to
     * prevent.
     */
    private function contents(): string
    {
        $listing = $this->deal->listing;

        if (! $listing) {
            return 'компютърен компонент';
        }

        // `label.bg`, NOT `label_bg` — checked against config/catalog.php rather
        // than typed from memory, which is how `City` had no `name` column.
        // It is the plural category name („Видеокарти"), which reads fine after
        // an em dash and badly in parentheses.
        $category = config('catalog.categories.'.$listing->category.'.label.bg');

        return $category
            ? $listing->title.' — '.$category
            : $listing->title;
    }
}
