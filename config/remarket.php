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
        'sender_id'         => env('BULKGATE_SENDER_ID', 'REMARKET'),
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

    'listings' => [
        'expire_after_days'   => 60,
        'bump_cooldown_hours' => 24,
        'max_images'          => 12,
        'min_images'          => 1,
        // Private sellers must photograph the item beside a handwritten note
        // showing their username and the date. This single rule kills
        // stock-photo scams outright.
        'require_timestamp_photo_for_private' => true,
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
];
