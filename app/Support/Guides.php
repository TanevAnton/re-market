<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The buyer's guides (config/guides.php) as pages, a sitemap section and
 * structured data.
 *
 * The content is data rather than four Blade files so the index, the sitemap,
 * the "related" links and the structured data all read from one place and
 * cannot disagree about which guides exist.
 */
class Guides
{
    /** @return array<string, array<string, mixed>> keyed by slug, in the order they are shown */
    public static function all(): array
    {
        return (array) config('guides', []);
    }

    /** @return array<string, mixed>|null */
    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /**
     * Config text to HTML.
     *
     * ESCAPED FIRST, then the three conveniences are applied to the escaped
     * text, so the only tags that can come out are the ones made here. A link
     * to a route that does not exist renders as its plain text instead of
     * taking the page down: a typo in a guide is not worth a 500.
     */
    public static function html(string $text): string
    {
        $text = e($text);

        $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text);
        $text = preg_replace('/`(.+?)`/u', '<code>$1</code>', $text);

        return (string) preg_replace_callback('/\[\[([a-z0-9._:-]+)\|(.+?)\]\]/u', function (array $m) {
            [$target, $label] = [$m[1], $m[2]];

            try {
                $href = str_starts_with($target, 'guide:')
                    ? route('guide', substr($target, 6))
                    : route($target);
            } catch (\Throwable) {
                return $label;
            }

            return '<a href="'.e($href).'" wire:navigate>'.$label.'</a>';
        }, $text);
    }

    /** „21 ноември 2026" - not translatedFormat(), which depends on the locale being installed. */
    public static function dateBg(string $iso): string
    {
        $months = ['', 'януари', 'февруари', 'март', 'април', 'май', 'юни', 'юли', 'август',
                   'септември', 'октомври', 'ноември', 'декември'];

        $d = Carbon::parse($iso);

        return $d->day.' '.$months[$d->month].' '.$d->year;
    }

    /**
     * Article and breadcrumb data, already encoded for the layout's
     * `$jsonLd`. JSON_HEX_TAG so nothing in the text can close the script tag.
     *
     * Deliberately NOT FAQPage or HowTo: those are rich-result types Google
     * has narrowed, and claiming one for a page that is not a Q&A is the kind
     * of mismatch that earns a manual action.
     *
     * @param  array<string, mixed>  $guide
     */
    public static function jsonLd(string $slug, array $guide): string
    {
        $url = route('guide', $slug);

        return (string) json_encode([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type'            => 'Article',
                    'headline'         => $guide['title'],
                    'description'      => $guide['description'],
                    'inLanguage'       => 'bg',
                    'datePublished'    => $guide['updated'],
                    'dateModified'     => $guide['updated'],
                    'mainEntityOfPage' => $url,
                    'author'           => ['@type' => 'Organization', 'name' => config('app.name')],
                    'publisher'        => ['@type' => 'Organization', 'name' => config('app.name')],
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Ръководства', 'item' => route('guides')],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => $guide['title'], 'item' => $url],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }
}
