<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Listings\ListingImporter;
use Illuminate\Console\Command;

/**
 * Bulk import, for getting real stock onto the site in an evening instead of
 * twenty-five.
 *
 * A COMMAND RATHER THAN A SCREEN, deliberately. The photographs have to be on
 * disk next to the file for a three-hundred-row import to be practical, this is
 * run once or twice by the person with server access rather than daily by a
 * moderator, and a browser upload that dies at row 210 leaves nothing to resume
 * from. It is also the only shape of this feature that is properly testable.
 *
 * ALWAYS DRY-RUN FIRST. `--dry-run` reads and validates every row and writes
 * nothing, which is how a misspelt category or a missing photograph gets found
 * before two hundred listings exist rather than after.
 */
class ImportListings extends Command
{
    protected $signature = 'remarket:import-listings
        {file : CSV file, semicolon or comma separated}
        {--user= : username or email of the seller these listings belong to}
        {--photos= : folder the `images` column resolves against (default: next to the CSV)}
        {--dry-run : validate and report, write nothing}
        {--update : correct rows whose `ref` already exists instead of skipping them}
        {--force : skip the confirmation, for terminals where a prompt cannot run}';

    protected $description = 'Import listings for one seller from a CSV of stock';

    public function handle(ListingImporter $importer): int
    {
        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("Няма такъв файл: {$file}");

            return self::FAILURE;
        }

        $seller = $this->seller();

        if (! $seller) {
            return self::FAILURE;
        }

        $photos = $this->option('photos') ?: dirname((string) realpath($file));
        $dryRun = (bool) $this->option('dry-run');

        /*
         * Said out loud before anything happens. This writes listings that the
         * public will see, under somebody's name, and the two ways to get it
         * wrong - the wrong account, or forgetting the dry run - are both
         * silent until the damage is done.
         */
        $this->line('');
        $this->line("  Продавач : <options=bold>{$seller->username}</> ({$seller->email})");
        $this->line("  Файл     : {$file}");
        $this->line("  Снимки   : {$photos}");
        $this->line('  Режим    : '.($dryRun ? '<comment>пробен, нищо не се записва</comment>' : '<options=bold>запис</>'));
        $this->line('');

        /*
         * The confirmation is the point of this command's safety, so --force
         * is deliberately not a convenience: it exists because a prompt is not
         * always POSSIBLE.
         *
         * Symfony saves and restores the terminal with `stty -g` around a
         * question, and on a shell whose stty rejects that string the prompt
         * dies with „invalid argument" before it can ask anything. Without an
         * escape hatch the command is simply unrunnable there — and the
         * alternative people reach for, --no-interaction, is worse: it answers
         * this question with its default, which is `false`, so the import
         * silently does nothing and reports success.
         *
         * The report above still prints either way, so --force skips the
         * question and not the summary of what is about to happen.
         */
        if (! $dryRun && ! $this->option('force')
            && ! $this->confirm('Импортиране в базата. Продължаваш ли?', false)) {
            $this->line('Отказано.');

            return self::SUCCESS;
        }

        $report = $importer->import($file, $seller, $photos, $dryRun, (bool) $this->option('update'));

        $this->render($report);

        // A non-zero exit on any failed row, so a scripted run does not report
        // success while a quarter of the stock is missing.
        return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function seller(): ?User
    {
        $handle = (string) $this->option('user');

        if ($handle === '') {
            $this->error('Кой продава? Подай --user=<потребител или имейл>.');

            return null;
        }

        $seller = User::where('username', $handle)->orWhere('email', $handle)->first();

        if (! $seller) {
            $this->error("Няма потребител „{$handle}\u{201C}.");

            return null;
        }

        /*
         * An unverified account cannot publish through the wizard, and an
         * import is not the way around that. `verified` middleware guards
         * /publikuvai for exactly this reason.
         */
        if (! $seller->hasVerifiedEmail()) {
            $this->error("Профилът на {$seller->username} няма потвърден имейл.");

            return null;
        }

        return $seller;
    }

    /** @param  array<string, mixed>  $report */
    private function render(array $report): void
    {
        foreach ($report['rows'] as $row) {
            $tag = match ($row['status']) {
                'created' => '<info>+</info>',
                'updated' => '<comment>~</comment>',
                'skipped' => '<fg=gray>·</>',
                default   => '<error>✗</error>',
            };

            $this->line(sprintf(
                '  %s ред %-4d %-14s %s',
                $tag, $row['line'], $row['ref'] !== '' ? $row['ref'] : '—', $row['message'],
            ));
        }

        $this->line('');
        $this->line(sprintf(
            '  %d нови · %d обновени · %d пропуснати · %d с грешка',
            $report['created'], $report['updated'], $report['skipped'], $report['failed'],
        ));

        if ($report['failed'] > 0) {
            $this->line('');
            $this->warn('  Редовете с грешка НЕ са импортирани. Поправи ги и пусни файла пак — '
                .'вече импортираните се пропускат по `ref`.');
        }
    }
}
