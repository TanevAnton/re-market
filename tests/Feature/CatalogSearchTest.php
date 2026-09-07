<?php

namespace Tests\Feature;

use App\Models\Part;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Bulgarians type in two alphabets. An earlier version of the alias generator
 * produced "джифорс ртх 4090" - a faithful transliteration of the full
 * marketing name and a string no human will ever enter - while missing
 * "ртх 4090" entirely. Search looked fine and found nothing.
 */
class CatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PartSeeder::class);
    }

    public static function aliasProvider(): array
    {
        return [
            'latin, full'         => ['geforce rtx 4090', 'GeForce RTX 4090'],
            'latin, no prefix'    => ['rtx 4090',         'GeForce RTX 4090'],
            'latin, run together' => ['rtx4090',          'GeForce RTX 4090'],
            'bare number'         => ['4090',             'GeForce RTX 4090'],
            'cyrillic'            => ['ртх 4090',         'GeForce RTX 4090'],
            'cyrillic, joined'    => ['ртх4090',          'GeForce RTX 4090'],
            'amd cyrillic'        => ['рх 7800 хт',       'Radeon RX 7800 XT'],
            'amd shlyokavitsa'    => ['7800хт',           'Radeon RX 7800 XT'],
            'ryzen'               => ['7800x3d',          'Ryzen 7 7800X3D'],
            'intel dashed'        => ['i5-13600k',        'Core i5-13600K'],
        ];
    }

    #[DataProvider('aliasProvider')]
    public function test_alias_lookup_finds_the_right_part(string $term, string $expected): void
    {
        $part = Part::matchingAlias($term)->first();

        $this->assertNotNull($part, "no part matched \"{$term}\"");
        $this->assertSame($expected, $part->model);
    }

    public static function homoglyphProvider(): array
    {
        return [
            'gpu, both zeros'  => ['RTX 4O7O',       'GeForce RTX 4070'],
            'gpu, one zero'    => ['rtx 4o70',       'GeForce RTX 4070'],
            'amd'              => ['78o0 xt',        'Radeon RX 7800 XT'],
            'ryzen'            => ['78oox3d',        'Ryzen 7 7800X3D'],
            'cyrillic'         => ['ртх 4о9о',       'GeForce RTX 4090'],
        ];
    }

    /**
     * People type the letter o where a zero belongs. Measured against real
     * data, trigram similarity scores "rtx 4o7o" at 0.238 - below the 0.3
     * threshold - and scores the 4070 and the 4090 IDENTICALLY, so no amount
     * of threshold tuning picks the right one. Normalising o to 0 first turns
     * it into an exact alias hit.
     */
    #[DataProvider('homoglyphProvider')]
    public function test_letter_o_typed_for_zero_still_finds_the_part(string $term, string $expected): void
    {
        $part = Part::search($term)->first();

        $this->assertNotNull($part, "nothing matched \"{$term}\"");
        $this->assertSame($expected, $part->model);
    }

    public function test_the_normalizer_leaves_words_without_digits_alone(): void
    {
        // Mapping o->0 everywhere would turn "econt" into "ec0nt".
        $this->assertSame('geforce rtx', Part::normalizeQuery('GeForce RTX'));
        $this->assertSame('rtx 4070', Part::normalizeQuery('rtx 4o7o'));

        // l->1 would break "i5-13600k" into "15-13600k"; we deliberately
        // normalise only the letter o.
        $this->assertSame('i5-13600k', Part::normalizeQuery('i5-136o0k'));
    }

    public function test_search_ranks_an_exact_alias_above_a_fuzzy_match(): void
    {
        $first = Part::search('4090')->first();

        $this->assertSame('GeForce RTX 4090', $first->model);
    }

    public function test_spec_filtering_excludes_cards_that_do_not_fit(): void
    {
        $models = Part::where('category', 'gpu')
            ->whereRaw("(specs->>'length_mm')::int <= 300")
            ->whereRaw("(specs->>'vram_gb')::int >= 16")
            ->pluck('model');

        $this->assertContains('Radeon RX 7800 XT', $models->all());

        // 4090 is 304 mm at reference - it must not survive a 300 mm filter.
        $this->assertNotContains('GeForce RTX 4090', $models->all());
    }
}
