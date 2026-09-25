<?php

namespace App\Support;

/**
 * „May this site issue an invoice yet?" — asked in three places and answered
 * in one.
 *
 * THE POINT OF THIS CLASS IS TO SAY NO. An invoice carries a sequential number
 * that cannot be reused and a statement about VAT that is either right or a
 * problem. Every field it needs comes from `.env`, and `.env` on a fresh
 * server is empty — so without something standing in the way, the first
 * confirmed payment on a new deployment issues invoice 0000000001 with a blank
 * company name and, worse, silence where the VAT treatment should be.
 *
 * So: PaymentService refuses to confirm while anything is missing, the top-up
 * screen refuses to take a request, and `php artisan doctor` names the blanks
 * before launch rather than after the first payment.
 *
 * The VAT rule is the one worth reading twice. A company either charges VAT
 * and shows the rate, or does not charge it and states the GROUND — there is
 * no third option where the document simply does not mention it. So when
 * BILLING_VAT_REGISTERED is false, BILLING_VAT_EXEMPT_NOTE becomes required,
 * and what goes in it is the accountant's sentence, copied exactly.
 */
class BillingIdentity
{
    /** @return array<string, string> env key => what it is, for each blank */
    public static function missing(): array
    {
        /*
         * env key => [config key, what it is].
         *
         * Company, ЕИК and address are NOT here: they come from
         * config/legal.php, which has them from the Trade Register as
         * defaults, so on a fresh deployment they are already correct. The
         * only thing a new server genuinely does not know is where to be paid.
         */
        $required = [
            'BILLING_IBAN' => ['iban', 'IBAN за преводите'],
        ];

        $out = [];

        foreach ($required as $env => [$key, $what]) {
            if (blank(config('remarket.billing.'.$key))) {
                $out[$env] = $what;
            }
        }

        /*
         * The issuer's own identity comes from config/legal.php — Trade
         * Register data, shipped as defaults, so a fresh server already has it
         * right. Checked anyway, because an override set to an empty string
         * would blank it, and a blank company name must never reach a document.
         */
        foreach ([
            'LEGAL_ENTITY_NAME'    => ['name',    'фирмено наименование'],
            'LEGAL_ENTITY_EIK'     => ['eik',     'ЕИК'],
            'LEGAL_ENTITY_ADDRESS' => ['address', 'адрес по регистрация'],
        ] as $env => [$key, $what]) {
            if (blank(config('legal.entity.'.$key))) {
                $out[$env] = $what;
            }
        }

        if (config('remarket.billing.vat_registered')) {
            if (blank(config('remarket.billing.vat_number'))) {
                $out['BILLING_VAT_NUMBER'] = 'ДДС номер (регистрацията е включена)';
            }
        } elseif (blank(config('remarket.billing.vat_exempt_note'))) {
            // Not an oversight to be defaulted away: a document that charges
            // no VAT has to say on what ground, and nobody here knows it.
            $out['BILLING_VAT_EXEMPT_NOTE'] = 'основание за неначисляване на ДДС';
        }

        return $out;
    }

    public static function ready(): bool
    {
        return self::missing() === [];
    }

    /** The issuer's name, from the one place it is defined. */
    public static function company(): string
    {
        return (string) config('legal.entity.name');
    }

    /** The issuer block, frozen onto each invoice at the moment it is issued. */
    public static function issuerBlock(): string
    {
        $entity = config('legal.entity');
        $bank   = config('remarket.billing');

        return collect([
            $entity['name'],
            'ЕИК: '.$entity['eik'],
            // The VAT line appears ONLY under ordinary registration. чл. 97а
            // is not that, and printing its number here would invite the
            // reader to assume it was — see config/legal.php.
            $bank['vat_registered'] ? 'ДДС №: '.$bank['vat_number'] : null,
            $entity['address'],
            $entity['manager'] ? 'МОЛ: '.$entity['manager'] : null,
            'IBAN: '.$bank['iban'],
        ])->filter()->implode("\n");
    }

    /**
     * The VAT treatment for a gross amount, as it will be printed.
     *
     * INCLUSIVE, not added on top. The price a seller sees on the boost ladder
     * is the price they pay; quoting a figure to a consumer and then adding
     * tax to it at the till is the thing consumer-pricing rules exist to stop.
     * So the top-up amount IS the total, and the net is what is left after the
     * tax inside it.
     *
     * @return array{net: int, vat: int, total: int, rate: float, note: ?string}
     */
    public static function split(int $grossCents): array
    {
        if (! config('remarket.billing.vat_registered')) {
            return [
                'net'   => $grossCents,
                'vat'   => 0,
                'total' => $grossCents,
                'rate'  => 0.0,
                'note'  => config('remarket.billing.vat_exempt_note'),
            ];
        }

        $rate = (float) config('remarket.billing.vat_rate', 20);

        // Net rounded down, VAT taking the remainder, so net + vat is always
        // exactly the total — a document whose lines do not add up to its own
        // sum is a document somebody has to explain.
        $net = (int) floor($grossCents / (1 + $rate / 100));

        return [
            'net'   => $net,
            'vat'   => $grossCents - $net,
            'total' => $grossCents,
            'rate'  => $rate,
            'note'  => null,
        ];
    }
}
