<?php

namespace App\Support\Market;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fetches OLX.bg search pages, politely.
 *
 * Every request goes through `get()`, which (1) refuses any path the site's
 * robots.txt disallows, (2) waits a random few seconds first, (3) says who is
 * asking, and (4) turns a 403, 429, 5xx or a bot-challenge page into
 * `MarketBlocked`, which ends the run. There is no retry and no way round a
 * refusal, deliberately.
 */
class OlxClient
{
    private ?RobotsGuard $robots = null;

    private int $requests = 0;

    public function requests(): int
    {
        return $this->requests;
    }

    /** The URL for page N of a search, or null when robots.txt forbids it. */
    public function searchPath(string $query, int $page = 1): string
    {
        $path = str_replace('{q}', Str::slug($query), (string) config('market.olx.path'));

        return $page > 1 ? $path.'?page='.$page : $path;
    }

    /**
     * @throws MarketBlocked
     */
    public function search(string $query, int $page = 1): string
    {
        return $this->get($this->searchPath($query, $page));
    }

    /** @throws MarketBlocked */
    public function get(string $pathAndQuery): string
    {
        if (! $this->robots()->allows($pathAndQuery)) {
            throw new MarketBlocked("robots.txt disallows {$pathAndQuery}");
        }

        $this->pause();

        return $this->fetch($pathAndQuery);
    }

    private function robots(): RobotsGuard
    {
        if ($this->robots) {
            return $this->robots;
        }

        $response = Http::withUserAgent((string) config('market.user_agent'))
            ->timeout((int) config('market.olx.timeout', 20))
            ->get(rtrim((string) config('market.olx.base'), '/').'/robots.txt');

        // No readable robots.txt is not permission to do anything unusual, but
        // it is also not a rule; an unreadable one stops the run so that a
        // block page is never mistaken for „no restrictions".
        if (! $response->successful()) {
            throw new MarketBlocked('robots.txt could not be read ('.$response->status().')');
        }

        return $this->robots = new RobotsGuard($response->body());
    }

    private function fetch(string $pathAndQuery): string
    {
        $this->requests++;

        $response = Http::withUserAgent((string) config('market.user_agent'))
            ->withHeaders(['Accept-Language' => 'bg-BG,bg;q=0.9'])
            ->timeout((int) config('market.olx.timeout', 20))
            ->get(rtrim((string) config('market.olx.base'), '/').$pathAndQuery);

        $status = $response->status();

        if (in_array($status, [401, 403, 429], true) || $status >= 500) {
            throw new MarketBlocked("HTTP {$status} for {$pathAndQuery}");
        }

        if (! $response->successful()) {
            throw new MarketBlocked("HTTP {$status} for {$pathAndQuery}");
        }

        $body = $response->body();

        if (preg_match('/cf-chl|Just a moment\.\.\.|captcha|Attention Required/i', mb_substr($body, 0, 6000))) {
            throw new MarketBlocked('a bot-challenge page was returned');
        }

        return $body;
    }

    private function pause(): void
    {
        if ($this->requests === 0) {
            return;
        }

        [$min, $max] = (array) config('market.delay_ms', [4000, 9000]);

        $ms = $max > $min ? random_int((int) $min, (int) $max) : (int) $min;

        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
