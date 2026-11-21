<?php

namespace App\Support\Market;

use RuntimeException;

/**
 * The site said no - or something that looks like it. Ends the whole run: a
 * server that answers 403, 429 or a challenge page is not asking to be asked
 * again in four seconds.
 */
final class MarketBlocked extends RuntimeException {}
