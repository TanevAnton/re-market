<?php

/**
 * Product rules that are decisions, not constants. Everything here is a knob
 * someone will want to turn after watching real user behaviour - so it lives
 * in config and .env rather than being scattered through the codebase.
 */

return [

    // Rotating this orphans every stored phone hash and lets banned numbers
    // register again. Treat it as permanent once the site is live.
    'phone_hash_salt' => env('PHONE_HASH_SALT', ''),

    // Where a user challenges a moderation decision. DSA Art. 17(3)(f) requires
    // telling them how to appeal, so this stops being optional at launch.
    'support_email' => env('SUPPORT_EMAIL'),

    /*
     * Paid visibility. Prices are in EURO cents, like every other price on
     * this site.
     *
     * `max_pinned_per_page` is the one that protects the product rather than
     * the revenue. The moment a third of the grid is paid placement, the
     * reason anybody chose this over OLX is gone — and the pressure to raise
     * it will come from the only part of the site that earns money, so it
     * lives here where changing it is a visible decision.
     */
    'boosts' => [
        'prices' => [
            'bump'      => (int) env('BOOST_PRICE_BUMP', 100),
            'highlight' => (int) env('BOOST_PRICE_HIGHLIGHT', 300),
            'pin'       => (int) env('BOOST_PRICE_PIN', 900),
            // The homepage. Roughly 3x the category pin, like every other rung
            // — one audience shared by every category is genuinely scarcer
            // than a slot in one of nineteen of them.
            'front'     => (int) env('BOOST_PRICE_FRONT', 2700),
        ],
        'highlight_days'      => (int) env('BOOST_HIGHLIGHT_DAYS', 7),
        'pin_days'            => (int) env('BOOST_PIN_DAYS', 7),
        'front_days'          => (int) env('BOOST_FRONT_DAYS', 7),
        'max_pinned_per_page' => (int) env('BOOST_MAX_PINNED_PER_PAGE', 2),
        // Eight is what the homepage rail holds. Raising it makes every slot
        // worth less to everyone who already bought one, so it is a number
        // somebody has to come here and change.
        'max_front_page'      => (int) env('BOOST_MAX_FRONT_PAGE', 8),
        'topup_options'       => [500, 1000, 2000, 5000],
    ],

    /*
     * Who issues the invoices, and on what VAT basis.
     *
     * EVERY FIELD HERE ENDS UP PRINTED ON A LEGAL DOCUMENT, which is why none
     * of them has a plausible-looking default. An invoice with an invented
     * company name or a guessed VAT treatment is worse than no invoice: it is
     * a wrong document with a real sequential number on it, and the fix for
     * that is a credit note rather than an edit.
     *
     * `PaymentService::confirm()` refuses to issue while any required field is
     * blank, and `php artisan doctor` says so before a live deployment. That
     * refusal is the feature — see BillingIdentity.
     *
     * VAT_REGISTERED decides which of two things the document says. Registered:
     * the rate is charged and shown. Not registered: a GROUND for charging
     * none has to be stated, and which ground applies to a Bulgarian company
     * selling digital services to Bulgarian consumers is a question for an
     * accountant, not for this file. VAT_EXEMPT_NOTE is where their answer
     * goes, verbatim.
     */
    'billing' => [
        /*
         * WHO ISSUES IT IS NOT HERE. The company behind this site is defined
         * once, in config/legal.php, from the Trade Register, for the five
         * public legal pages — and App\Support\BillingIdentity reads it from
         * there. A second copy in this file would be two places for one
         * company's name to be wrong, and the wrong one would be the one
         * nobody reads until an invoice is disputed.
         *
         * Not even as `config('legal.entity.name')` here: a config file that
         * reads another config file works only because of the order Laravel
         * happens to load them in, which is a dependency nobody can see.
         *
         * The bank account is the one genuinely new identity fact, and it is
         * not public record, so it stays in .env.
         */
        'iban'           => env('BILLING_IBAN'),
        'bic'            => env('BILLING_BIC'),
        'bank'           => env('BILLING_BANK'),

        /*
         * FALSE, AND THE REASON IS ALREADY WRITTEN DOWN IN config/legal.php.
         *
         * BG208386399 exists, but it is a ЗДДС чл. 97а registration — the
         * special regime for buying services from abroad. It is NOT ordinary
         * VAT registration, and the company does not charge VAT on domestic
         * supplies under it. Putting it on an invoice as „ДДС номер" and
         * charging 20% would be a false statement about a real company's tax
         * status, in a document that is kept for ten years.
         *
         * So this stays false until ordinary registration actually happens —
         * the turnover threshold, or voluntarily — and BILLING_VAT_EXEMPT_NOTE
         * carries the ground for charging none. That sentence is the
         * ACCOUNTANT'S, copied exactly; there is no default for it here
         * because nobody in this file knows it.
         */
        'vat_registered' => (bool) env('BILLING_VAT_REGISTERED', false),
        'vat_number'     => env('BILLING_VAT_NUMBER'),
        'vat_rate'       => (float) env('BILLING_VAT_RATE', 20),
        'vat_exempt_note'=> env('BILLING_VAT_EXEMPT_NOTE'),

        // What a seller may put in at once. Small enough that a first-time
        // buyer is not asked to trust the site with much.
        'topup_options'  => [1000, 2000, 5000, 10000],
        'topup_min'      => (int) env('BILLING_TOPUP_MIN', 500),
        'topup_max'      => (int) env('BILLING_TOPUP_MAX', 50000),
    ],

    // Cloudflare Turnstile. Cookieless, so it needs no consent-banner entry
    // under ЗЕС, and free at any volume. With either key missing the challenge
    // disables itself entirely - which is what keeps local and LAN testing
    // working, and what must be checked before launch.
    'turnstile' => [
        'site_key'   => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'verify' => [
        // Cheapest channel first. Telegram ~$0.01, Viber ~EUR 0.017, SMS ~EUR 0.04.
        // The same volume on Twilio Verify would be roughly 10x the SMS price.
        'channels'     => explode(',', (string) env('VERIFY_CHANNELS', 'telegram,viber,sms')),
        'code_ttl'     => (int) env('VERIFY_CODE_TTL_MINUTES', 10),
        'max_attempts' => (int) env('VERIFY_MAX_ATTEMPTS', 5),

        // With none of these set, PhoneVerifier falls back to the log channel
        // so local signup works with no credentials and no spend.
        // Escape hatch for a staging or LAN box with no SMS credentials.
        // Writes the code to storage/logs/laravel.log instead of sending it -
        // which also means anyone who can read that log can verify anyone's
        // number. Never set this where real accounts exist.
        'allow_log_channel_in_production' => (bool) env('VERIFY_ALLOW_LOG_CHANNEL', false),

        'telegram_token'    => env('TELEGRAM_GATEWAY_TOKEN'),

        // The BOT flow - free at any volume, and stronger than a code: the
        // user shares their contact and Telegram vouches for the number it
        // verified when they signed up. Nothing is sent, so nothing can be
        // intercepted. Token from @BotFather.
        'telegram_bot_token'    => env('TELEGRAM_BOT_TOKEN'),
        'telegram_bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'telegram_link_ttl'     => (int) env('TELEGRAM_LINK_TTL_MINUTES', 15),
        'bulkgate_app_id'   => env('BULKGATE_APP_ID'),
        'bulkgate_token'    => env('BULKGATE_APP_TOKEN'),
        // Max 11 alphanumeric characters, and the operator has to believe you own
        // the brand. RIGO is four, which leaves room and is easy to read in an SMS.
        'sender_id'         => env('BULKGATE_SENDER_ID', 'RIGO'),
    ],
    'verify_max_attempts' => (int) env('VERIFY_MAX_ATTEMPTS', 5),

    'offers' => [
        'ttl_hours'         => (int) env('OFFER_TTL_HOURS', 48),
        'decline_cooldown'  => (int) env('OFFER_DECLINE_COOLDOWN_HOURS', 24),
        'max_per_listing'   => (int) env('OFFER_MAX_PER_LISTING', 3),
        'note_max_length'   => 200,
        // A counter-offer may be sent once per offer chain. More than that and
        // it is haggling again, which is the thing we exist to remove.
        'max_counters'      => 1,
    ],

    'deals' => [
        'reservation_hours' => (int) env('DEAL_RESERVATION_HOURS', 72),
        // Storing an exact agreed price makes the amount "reasonably knowable"
        // and weakens the DAC7 advertising carve-out. See plan section 8.4 -
        // set to false to record a band instead.
        'store_exact_price' => (bool) env('DEAL_STORE_EXACT_PRICE', true),
    ],

    'seo' => [
        /*
         * OFF by default, and that is the safe direction.
         *
         * The site currently runs on a LAN box at an IP address. If that ever
         * became reachable and indexable it would compete with the real domain
         * for its own content on the day it launches, and duplicate content
         * under a different host is the hardest kind to undo.
         *
         * Set SEO_INDEXABLE=true in .env on the real domain, and nowhere else.
         */
        'indexable' => (bool) env('SEO_INDEXABLE', false),
    ],

    /*
     * The catalogue pages - our whole organic-search strategy. Someone typing
     * "RTX 4070 цена бг" should land on a page that answers the question, not
     * on a search result that might be empty this week.
     */
    'parts' => [
        // Below this many live listings a "market price" is one person's
        // opinion, so no band is shown at all. Low on purpose while the site
        // is young: a threshold nothing meets means no page has a band, and
        // the band is the reason to visit the page.
        'price_band_min_listings' => (int) env('PART_PRICE_BAND_MIN', 3),

        // A band computed a month ago is quoted in negotiations as if it were
        // today's. Past this age the page stops showing it.
        'price_band_max_age_days' => (int) env('PART_PRICE_BAND_MAX_AGE', 7),

        // Landing pages with nothing on them are worse than no landing page:
        // they teach a crawler the site is thin. Below this, the page still
        // works for anyone with the link but stays out of the sitemap.
        'sitemap_min_listings'    => (int) env('PART_SITEMAP_MIN', 1),

        /*
         * The public „под средното за модела" badge, which is a stricter claim
         * than the band and so has its own two thresholds.
         *
         * The band exists to inform; the badge exists to say a specific listing
         * is a good buy, in public, in our own voice. Cheapest-of-three is not
         * a deal, it is arithmetic about three people - hence a higher sample
         * floor than the band's. And a badge that fires at 2% teaches buyers
         * to ignore the badge, which costs more than never showing it.
         */
        'deal_badge_min_listings' => (int) env('PART_DEAL_BADGE_MIN', 5),
        'deal_badge_min_percent'  => (int) env('PART_DEAL_BADGE_PERCENT', 7),

        /*
         * Price history — the window the pages read, and what the series has to
         * contain before anybody is told a direction.
         *
         * `min_points` and `min_days` are two different refusals. Four points
         * spread over three days is not a trend, and neither is two points a
         * month apart; a series has to be both long enough and dense enough
         * before a percentage off it means anything. On a thin market one
         * seller relisting moves a median several percent, so a number
         * published without those guards is noise reported as news - and a
         * seller who cuts their price because of it has been misled by us.
         *
         * `flat_percent` matches the price-drop notification's threshold on
         * purpose: what counts as "worth telling somebody" should not depend on
         * which screen they happen to be looking at.
         */
        'history_days'         => (int) env('PART_HISTORY_DAYS', 90),
        'history_min_points'   => (int) env('PART_HISTORY_MIN_POINTS', 4),
        'history_min_days'     => (int) env('PART_HISTORY_MIN_DAYS', 14),
        'history_flat_percent' => (int) env('PART_HISTORY_FLAT_PERCENT', 3),
    ],

    'listings' => [
        /*
         * Below this many live listings in a signed-in visitor's own city, the
         * browse page does NOT start them there. A local-first default is only
         * a kindness while the local market has something in it; under a
         * handful it is a page that says the site is dead, and the visitor has
         * no way of knowing a filter they never set is the reason.
         */
        'home_city_min'       => (int) env('BROWSE_HOME_CITY_MIN', 3),

        'expire_after_days'   => 60,
        'bump_cooldown_hours' => 24,
        'max_images'          => 12,
        'min_images'          => 1,

        /*
         * How long an unfinished wizard is kept before `remarket:prune-drafts`
         * deletes it and the photos it was holding.
         *
         * Generous on purpose. The draft exists because somebody walked away
         * mid-listing, and the whole point is that they can come back to it —
         * a week is the sort of interval that turns a rescue into a second
         * loss. The cost of being wrong in this direction is disk.
         */
        'draft_ttl_days'      => (int) env('LISTING_DRAFT_TTL_DAYS', 30),

        /*
         * Price-drop alerts to the people who shortlisted a listing.
         *
         * Two guards, because this is the only notification a buyer receives
         * about somebody else's decision, which makes it the easiest one to
         * experience as spam.
         *
         * The percentage is "is this news" - a 2 € cut on a 900 € card is not.
         * The cooldown is "is this a stream" - a seller feeling out the market
         * moves the price four times in an afternoon, each step over the
         * threshold, and the fourth message is what gets the channel muted.
         */
        'price_drop_min_percent'     => (int) env('PRICE_DROP_MIN_PERCENT', 3),
        'price_drop_cooldown_hours'  => (int) env('PRICE_DROP_COOLDOWN_HOURS', 24),
        // Private sellers must photograph the item beside a handwritten note
        // showing their username and the date. This single rule kills
        // stock-photo scams outright.
        /*
         * The handwritten-note photo is now optional.
         *
         * It is still the strongest single signal that a seller physically has
         * the item, so the mark, the badge and the rejection reason all stay -
         * and a moderator can still weigh its absence. What it stopped being is
         * a wall in front of publishing: a first-time seller who does not
         * understand the demand abandons the listing rather than fetching a pen,
         * and a marketplace with no supply catches no scammers either.
         *
         * Set back to true and the requirement returns exactly as it was.
         */
        'require_timestamp_photo_for_private' => env('REQUIRE_TIMESTAMP_PHOTO', false),
    ],

    /*
     * „Търся" — wanted ads.
     *
     * Shorter than a listing's sixty days on purpose: somebody who needed a
     * card in March has bought one by May, and an answered request left open
     * wastes the time of every seller who reads it.
     */
    'wanted' => [
        'expire_after_days' => (int) env('WANTED_EXPIRE_AFTER_DAYS', 30),
    ],

    /*
     * Serial numbers and IMEIs.
     *
     * THE PEPPER IS NOT OPTIONAL AND IT CAN NEVER CHANGE. Every serial is
     * stored as HMAC-SHA256 keyed with it, so:
     *
     *   - empty means the feature is off, and the screens hide it rather than
     *     writing rows that could never be matched;
     *   - rotating it does not invalidate the register, it SILENTLY EMPTIES it
     *     while leaving every row in place. Nothing fails; matches just stop.
     *
     * Generate once, put it in .env, back it up with the database rather than
     * separately from it, and never touch it again:
     *
     *   php -r "echo bin2hex(random_bytes(32));"
     */
    'identifiers' => [
        'pepper' => env('IDENTIFIER_PEPPER'),
        // The buyer lookup is a gift to somebody checking whether their own
        // haul is 'hot', so it is signed-in and rate-limited. Generous enough
        // for a person with a box of parts, mean enough to be useless for a
        // sweep.
        'lookups_per_hour' => (int) env('IDENTIFIER_LOOKUPS_PER_HOUR', 30),
    ],

    'antispam' => [
        'moderated_listings_for_new_accounts' => (int) env('NEW_ACCOUNT_MODERATED_LISTINGS', 2),
        'limits' => [
            'day_one'  => (int) env('LISTING_LIMIT_DAY_ONE', 2),
            'week_one' => (int) env('LISTING_LIMIT_WEEK_ONE', 5),
            'trusted'  => (int) env('LISTING_LIMIT_TRUSTED', 20),
        ],
        'trusted_after_deals' => 3,
        // Hamming distance under this means the same photograph.
        'phash_distance'      => (int) env('PHASH_DISTANCE_THRESHOLD', 8),
        'price_outlier_low'   => (int) env('PRICE_OUTLIER_LOW_PCT', 40),
        'price_outlier_high'  => (int) env('PRICE_OUTLIER_HIGH_PCT', 250),
        // A median over two listings is not a median. Below this the price
        // check says nothing rather than guessing - flagging every third
        // listing of a new catalogue part would train the moderator to approve
        // without looking, which is worse than not checking.
        'price_sample_minimum' => (int) env('PRICE_SAMPLE_MINIMUM', 5),
    ],
    'phash_distance_threshold' => (int) env('PHASH_DISTANCE_THRESHOLD', 8),

    'couriers' => [
        'econt' => [
            'url'      => env('ECONT_API_URL', 'https://demo.econt.com/ee/services/'),
            'username' => env('ECONT_USERNAME'),
            'password' => env('ECONT_PASSWORD'),
        ],
        'speedy' => [
            'url'      => env('SPEEDY_API_URL', 'https://api.speedy.bg/v1/'),
            'username' => env('SPEEDY_USERNAME'),
            'password' => env('SPEEDY_PASSWORD'),
        ],
    ],

    /*
     * „Обикновено отговаря до 2 часа."
     *
     * Measured from the message timestamps, never stored. See
     * App\Support\ReplySpeed for why the answered RATE is in here too: reply
     * time can only be measured on threads that got a reply, so without a floor
     * on the rate a seller who answers one message in five and answers it fast
     * outscores one who answers all five within a day.
     */
    'replies' => [
        'window_days'     => (int) env('REPLY_WINDOW_DAYS', 90),

        // Below this many threads there is no habit to report, only an anecdote.
        'min_threads'     => (int) env('REPLY_MIN_THREADS', 3),

        // Answer at least this share of them, or the median is flattering.
        'min_answer_rate' => (float) env('REPLY_MIN_ANSWER_RATE', 0.6),

        /*
         * One-sided, like the deal badge: shown when it is good, absent when it
         * is not. „Отговаря до 6 дни" reads as a punishment for a seller with a
         * job, and the absence of a badge is not a claim about anybody.
         */
        'max_hours'       => (int) env('REPLY_MAX_HOURS', 24),
    ],

    /*
     * DemoSeeder's scale. Nothing reads these outside the seeder, and the
     * seeder refuses to run at all when `seo.indexable` is true.
     *
     * They exist because the seed is EXPENSIVE, and the cost is almost entirely
     * photographs: each one goes through the real ImageProcessor, which decodes
     * and re-encodes four times (full, thumbnail, and the hash's own reduction).
     * At the defaults that is ~190 listings and ~380 photographs, which is what
     * you want to look at and emphatically not what you want a test suite
     * repeating for every assertion.
     */
    /*
     * The two couriers, and the two things RIGO needs to know about them.
     *
     * NO API CREDENTIALS HERE, AND THAT IS THE DESIGN. RIGO holds no contract
     * with either courier: the seller creates the waybill in their own account,
     * because whoever creates it is the sender of record and the sender of record
     * is who the cash-on-delivery is paid to. The platform never touching the
     * money is the whole regulatory position. See App\Support\WaybillDraft.
     *
     * `tracking_url` defaults to each courier's plain tracking page, which has
     * been verified. Their DEEP-LINK parameter has not, and a button that lands
     * on an error page teaches the buyer to stop trusting the screen — the same
     * reason the trader queue links to the Commercial Register's home page rather
     * than a guessed search URL. Put `{number}` in the env value where the
     * waybill number goes and it starts deep-linking with no code change; until
     * then the number is rendered beside the link for copying.
     */
    'couriers' => [
        'econt' => [
            'tracking_url' => env('COURIER_ECONT_TRACKING_URL', 'https://www.econt.com/services/track-shipment'),
        ],
        'speedy' => [
            'tracking_url' => env('COURIER_SPEEDY_TRACKING_URL', 'https://www.speedy.bg/bg/track-shipment'),
        ],
    ],

    /*
     * How long a finished deal keeps the buyer's name, phone and address.
     *
     * Long enough to reprint a label or sort out a courier dispute; short enough
     * that the table is not a list of where everybody who ever bought a graphics
     * card lives. `remarket:purge-delivery-details` does the erasing and the
     * transaction facts — courier, tracking number, inspect-and-test — survive it.
     */
    'delivery' => [
        'retention_days' => (int) env('DELIVERY_RETENTION_DAYS', 30),
    ],

    'demo' => [
        'per_category' => (int) env('DEMO_PER_CATEGORY', 10),

        // Photos per listing, 1..N. Lower is faster; zero is not allowed,
        // because a listing with no photograph is the bug this seeder was
        // rewritten to fix.
        'photos_max'   => max(1, (int) env('DEMO_PHOTOS_MAX', 3)),

        // History depth for the backfilled sparkline. Must stay above
        // `parts.history_min_days` or the trend refuses to draw and the demo
        // silently loses the feature.
        'history_days' => (int) env('DEMO_HISTORY_DAYS', 45),
    ],
];
