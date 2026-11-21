<?php

namespace App\Console\Commands;

use App\Support\PartImporter;
use Illuminate\Console\Command;

/**
 * Adds catalogue models from a JSON file of facts.
 *
 *   php artisan parts:import --dry-run     what would happen, nothing written
 *   php artisan parts:import               add them, unpublished
 *   php artisan parts:import --publish     add them and give them a public page
 *
 * Never updates a row that exists, so it is safe to run twice and safe to run
 * after somebody has corrected a part by hand. See PartImporter for the rules.
 */
class ImportParts extends Command
{
    protected $signature = 'parts:import
                            {file=database/data/gpus.json : JSON file, relative to the project root}
                            {--category=gpu : Catalogue category the file belongs to}
                            {--publish : Publish the new rows (default: leave them unpublished)}
                            {--dry-run : Report only, write nothing}';

    protected $description = 'Add catalogue models from a JSON file of facts (never overwrites existing rows)';

    public function handle(): int
    {
        $category = (string) $this->option('category');

        if (config("catalog.categories.{$category}") === null) {
            $this->error("Unknown category \"{$category}\".");

            return self::FAILURE;
        }

        $arg  = (string) $this->argument('file');
        $path = str_starts_with($arg, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $arg) ? $arg : base_path($arg);

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $records = json_decode((string) file_get_contents($path), true);

        if (! is_array($records) || ! array_is_list($records)) {
            $this->error('The file must be a JSON array of records.');

            return self::FAILURE;
        }

        $dry    = (bool) $this->option('dry-run');
        $report = (new PartImporter($category, (bool) $this->option('publish')))->import($records, $dry);

        $this->info(($dry ? 'DRY RUN - nothing written. ' : '').count($report['created']).' to add, '
            .array_sum(array_map('count', $report['skipped'])).' skipped, of '.count($records).' in the file.');

        foreach ($report['created'] as $model) {
            $this->line("  + {$model}");
        }

        $why = [
            'exists'    => 'already in the catalogue under this name',
            'same-card' => 'same chipset, memory size and type as an existing row',
            'slug'      => 'its page address is taken',
            'invalid'   => 'missing manufacturer, model, chipset or memory size',
        ];

        foreach ($report['skipped'] as $reason => $models) {
            $this->newLine();
            $this->warn(count($models).' skipped - '.($why[$reason] ?? $reason).':');
            foreach ($models as $model) {
                $this->line("  - {$model}");
            }
        }

        if ($report['dropped'] !== []) {
            $this->newLine();
            $this->warn('Spec values dropped (not in the catalogue\'s allowed options), row kept:');
            foreach ($report['dropped'] as $model => $keys) {
                $this->line("  ~ {$model}: ".implode(', ', $keys));
            }
        }

        if (! $dry && $report['created'] !== []) {
            $this->newLine();
            $this->line($this->option('publish')
                ? 'Published. Run remarket:refresh-part-stats to give the new pages their price bands.'
                : 'Added unpublished: they feed the listing form\'s search and the filters, and have no public page until published in /katalog.');
        }

        return self::SUCCESS;
    }
}
