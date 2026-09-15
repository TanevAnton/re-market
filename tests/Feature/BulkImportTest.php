<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Services\Listings\ListingImporter;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bulk import — twenty-five evenings of wizard, saved.
 *
 * Supply is the only thing standing between this site and being useful, so the
 * cost of getting the first few hundred listings on is the cost of the whole
 * project starting. The wizard is the right tool for one listing and the wrong
 * one for three hundred.
 *
 * Most of what is tested below is the importer refusing to do something: no
 * fuzzy catalogue matching, no publishing a listing with no photograph, no
 * silently ignored column, no second copy on a re-run. An import runs
 * unattended over hundreds of rows, and every one of those failures is
 * invisible on the resulting pages — which is exactly why they have to be
 * caught here.
 */
class BulkImportTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(CitySeeder::class);

        $this->seller = User::factory()->create([
            'username'          => 're-tech',
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        $this->dir = storage_path('framework/testing/import-'.uniqid());
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    // --- fixtures ---------------------------------------------------------

    /**
     * A real JPEG, and a DIFFERENT one per filename.
     *
     * The importer runs every photo through ImageProcessor, which decodes,
     * re-encodes and perceptually hashes it, and then through ListingScreener,
     * which compares that hash against every other listing on the site. A flat
     * 2x2 image hashes to zero whatever colour it is - the difference hash
     * compares each pixel with its neighbour - so a dozen of them read as the
     * same stolen photograph and every listing after the first lands in
     * moderation. Blocks seeded from the name give each one its own hash.
     */
    private function photo(string $name = 'a.jpg'): string
    {
        $image = imagecreatetruecolor(64, 64);
        $seed  = crc32($name);

        for ($x = 0; $x < 64; $x += 8) {
            for ($y = 0; $y < 64; $y += 8) {
                $v     = ($seed >> ((($x + $y) / 8) % 24)) & 0xFF;
                $color = imagecolorallocate($image, $v, ($v * 7) % 256, ($v * 13) % 256);
                imagefilledrectangle($image, $x, $y, $x + 7, $y + 7, $color);
            }
        }

        imagejpeg($image, $this->dir.'/'.$name, 95);
        imagedestroy($image);

        return $name;
    }

    /** @param  list<array<string, string>>  $rows */
    private function csv(array $rows, array $extraColumns = []): string
    {
        $columns = array_merge([
            'ref', 'category', 'title', 'description', 'price',
            'condition', 'model', 'city', 'images',
        ], $extraColumns);

        /*
         * Quoted the way a real CSV writer quotes, which these fixtures did not
         * do at first and which hid a bug in the fixture rather than in the
         * importer. The default price is „629,00" — a Bulgarian decimal comma,
         * and the reason Excel picks `;` as the list separator here in the
         * first place. Written bare, it survives a semicolon-delimited file and
         * then splits into two cells the moment a test rewrites that file as
         * comma-delimited, shifting every column after it by one.
         *
         * Quoting on either separator also means `str_replace(';', ',')` is a
         * safe way to produce the comma-delimited variant.
         */
        $quote = fn (string $c): string => preg_match('/[;,"\r\n]/', $c)
            ? '"'.str_replace('"', '""', $c).'"'
            : $c;

        $lines = [implode(';', $columns)];

        foreach ($rows as $row) {
            $lines[] = implode(';', array_map(fn ($c) => $quote((string) ($row[$c] ?? '')), $columns));
        }

        $path = $this->dir.'/stock.csv';
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    /** @return array<string, string> */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'ref'         => 'GPU-0041',
            'category'    => 'gpu',
            'title'       => 'ASUS TUF RTX 4070 OC 12GB, пълен комплект',
            'description' => 'Картата е работила в офис машина и е тествана под натоварване преди снимките.',
            'price'       => '629,00',
            'condition'   => 'like_new',
            'model'       => '',
            'city'        => 'Горна Оряховица',
            'images'      => $this->photo(),
        ], $overrides);
    }

    private function import(string $csv, bool $dryRun = false, bool $update = false): array
    {
        return app(ListingImporter::class)->import($csv, $this->seller, $this->dir, $dryRun, $update);
    }

    private function part(string $model = 'GeForce RTX 4070'): Part
    {
        return Part::create([
            'category'     => 'gpu',
            'manufacturer' => 'NVIDIA',
            'model'        => $model,
            'slug'         => \Illuminate\Support\Str::slug('nvidia '.$model),
            'specs'        => ['vram_gb' => 12],
            'aliases'      => ['4070'],
            'is_published' => true,
        ]);
    }

    // --- the happy path ---------------------------------------------------

    public function test_a_row_becomes_a_published_listing_with_its_photo(): void
    {
        $report = $this->import($this->csv([$this->row()]));

        $this->assertSame(1, $report['created'], json_encode($report['rows'], JSON_UNESCAPED_UNICODE));

        $listing = Listing::firstOrFail();

        $this->assertSame($this->seller->id, $listing->user_id);
        $this->assertSame('GPU-0041', $listing->external_ref);
        $this->assertSame(62900, $listing->price_cents);
        $this->assertCount(1, $listing->images);
    }

    /** „1 299,50" and "1299.50" are the same price typed by different tools. */
    public function test_prices_arrive_in_whatever_the_spreadsheet_wrote(): void
    {
        $this->import($this->csv([
            $this->row(['ref' => 'A', 'price' => '1 299,50', 'images' => $this->photo('a1.jpg')]),
            $this->row(['ref' => 'B', 'price' => '1299.50', 'images' => $this->photo('b1.jpg')]),
        ]));

        $this->assertSame([129950, 129950], Listing::orderBy('external_ref')->pluck('price_cents')->all());
    }

    /**
     * Excel on a Bulgarian Windows machine writes a UTF-8 BOM and semicolons.
     * The BOM otherwise becomes part of the first header's NAME, so `ref`
     * silently does not exist and every row fails for a missing column that is
     * plainly there in the file.
     */
    public function test_a_file_straight_out_of_excel_imports(): void
    {
        $csv = $this->csv([$this->row()]);
        file_put_contents($csv, "\u{FEFF}".file_get_contents($csv));

        $this->assertSame(1, $this->import($csv)['created']);
    }

    /**
     * The default row is deliberately not softened for this: its title contains
     * a comma and its price IS a comma („629,00"), so a file that imports here
     * proves quoted cells survive a comma-delimited file rather than proving
     * the sniffer works on a row with nothing in it to trip over.
     */
    public function test_a_comma_separated_file_imports_too(): void
    {
        $csv = $this->csv([$this->row()]);

        file_put_contents($csv, str_replace(';', ',', file_get_contents($csv)));

        $report = $this->import($csv);

        $this->assertSame(1, $report['created'], json_encode($report['rows'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(62900, Listing::sole()->price_cents);
    }

    // --- catalogue matching -----------------------------------------------

    public function test_an_exact_model_name_attaches_to_the_catalogue(): void
    {
        $part = $this->part();

        $this->import($this->csv([$this->row(['model' => 'GeForce RTX 4070'])]));

        $this->assertSame($part->id, Listing::firstOrFail()->part_id);
    }

    public function test_an_alias_attaches_too(): void
    {
        $part = $this->part();

        $this->import($this->csv([$this->row(['model' => '4070'])]));

        $this->assertSame($part->id, Listing::firstOrFail()->part_id);
    }

    /**
     * The decision this whole feature turns on.
     *
     * The search box may guess, because a person reads the result and notices.
     * Nothing reads an import: a fuzzy match attaches three hundred cards to
     * the wrong model page — wrong price band, wrong specifications — and no
     * page looks wrong afterwards.
     */
    public function test_a_near_miss_is_not_guessed_at(): void
    {
        $this->part('GeForce RTX 4070');

        $this->import($this->csv([$this->row(['model' => 'RTX 4070 Ti Super'])]));

        $listing = Listing::firstOrFail();

        $this->assertNull($listing->part_id, 'a near miss was fuzzy-matched to the wrong model');
        $this->assertSame('RTX 4070 Ti Super', $listing->custom_part);
    }

    /** An unmatched string is not an error — it is the promotion queue's input. */
    public function test_an_unknown_model_lands_in_the_promotion_queue(): void
    {
        $this->import($this->csv([$this->row(['model' => 'ASUS TUF RTX 4070 OC'])]));

        $this->assertSame(1, Listing::awaitingCatalogue()->count());
    }

    /** A listing that found its model does not also need the seller's spelling. */
    public function test_a_matched_listing_does_not_stay_in_the_queue(): void
    {
        $this->part();

        $this->import($this->csv([$this->row(['model' => '4070'])]));

        $this->assertSame(0, Listing::awaitingCatalogue()->count());
    }

    // --- what it refuses --------------------------------------------------

    /**
     * A listing with no photograph does not sell, and a hundred of them make
     * the site look like a scrape rather than a shop.
     */
    public function test_a_row_with_no_photo_fails_rather_than_publishing(): void
    {
        $report = $this->import($this->csv([$this->row(['images' => ''])]));

        $this->assertSame(1, $report['failed']);
        $this->assertSame(0, Listing::count());
        $this->assertStringContainsString('снимк', $report['rows'][0]['message']);
    }

    public function test_a_named_photo_that_is_not_there_fails_the_row(): void
    {
        $report = $this->import($this->csv([$this->row(['images' => 'nope.jpg'])]));

        $this->assertSame(1, $report['failed']);
    }

    /** A spreadsheet cell is not a path. */
    public function test_a_row_cannot_reach_outside_the_photo_folder(): void
    {
        $report = $this->import($this->csv([$this->row(['images' => '../../../.env'])]));

        $this->assertSame(1, $report['failed']);
        $this->assertSame(0, Listing::count());
    }

    public function test_an_unknown_category_fails_the_row(): void
    {
        $report = $this->import($this->csv([$this->row(['category' => 'gpus'])]));

        $this->assertSame(1, $report['failed']);
        $this->assertStringContainsString('категория', $report['rows'][0]['message']);
    }

    /**
     * A silently ignored column is a spreadsheet the operator believes is being
     * imported and a facet that never matches anything.
     */
    public function test_an_unknown_spec_column_fails_the_row(): void
    {
        $report = $this->import($this->csv(
            [$this->row() + ['spec:vram' => '12']],
            ['spec:vram'],
        ));

        $this->assertSame(1, $report['failed']);
        $this->assertStringContainsString('vram', $report['rows'][0]['message']);
    }

    /** A spec of the MODEL is not a fact about this particular unit. */
    public function test_a_part_scoped_spec_is_refused(): void
    {
        $report = $this->import($this->csv(
            [$this->row() + ['spec:vram_gb' => '12']],
            ['spec:vram_gb'],
        ));

        $this->assertSame(1, $report['failed']);
    }

    public function test_a_listing_scoped_spec_is_kept(): void
    {
        $this->import($this->csv(
            [$this->row() + ['spec:backplate' => 'да']],
            ['spec:backplate'],
        ));

        $this->assertSame(['backplate' => true], Listing::firstOrFail()->specs);
    }

    /**
     * One bad row must not take the run down. Two hundred rows will not all be
     * right the first time, and an importer that aborts on row 140 leaves the
     * operator with 139 listings and no report.
     */
    public function test_one_bad_row_does_not_stop_the_others(): void
    {
        $report = $this->import($this->csv([
            $this->row(['ref' => 'GOOD-1', 'images' => $this->photo('g1.jpg')]),
            $this->row(['ref' => 'BAD-1', 'category' => 'nonsense']),
            $this->row(['ref' => 'GOOD-2', 'images' => $this->photo('g2.jpg')]),
        ]));

        $this->assertSame(2, $report['created']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(['GOOD-1', 'GOOD-2'], Listing::orderBy('external_ref')->pluck('external_ref')->all());
    }

    // --- running it again -------------------------------------------------

    /**
     * The reason external_ref exists. The natural response to a bad report is
     * to fix the file and run it again; without a stable key that second run
     * creates a second copy of everything that worked.
     */
    public function test_a_second_run_creates_only_what_was_missing(): void
    {
        $csv = $this->csv([
            $this->row(['ref' => 'A', 'images' => $this->photo('a1.jpg')]),
            $this->row(['ref' => 'B', 'category' => 'nonsense']),
        ]);

        $this->import($csv);

        // The operator fixes row B and runs the same file again.
        $fixed = $this->csv([
            $this->row(['ref' => 'A', 'images' => $this->photo('a1.jpg')]),
            $this->row(['ref' => 'B', 'images' => $this->photo('b1.jpg')]),
        ]);

        $report = $this->import($fixed);

        $this->assertSame(1, $report['created']);
        $this->assertSame(1, $report['skipped']);
        $this->assertSame(2, Listing::count());
    }

    public function test_update_corrects_an_existing_row(): void
    {
        $csv = $this->csv([$this->row(['price' => '629,00'])]);
        $this->import($csv);

        $cheaper = $this->csv([$this->row(['price' => '549,00'])]);
        $report  = $this->import($cheaper, update: true);

        $this->assertSame(1, $report['updated']);
        $this->assertSame(54900, Listing::firstOrFail()->price_cents);
        $this->assertSame(1, Listing::count());
    }

    /**
     * Two different shops can both call something „GPU-0041" and neither is
     * wrong; the reference means something only inside the account that issued
     * it.
     */
    public function test_another_seller_may_use_the_same_reference(): void
    {
        $this->import($this->csv([$this->row()]));

        $other = User::factory()->create(['email_verified_at' => now(), 'city_id' => City::first()->id]);

        app(ListingImporter::class)
            ->import($this->csv([$this->row()]), $other, $this->dir);

        $this->assertSame(2, Listing::where('external_ref', 'GPU-0041')->count());
    }

    // --- the dry run ------------------------------------------------------

    /**
     * How a misspelt category gets found before two hundred listings exist
     * rather than after.
     */
    public function test_a_dry_run_validates_everything_and_writes_nothing(): void
    {
        $report = $this->import($this->csv([
            $this->row(['ref' => 'A', 'images' => $this->photo('a1.jpg')]),
            $this->row(['ref' => 'B', 'category' => 'nonsense']),
        ]), dryRun: true);

        $this->assertSame(1, $report['created']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(0, Listing::count());
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    // --- what it does not skip --------------------------------------------

    /**
     * An importer that waved its listings past the new-account gate would be
     * the one route onto the site that bypasses the best anti-spam measure
     * there is — and a bulk-import account is precisely what an attacker wants.
     */
    public function test_a_fresh_account_still_goes_through_moderation(): void
    {
        config(['remarket.antispam.moderated_listings_for_new_accounts' => 2]);

        // The duplicate-photo screen is neutralised for this test only, so that
        // what is being measured is the account gate rather than how similar
        // three generated JPEGs happen to be. The screen has its own test.
        config(['remarket.antispam.phash_distance' => -1]);

        $this->import($this->csv([
            $this->row(['ref' => 'A', 'images' => $this->photo('a1.jpg')]),
            $this->row(['ref' => 'B', 'images' => $this->photo('b1.jpg')]),
            $this->row(['ref' => 'C', 'images' => $this->photo('c1.jpg')]),
        ]));

        $held = Listing::where('status', ListingStatus::PendingReview)->count();

        $this->assertSame(2, $held, 'the new-account gate was bypassed by the importer');
        $this->assertSame(1, Listing::where('status', ListingStatus::Active)->count());
    }

    /**
     * The other half of that promise. Reposting another seller's photographs is
     * the most common scam on any hardware marketplace, and an import route
     * that skipped the check would be the obvious way in.
     */
    public function test_a_stolen_photograph_is_still_caught_on_import(): void
    {
        // Past the account gate, so the status change can only be the screener.
        config(['remarket.antispam.moderated_listings_for_new_accounts' => 0]);

        $shared = $this->photo('shared.jpg');

        $this->import($this->csv([
            $this->row(['ref' => 'A', 'images' => $shared]),
            $this->row(['ref' => 'B', 'images' => $shared]),
        ]));

        $this->assertSame(
            1,
            Listing::where('status', ListingStatus::PendingReview)->count(),
            'the same photograph on two listings went unnoticed by a bulk import',
        );
    }
}
