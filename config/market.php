<?php

/*
 * Outside price reference: what a model is asked for on OLX.bg, used as the
 * seller's price guide while RIGO has too few listings of its own to make one.
 *
 * OFF BY DEFAULT. Nothing contacts another site until MARKET_PRICES_ENABLED is
 * true, and that is a decision somebody makes on purpose, not a side effect of
 * deploying. Turning it off again stops the weekly job; figures already saved
 * simply age out after `max_age_days` and stop being shown.
 *
 * WHAT IT DOES AND DOES NOT KEEP. It fetches public search-result pages, reads
 * a title and a price from each ad, decides whether the ad is about the right
 * card, and stores ONE ROW PER MODEL: p25, median, p75 and how many ads that
 * was. Not the ads, their text, photos, addresses or sellers - the title is read
 * once and discarded. The figures are shown only to a seller on the price step
 * and never on a public page, never as a badge on anybody's listing, and always
 * labelled as asking prices in OLX.bg, as of a date.
 *
 * HOW IT BEHAVES. It identifies itself (user agent with a contact address),
 * reads the site's robots.txt before every run and refuses any path it
 * disallows, waits several seconds between requests, reads a few pages per model
 * and a weekly run in total, and STOPS - rather than retries - on a 403, 429 or
 * a challenge page. A site that says no gets no.
 */

return [

    'enabled' => (bool) env('MARKET_PRICES_ENABLED', false),

    /*
     * The URL template. {q} becomes the slugged chipset, e.g. „rtx-3060-ti".
     * The default is the global search, which also returns laptops and whole
     * computers - the matcher filters them out, but a category-scoped path is
     * far cleaner: open OLX.bg's „Видеокарти" category in a browser, copy the
     * path before „q-...", and set it here, e.g. /elektronika/.../q-{q}/.
     *
     * Never a `?q=` query string: the site's robots.txt disallows those, and
     * the client refuses them.
     */
    'olx' => [
        'base'       => env('OLX_BASE_URL', 'https://www.olx.bg'),
        'path'       => env('OLX_SEARCH_PATH', '/ads/q-{q}/'),
        'pages'      => (int) env('OLX_PAGES', 3),
        'timeout'    => 20,
    ],

    // Identifies us to the site, with somewhere to write if it is a problem.
    'user_agent' => env('MARKET_USER_AGENT', 'RIGO-pricebot/1.0 (+https://rigo.bg/kontakti)'),

    // A random wait between requests, in milliseconds. Slow on purpose.
    'delay_ms' => [(int) env('MARKET_DELAY_MIN_MS', 4000), (int) env('MARKET_DELAY_MAX_MS', 9000)],

    // Fewer matching ads than this and the model gets no outside figure at all.
    'min_sample' => (int) env('MARKET_MIN_SAMPLE', 8),

    // Older than this and the figure is no longer shown.
    'max_age_days' => (int) env('MARKET_MAX_AGE_DAYS', 21),

    /*
     * Ads matching any of these (regular expressions, case-insensitive, run
     * against the lower-cased title) are left out: laptops, whole computers and
     * bundles, broken or for-parts, wanted and swap ads, and any ad that names
     * a processor, which is a bundle however it is worded.
     */
    'exclude' => [
        'лаптоп|laptop|notebook|ноутбук|макбук|macbook',
        'за части|дефект|неработещ|не работи|счупен|повреден|ремонт',
        'комплект|конфигурация|компютър|геймърски комп|\bpc\b|gaming pc|кутия',
        'търся|купувам|замяна|бартер|размяна|swap',
        '\bi[3579][ -]?\d{3,5}|\bryzen\b|\bcore i[3579]|\bpentium\b',
        'воден блок|waterblock|охладител за|райзер|riser',
    ],
];
