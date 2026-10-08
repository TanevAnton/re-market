<?php

/**
 * The words that put a listing in front of a moderator.
 *
 * NOT A CENSOR. Nothing here rejects anything — a match sends the listing to
 * the review queue, same as a reused photograph or a suspicious price, and a
 * person decides. That is the rule everywhere else in this codebase and it is
 * load-bearing: an automated takedown is a restrictive decision under DSA
 * Art. 17 and needs a statement of reasons, which a word list cannot write.
 *
 * MATCHED AS WHOLE WORDS, NEVER AS SUBSTRINGS, and the list is written out in
 * full — inflections included — rather than as stems with a wildcard. The
 * reason is that Bulgarian makes substring matching actively dangerous:
 *
 *     кур   is inside  курс, курсор, курорт, курсив, конкурс, Меркурий
 *     гъз   is inside  гъзер
 *     бич   is inside  обичам
 *
 * A stem plus „up to three more letters" looks clever and blocks a seller
 * writing „курсор". They are never told why, so they retype it, fail again, and
 * leave. A missed insult costs a moderator ten seconds; a blocked honest
 * listing costs a seller, and this site has two of those.
 *
 * WHAT IS DELIBERATELY ABSENT, and must stay absent:
 *
 *   педал / педали — racing-sim PEDALS. A real product category here. This is
 *     the Scunthorpe problem landing directly on the catalogue, and it would
 *     block Logitech, Thrustmaster and Fanatec listings on a site that sells
 *     gaming gear.
 *   циганин / цигани — the ordinary word for Roma, not a slur in itself.
 *     Flagging it would put honest text in the queue and teach the moderator to
 *     approve without reading.
 *   маймуна — „monkey". Literal far more often than aimed at a person.
 *
 * Those three are listed in `never` below so that adding them back is a
 * deliberate act with a comment to argue with, rather than a plausible-looking
 * one-line edit.
 *
 * WHAT THIS DOES NOT CATCH, stated plainly so nobody assumes otherwise:
 * deliberate evasion. „к у р в а", „kurv@", a slur inside a photograph. Light
 * obfuscation is normalised away (see App\Support\Profanity), but a person who
 * sets out to defeat a word list will. The backstop is the report button and
 * the moderation queue, which is where this check sends things anyway.
 */

return [

    'enabled' => (bool) env('PROFANITY_FILTER', true),

    /*
     * Strong obscenity and slurs. The bar is „there is no honest reason for
     * this in a hardware listing" — not „this is rude". Mild swearing passes
     * on purpose: a queue full of „бачка" is a queue nobody works, and the
     * price and photo checks are already competing for the same attention.
     */
    'hold' => [
        // --- obscenity ---
        'кур', 'курът', 'курове',
        'курва', 'курви', 'курвата', 'курвар', 'курвенски',
        'путка', 'путки', 'путката',
        'еби', 'ебах', 'ебал', 'ебала', 'ебане', 'ебаси', 'ебати', 'ебавка',
        'шибан', 'шибана', 'шибано', 'шибани', 'шибаняк',
        'копеле', 'копелета', 'копелдак',
        'гъз', 'гъзове',
        'педераст', 'педерасти',
        'мръсница',

        // --- slurs ---
        // Aimed at people. A listing is not a place for any of them, and
        // unlike the obscenity above these are the ones that make the site
        // look like somewhere a buyer should not be.
        'мангал', 'мангали',
        'негър', 'негри',
        'жид', 'жидове',

        // --- latin transliterations ---
        // Usernames are alpha_dash, so Cyrillic cannot appear in one at all —
        // these are what a profane username would actually be spelled with.
        'kur', 'kurva', 'kurvi', 'putka', 'putki',
        'ebi', 'ebah', 'ebal', 'ebasi', 'ebati',
        'shiban', 'shibana', 'shibanqk', 'kopele',
        'mangal', 'mangali', 'pederast',
    ],

    /*
     * Words that must never be added to `hold`, with the reason attached.
     * App\Support\Profanity throws if one appears in both, so the argument has
     * to be had here rather than discovered in production.
     */
    'never' => [
        'педал'    => 'Racing-sim pedals. A product category on this site.',
        'педали'   => 'Racing-sim pedals. A product category on this site.',
        'циганин'  => 'The ordinary word for Roma. Not a slur in itself.',
        'цигани'   => 'The ordinary word for Roma. Not a slur in itself.',
        'маймуна'  => 'Means „monkey". Literal far more often than aimed at anybody.',
        'курс'     => 'Course / exchange rate. The classic substring trap.',
        'курсор'   => 'Cursor.',
        'гъзер'    => 'Slang for a show-off. Mild, and it contains a listed word.',
    ],

    /*
     * Light obfuscation, undone before matching.
     *
     * TWO MAPS, because the list is half Cyrillic and half Latin and one
     * substitution table cannot serve both: „k0rva" has to become „kurva" and
     * „к0рва" has to become „курва", and a single map turns one of them into a
     * mixed-script string that matches nothing. App\Support\Profanity tries
     * both and holds if either matches.
     *
     * Deliberately short. Every entry is a new way for innocent text to become
     * a listed word, and „RTX 4070" is a thing people type here all day.
     */
    'digit_map_cyrillic' => [
        '0' => 'о',
        '3' => 'е',
        '4' => 'а',
        '@' => 'а',
    ],

    'digit_map_latin' => [
        '0' => 'o',
        '3' => 'e',
        '4' => 'a',
        '@' => 'a',
    ],
];
