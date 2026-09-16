<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\User;
use App\Services\Images\ImageProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove everything DemoSeeder made.
 *
 * WHY THIS EXISTS AT ALL. Demo data on a staging box is harmless right up to
 * the moment that box becomes, or is copied into, the real one — and the plan
 * here is precisely that: seed the LAN box now, `pg_dump` it onto a real host
 * when the domain lands. Without a way to remove them, a hundred and ninety
 * invented listings by „demo-plamen" ride along into launch, and the first
 * real visitor sees a marketplace whose stock does not exist.
 *
 * The marker is the `@demo.invalid` email domain, set by the seeder. `.invalid`
 * is reserved by RFC 2606 and can never be a real address, so nothing a genuine
 * user could ever register is matched by this.
 */
class ClearDemoData extends Command
{
    protected $signature = 'remarket:demo-clear {--force : skip the confirmation}';

    protected $description = 'Delete the demo users, listings and photos DemoSeeder created';

    public function handle(ImageProcessor $images): int
    {
        $users = User::where('email', 'like', '%@demo.invalid')->get();

        if ($users->isEmpty()) {
            $this->info('Няма демо данни.');

            return self::SUCCESS;
        }

        $listingIds = Listing::whereIn('user_id', $users->pluck('id'))->pluck('id');
        $photos     = ListingImage::whereIn('listing_id', $listingIds)->get();

        $this->line('');
        $this->line("  Профили : {$users->count()}");
        $this->line("  Обяви   : {$listingIds->count()}");
        $this->line("  Снимки  : {$photos->count()}");
        $this->line('');

        if (! $this->option('force') && ! $this->confirm('Изтриване. Сигурен ли си?', false)) {
            $this->line('Отказано.');

            return self::SUCCESS;
        }

        /*
         * The files first, and OUTSIDE the transaction.
         *
         * Storage is not transactional: a rollback after the unlinks cannot put
         * the bytes back, so the only safe order is the one where a failure
         * leaves orphaned FILES (harmless, and `remarket:doctor` can see them)
         * rather than orphaned ROWS pointing at photographs that are gone —
         * which renders as a broken image on every affected card.
         */
        foreach ($photos as $photo) {
            $images->delete($photo->path, str_replace('.jpg', '_t.jpg', $photo->path));
        }

        DB::transaction(function () use ($users, $listingIds) {
            // Listings are soft-deleted, so forceDelete - a tombstone of a
            // listing that never existed is worse than no row at all.
            Listing::whereIn('id', $listingIds)->forceDelete();

            User::whereIn('id', $users->pluck('id'))->delete();
        });

        $this->info('Изтрито. Пусни remarket:refresh-part-stats, за да се преизчислят диапазоните.');

        return self::SUCCESS;
    }
}
