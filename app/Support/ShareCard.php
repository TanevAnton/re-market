<?php

namespace App\Support;

/**
 * The picture that appears when a link to this site is pasted into Viber,
 * Messenger, Telegram or a Facebook group - which is how a Bulgarian
 * marketplace actually spreads.
 *
 * WHY NOT JUST THE SELLER'S PHOTO. That is what the page used to offer, and it
 * is a photograph of a graphics card on a desk: no price, no name, no sign of
 * where it came from, and a link with no photo at all got no picture. A card
 * that carries the title and the price answers the only two questions a person
 * scrolling a group chat has - what is it, and how much - before they decide to
 * tap, and it is the thing that makes the link look like it belongs to
 * somebody.
 *
 * PURE: nothing here knows about Laravel, the database or the request. It takes
 * a plain array and returns JPEG bytes, which is what makes it testable with a
 * font directory and nothing else. Resolving a listing into that array is the
 * controller's job.
 *
 * GD with FreeType, which Intervention's driver already needs, so there is no
 * new dependency. Fonts are bundled with the app (resources/fonts/share) rather
 * than found on the machine: a server that happens to have no Cyrillic face
 * would otherwise render every Bulgarian title as empty boxes, and nobody would
 * see it until somebody pasted a link.
 */
final class ShareCard
{
    public const WIDTH  = 1200;
    public const HEIGHT = 630;

    /** Width of the photo panel when there is a photo. */
    private const PHOTO_W = 520;

    private const PAD = 64;

    /** [r, g, b] - the site's dark theme, so the card looks like the site. */
    private const BG     = [10, 13, 12];
    private const PANEL  = [19, 24, 21];
    private const INK    = [242, 245, 243];
    private const MUTED  = [154, 168, 161];
    private const ACCENT = [52, 211, 153];

    public function __construct(
        private readonly string $fontDir,
    ) {}

    public function fontsAvailable(): bool
    {
        return function_exists('imagettftext')
            && is_file($this->bold())
            && is_file($this->medium());
    }

    /**
     * @param array{
     *     brand?: string, eyebrow?: ?string, title: string, price?: ?string,
     *     dim?: bool, photo?: ?string, domain?: ?string
     * } $card `photo` is a path on disk, not a URL.
     *
     * @return string JPEG bytes
     */
    public function render(array $card): string
    {
        foreach (['brand', 'eyebrow', 'title', 'price', 'domain'] as $key) {
            if (isset($card[$key])) {
                $card[$key] = self::clean((string) $card[$key]);
            }
        }

        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, $this->color($img, self::BG));

        $photo   = $this->loadPhoto($card['photo'] ?? null);
        $textRight = self::WIDTH - self::PAD;

        if ($photo) {
            $this->drawPhoto($img, $photo);
            $textRight = self::WIDTH - self::PHOTO_W - 48;
        } else {
            // No photo: a quiet panel on the right so the card is not a
            // text-only slab. It carries no information, which is the point.
            imagefilledrectangle($img, self::WIDTH - 300, 0, self::WIDTH, self::HEIGHT, $this->color($img, self::PANEL));
            imagefilledrectangle($img, self::WIDTH - 304, 0, self::WIDTH - 300, self::HEIGHT, $this->color($img, self::ACCENT));
            $textRight = self::WIDTH - 300 - 48;
        }

        $maxW = $textRight - self::PAD;

        $this->drawBrand($img, (string) ($card['brand'] ?? ''));

        $y = 190;

        if (self::has($card['eyebrow'] ?? null)) {
            $eyebrow = $this->fit((string) $card['eyebrow'], 25.0, $this->medium(), $maxW);
            $this->text($img, $eyebrow, 25.0, self::PAD, $y, self::MUTED, $this->medium());
            $y += 22;
        }

        // Title: the biggest size that fits; if none does, the smallest, cut
        // with an ellipsis rather than shrunk to unreadable.
        $lines = [];
        $size  = 40.0;

        foreach ([[60.0, 3], [52.0, 3], [46.0, 4], [40.0, 4]] as [$try, $max]) {
            $size  = $try;
            $lines = $this->wrap((string) $card['title'], $try, $this->bold(), $maxW, $max);

            if (! $lines['truncated']) {
                break;
            }
        }

        $lineH = (int) round($size * 1.18);
        $y    += (int) round($size * 1.05);

        foreach ($lines['lines'] as $line) {
            $this->text($img, $line, $size, self::PAD, $y, self::INK, $this->bold());
            $y += $lineH;
        }

        if (self::has($card['price'] ?? null)) {
            // Dimmed for a listing that is no longer for sale: the number is
            // still the answer to „колко", but it must not read as an offer.
            // Shrunk to fit rather than allowed to run into the photo: a
            // four-figure price is the case that overflows, and it is the one
            // that matters most.
            $priceSize = 86.0;

            while ($priceSize > 48.0 && $this->width((string) $card['price'], $priceSize, $this->bold()) > $maxW) {
                $priceSize -= 4.0;
            }

            $this->text($img, (string) $card['price'], $priceSize, self::PAD, 540,
                ! empty($card['dim']) ? self::MUTED : self::ACCENT, $this->bold());
        }

        if (self::has($card['domain'] ?? null)) {
            $this->text($img, (string) $card['domain'], 24.0, self::PAD, 596, self::MUTED, $this->medium());
        }

        ob_start();
        imagejpeg($img, null, 86);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    // --- pieces --------------------------------------------------------------

    private function drawBrand(\GdImage $img, string $brand): void
    {
        $accent = $this->color($img, self::ACCENT);

        // A solid mark rather than the logo file: the card must render with
        // nothing but fonts on disk, and a missing image is a silent failure.
        imagefilledrectangle($img, self::PAD, 64, self::PAD + 44, 64 + 44, $accent);
        $this->text($img, 'R', 30.0, self::PAD + 12, 64 + 34, self::BG, $this->bold());

        if ($brand !== '') {
            $this->text($img, $brand, 32.0, self::PAD + 62, 64 + 34, self::INK, $this->bold());
        }
    }

    private function drawPhoto(\GdImage $img, \GdImage $photo): void
    {
        $sw = imagesx($photo);
        $sh = imagesy($photo);

        // Cover crop: fill the panel, keep the centre, never stretch.
        $scale = max(self::PHOTO_W / $sw, self::HEIGHT / $sh);
        $cw    = (int) round(self::PHOTO_W / $scale);
        $ch    = (int) round(self::HEIGHT / $scale);
        $sx    = (int) round(($sw - $cw) / 2);
        $sy    = (int) round(($sh - $ch) / 2);

        imagecopyresampled($img, $photo, self::WIDTH - self::PHOTO_W, 0, $sx, $sy,
            self::PHOTO_W, self::HEIGHT, $cw, $ch);

        imagefilledrectangle($img, self::WIDTH - self::PHOTO_W - 4, 0, self::WIDTH - self::PHOTO_W - 1,
            self::HEIGHT, $this->color($img, self::ACCENT));
    }

    private function loadPhoto(?string $path): ?\GdImage
    {
        if ($path === null || ! is_file($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);

        // An unreadable or corrupt photo must cost the card its photo, not the
        // listing its share picture.
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);

        return $image instanceof \GdImage ? $image : null;
    }

    // --- text ----------------------------------------------------------------

    /**
     * @return array{lines: list<string>, truncated: bool}
     */
    private function wrap(string $text, float $size, string $font, int $maxW, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $line  = '';

        foreach ($words as $word) {
            // A word wider than the whole line (a long model string, a URL)
            // is broken by character, or it would run off the card.
            while ($this->width($word, $size, $font) > $maxW) {
                $cut = mb_strlen($word);

                while ($cut > 1 && $this->width(mb_substr($word, 0, $cut), $size, $font) > $maxW) {
                    $cut--;
                }

                if ($line !== '') {
                    $lines[] = $line;
                    $line    = '';
                }

                $lines[] = mb_substr($word, 0, $cut);
                $word    = mb_substr($word, $cut);
            }

            $candidate = $line === '' ? $word : $line.' '.$word;

            if ($this->width($candidate, $size, $font) <= $maxW) {
                $line = $candidate;
            } else {
                $lines[] = $line;
                $line    = $word;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        $truncated = count($lines) > $maxLines;

        if ($truncated) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = $this->fit(rtrim($lines[$maxLines - 1], ' .,').'…', $size, $font, $maxW, true);
        }

        return ['lines' => $lines, 'truncated' => $truncated];
    }

    /** Shorten with an ellipsis until it fits. */
    private function fit(string $text, float $size, string $font, int $maxW, bool $alreadyHasEllipsis = false): string
    {
        if ($this->width($text, $size, $font) <= $maxW) {
            return $text;
        }

        $text = $alreadyHasEllipsis ? mb_substr($text, 0, -1) : $text;

        while (mb_strlen($text) > 1 && $this->width($text.'…', $size, $font) > $maxW) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text, ' .,').'…';
    }

    private function width(string $text, float $size, string $font): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box === false ? 0 : (int) ($box[2] - $box[0]);
    }

    private function text(\GdImage $img, string $text, float $size, int $x, int $y, array $rgb, string $font): void
    {
        imagettftext($img, $size, 0, $x, $y, $this->color($img, $rgb), $font, $text);
    }

    private function color(\GdImage $img, array $rgb): int
    {
        return (int) imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    }

    private function bold(): string
    {
        return rtrim($this->fontDir, '/\\').DIRECTORY_SEPARATOR.'Inter-Bold.otf';
    }

    private function medium(): string
    {
        return rtrim($this->fontDir, '/\\').DIRECTORY_SEPARATOR.'Inter-Medium.otf';
    }

    /**
     * Text a bundled font can actually draw.
     *
     * Emoji and pictographs are removed, not replaced: Inter has no glyph for
     * them, and GD would print an empty box in their place - and sellers do put
     * a flame or a rocket in a title. `&#` is defused because GD reads numeric
     * HTML entities out of the string it is given.
     */
    private static function clean(string $text): string
    {
        $text = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', $text) ?? $text;
        $text = str_replace('&#', '& #', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** Non-empty string, without pulling in a framework helper. */
    private static function has(mixed $v): bool
    {
        return is_string($v) && trim($v) !== '';
    }
}
