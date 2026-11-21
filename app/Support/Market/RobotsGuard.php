<?php

namespace App\Support\Market;

/**
 * Reads a robots.txt and answers one question: may we fetch this path?
 *
 * Only the `User-agent: *` group is honoured, the longest matching rule wins
 * and Allow beats Disallow on a tie - the way crawlers are expected to read
 * the file. `*` and a trailing `$` work as wildcards. A site that has not
 * published rules, or whose rules we cannot read, is treated as saying nothing
 * rather than as permission: the caller decides what to do with a null file.
 *
 * It exists so that the one thing the site has asked crawlers not to do - here,
 * the `?q=` search form and `/api/` - is something this code cannot do by
 * accident, whatever somebody later changes in the URL template.
 */
final class RobotsGuard
{
    /** @var list<array{allow: bool, pattern: string}> */
    private array $rules = [];

    public function __construct(string $robotsTxt)
    {
        $applies = false;
        $seenAgentLine = false;

        foreach (preg_split('/\R/u', $robotsTxt) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                // A new group starts at the first user-agent line after rules.
                if (! $seenAgentLine) {
                    $applies = false;
                }
                $applies       = $applies || $value === '*';
                $seenAgentLine = true;

                continue;
            }

            $seenAgentLine = false;

            if (! $applies || $value === '') {
                continue;
            }

            if ($field === 'allow' || $field === 'disallow') {
                $this->rules[] = ['allow' => $field === 'allow', 'pattern' => $value];
            }
        }
    }

    public function allows(string $pathAndQuery): bool
    {
        $bestLength = -1;
        $allowed    = true;

        foreach ($this->rules as $rule) {
            if (! self::matches($rule['pattern'], $pathAndQuery)) {
                continue;
            }

            $length = strlen($rule['pattern']);

            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $bestLength = $length;
                $allowed    = $rule['allow'];
            }
        }

        return $allowed;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\*', '.*', $regex);

        if (str_ends_with($regex, '\$')) {
            $regex = substr($regex, 0, -2).'$';
        }

        return (bool) preg_match('#^'.$regex.'#', $path);
    }
}
