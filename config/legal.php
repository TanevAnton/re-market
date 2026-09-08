<?php

/**
 * Who is behind this site.
 *
 * These details appear on five public pages and are quoted in the statement of
 * reasons, so they live in exactly one place. Several are legal obligations
 * rather than good manners: DSA Art. 11 and 12 require published contact
 * points, and ЗЕТ Art. 4 requires the provider to be identifiable.
 *
 * Anything still missing renders as a visible red placeholder naming the env
 * variable, rather than as a blank. An invented company number on a legal page
 * is worse than a missing one: a gap is obviously unfinished, an invention is a
 * false statement about a real registered company.
 */

return [

    /*
     * RE-MARKET is owned by RE-Tech (re-tech.bg), and the registered entity
     * behind that brand is КОМПНЕТ СОЛЮШЪНС ООД. "RE-Tech" is a trade name and
     * must not appear here - DSA Art. 11 and ЗЕТ чл. 4 both want the company
     * that can actually be served with a notice.
     *
     * These are defaults rather than .env values because they come from the
     * Trade Register: public record, identical on every deployment, and a legal
     * page that is blank because someone forgot an environment variable is
     * worse than one that is simply correct everywhere.
     */
    'entity' => [
        'name'     => env('LEGAL_ENTITY_NAME', 'КОМПНЕТ СОЛЮШЪНС ООД'),
        'name_en'  => env('LEGAL_ENTITY_NAME_EN', 'COMPNET SOLUTIONS Ltd'),
        'eik'      => env('LEGAL_ENTITY_EIK', '208386399'),

        /*
         * VAT is deliberately EMPTY despite BG208386399 existing.
         *
         * That registration is under ЗДДС чл. 97а - the special regime for
         * cross-border services, which the company is obliged to hold because
         * it buys services from abroad. It is NOT ordinary VAT registration:
         * the company does not charge VAT on domestic supplies under it.
         *
         * Publishing it as "ДДС номер" on a public page invites the reader to
         * assume ordinary registration, which is a misleading statement about
         * a real company's tax status. Fill this in only if and when ordinary
         * registration happens - the €51,130 turnover threshold, or voluntary.
         */
        'vat'      => env('LEGAL_ENTITY_VAT'),

        // Седалище и адрес на управление. Postcode NOT included: the register
        // extract shows a settlement code, not a postal code, and inventing
        // one on a legal page is the same mistake as inventing an ЕИК.
        'address'  => env(
            'LEGAL_ENTITY_ADDRESS',
            'гр. Велико Търново, ул. Георги Измирлиев 15, вх. А, ет. 6, ап. 18',
        ),

        // Управител - the person who can actually bind the company. Note this
        // is NOT Ivan, who is a 33% съдружник: anything needing a signature on
        // behalf of the company (the A1 sender-ID declaration, for one) goes
        // through Emanoel Tasev.
        'manager'  => env('LEGAL_ENTITY_MANAGER', 'Еманоел Александров Тасев'),
    ],

    /*
     * DSA Art. 11 (authorities) and Art. 12 (users) require SEPARATE published
     * contact points. They may be the same mailbox, but they must both be
     * published, and Art. 12 says the user-facing one must not rely solely on
     * automated tools - a bot-only address does not satisfy it.
     */
    'contact' => [
        /*
         * office@ for people, legal@ for authorities and data-protection
         * requests - which is also the better split than one shared mailbox:
         * "legal@" printed as the user-facing address reads as somewhere you
         * send a lawsuit rather than a question, and it would cost real reports.
         *
         * All of these move to the real domain once it exists. A marketplace
         * whose published contact sits on another company's domain looks like a
         * front to anyone who checks - and the people who check are exactly the
         * cautious buyers worth keeping.
         */
        'users'       => env('LEGAL_CONTACT_USERS', 'office@re-tech.bg'),       // Art. 12
        'authorities' => env('LEGAL_CONTACT_AUTHORITIES', 'legal@re-tech.bg'),  // Art. 11
        'privacy'     => env('LEGAL_CONTACT_PRIVACY', 'legal@re-tech.bg'),      // GDPR requests
        'phone'       => env('LEGAL_CONTACT_PHONE'),
        // Art. 11(3): the languages an authority may use with us.
        'languages'   => ['български', 'English'],
    ],

    // Shown as "последна редакция". Bump it whenever the text changes - users
    // are entitled to know the terms moved under them.
    'updated_at' => env('LEGAL_UPDATED_AT', '2026-09-07'),

    /*
     * Retention, published because a schedule nobody can see is not a schedule.
     * Keep this table and the actual deletion jobs in step; the day they differ
     * this page becomes a written admission rather than a policy.
     */
    'retention' => [
        'listings'   => 'активни обяви + 12 месеца след изтичане',
        'messages'   => '24 месеца от последното съобщение в разговора',
        'offers'     => '24 месеца',
        'moderation' => '6 месеца след решението',
        'reports'    => '6 месеца след решението',
        'phone_hash' => 'безсрочно (само необратим хеш, срещу заобикаляне на блокировки)',
        'account'    => 'до изтриване на профила, след което 30 дни в архив',
    ],
];
