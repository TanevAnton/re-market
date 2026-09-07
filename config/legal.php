<?php

/**
 * Who is behind this site.
 *
 * These details appear on five public pages and are quoted in the statement of
 * reasons, so they live in exactly one place. Several are legal obligations
 * rather than good manners: DSA Art. 11 and 12 require published contact
 * points, and ЗЕТ Art. 4 requires the provider to be identifiable.
 *
 * ЕИК, address and the mailboxes are intentionally empty until real values are
 * put in .env. An invented company number on a legal page is worse than a blank
 * one - a blank is obviously unfinished, an invented one is a false statement
 * about a real registered company.
 */

return [

    'entity' => [
        'name'     => env('LEGAL_ENTITY_NAME', 'КОМПНЕТ СОЛЮШЪНС ООД'),
        'name_en'  => env('LEGAL_ENTITY_NAME_EN', 'COMPNET SOLUTIONS Ltd.'),
        'eik'      => env('LEGAL_ENTITY_EIK'),          // ЕИК / БУЛСТАТ
        'vat'      => env('LEGAL_ENTITY_VAT'),          // ДДС номер, ако е регистрирано
        'address'  => env('LEGAL_ENTITY_ADDRESS'),      // седалище и адрес на управление
        'manager'  => env('LEGAL_ENTITY_MANAGER'),      // управител
    ],

    /*
     * DSA Art. 11 (authorities) and Art. 12 (users) require SEPARATE published
     * contact points. They may be the same mailbox, but they must both be
     * published, and Art. 12 says the user-facing one must not rely solely on
     * automated tools - a bot-only address does not satisfy it.
     */
    'contact' => [
        'users'       => env('LEGAL_CONTACT_USERS'),        // Art. 12
        'authorities' => env('LEGAL_CONTACT_AUTHORITIES'),  // Art. 11
        'privacy'     => env('LEGAL_CONTACT_PRIVACY'),      // GDPR requests
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
