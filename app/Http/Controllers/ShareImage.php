<?php

namespace App\Http\Controllers;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Part;
use App\Support\ShareCard;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Serves the share pictures. See App\Support\ShareCard for what they are and
 * why; this class only decides what goes on each one.
 *
 * THREE ROUTES, ONE RULE: a picture is served only for a page that is itself
 * public. A listing the moderators removed, a draft, or one awaiting review has
 * no share picture, because the picture would carry its title and price to
 * anybody who guessed the address.
 *
 * Rendered once and kept on disk. GD at this size takes tens of milliseconds,
 * which is nothing for one request and a great deal for a link pasted into a
 * busy group chat, where a dozen preview bots fetch it within the same second.
 * The cache key is a hash of everything that goes on the card, so an edited
 * title or a changed price is a different file rather than a stale one.
 *
 * The page addresses carry `?v=`, derived the same way, because Viber,
 * Telegram and Facebook cache a preview by address for days: a changed price
 * has to be a new address or the old number keeps circulating.
 */
class ShareImage
{
    /** Same list ShowListing uses for its tombstone: gone, but still a page. */
    private const GONE = [ListingStatus::Sold, ListingStatus::Expired];

    /**
     * Whether this server can draw one. False when GD has no FreeType or the
     * bundled fonts are missing - in which case pages simply do not declare a
     * picture of their own, instead of declaring one that 404s.
     */
    public static function available(): bool
    {
        static $ok = null;

        return $ok ??= self::card()->fontsAvailable();
    }

    public static function listingUrl(Listing $listing): string
    {
        return route('listing.share', ['listing' => $listing, 'v' => self::version(self::listingCard($listing))]);
    }

    public static function partUrl(Part $part): string
    {
        return route('part.share', ['part' => $part, 'v' => self::version(self::partCard($part))]);
    }

    public static function siteUrl(): string
    {
        return route('share.site', ['v' => self::version(self::siteCard())]);
    }

    // --- actions -------------------------------------------------------------

    public function listing(Listing $listing): Response
    {
        abort_unless(
            $listing->status->isPubliclyVisible() || in_array($listing->status, self::GONE, true),
            404,
        );

        return $this->respond(self::listingCard($listing->loadMissing(['city', 'images'])));
    }

    public function part(Part $part): Response
    {
        abort_unless($part->is_published, 404);

        return $this->respond(self::partCard($part));
    }

    public function site(): Response
    {
        return $this->respond(self::siteCard());
    }

    // --- what goes on each card ----------------------------------------------

    /** @return array<string, mixed> */
    private static function listingCard(Listing $listing): array
    {
        $gone = in_array($listing->status, self::GONE, true);

        $photo = $listing->images->first();

        return [
            'brand'   => (string) config('app.name'),
            'eyebrow' => implode(' · ', array_filter([
                $gone ? $listing->status->label() : null,
                $listing->condition?->label(),
                $listing->city?->name(),
            ])),
            'title'   => (string) $listing->title,
            'price'   => $listing->formattedPrice(),
            'dim'     => $gone,
            'photo'   => $photo ? Storage::disk('public')->path($photo->path) : null,
            'domain'  => self::domain(),
        ];
    }

    /** @return array<string, mixed> */
    private static function partCard(Part $part): array
    {
        $band  = $part->priceBand();
        $count = $part->listings()->where('status', ListingStatus::Active)->count();

        return [
            'brand'   => (string) config('app.name'),
            // The band's median is what the page itself leads with, so the
            // card says the same number rather than a lowest or an average.
            'eyebrow' => $band
                ? 'Средна цена втора употреба'.($count > 0 ? ' · '.$count.' '.($count === 1 ? 'обява' : 'обяви') : '')
                : 'Спецификации и цени втора употреба',
            'title'   => $part->fullName(),
            'price'   => $band ? number_format($band['median'] / 100, 0, ',', ' ').' €' : null,
            'dim'     => false,
            'photo'   => null,
            'domain'  => self::domain(),
        ];
    }

    /** @return array<string, mixed> */
    private static function siteCard(): array
    {
        return [
            'brand'   => (string) config('app.name'),
            'eyebrow' => 'Пазар за втора употреба · България',
            'title'   => 'Купи и продай хардуер втора употреба',
            'price'   => null,
            'dim'     => false,
            'photo'   => null,
            'domain'  => self::domain(),
        ];
    }

    // --- plumbing ------------------------------------------------------------

    private static function card(): ShareCard
    {
        return new ShareCard(resource_path('fonts/share'));
    }

    private static function domain(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: '');
    }

    /**
     * A short fingerprint of everything on the card, including the photo's
     * identity - a replaced cover photo must change the picture.
     *
     * @param  array<string, mixed>  $card
     */
    private static function version(array $card): string
    {
        $photo = $card['photo'] ?? null;

        $card['photo'] = $photo && is_file($photo) ? $photo.'@'.filemtime($photo) : null;

        return substr(sha1(json_encode($card, JSON_UNESCAPED_UNICODE)), 0, 10);
    }

    /** @param array<string, mixed> $card */
    private function respond(array $card): Response
    {
        abort_unless(self::available(), 404);

        $key  = self::version($card);
        $dir  = storage_path('app/share-cache');
        $file = $dir.DIRECTORY_SEPARATOR.$key.'.jpg';

        if (! is_file($file)) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $bytes = self::card()->render($card);

            // Written beside, then moved into place: two preview bots arriving
            // together must never read a half-written file.
            $tmp = $file.'.'.getmypid().'.tmp';
            file_put_contents($tmp, $bytes);
            @rename($tmp, $file);
        }

        return response(file_get_contents($file), 200, [
            'Content-Type'  => 'image/jpeg',
            // The address changes whenever the picture does, so it can be
            // cached hard; a day keeps it from being forever if a bot ignores v.
            'Cache-Control' => 'public, max-age=86400',
            'ETag'          => '"'.$key.'"',
        ]);
    }
}
