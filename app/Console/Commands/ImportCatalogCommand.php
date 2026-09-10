<?php

namespace App\Console\Commands;

use App\Catalog\Import\CatalogArchive;
use App\Catalog\Import\ImportCatalog;
use Illuminate\Console\Command;
use Throwable;

class ImportCatalogCommand extends Command
{
    protected $signature = 'catalog:import
                            {--archive= : Path to a downloads.zip to import instead of the configured one}';

    protected $description = 'Import the BrickLink catalog from a brickstore-database archive';

    public function handle(ImportCatalog $import): int
    {
        $path = $this->option('archive') ?: config('brickcollector.archive_path');

        $this->line("Archive: {$path}");

        try {
            $archive = new CatalogArchive($path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->line('Download a release from '.config('brickcollector.release_url'));

            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $counts = $import->handle($archive, function (string $step) {
                $this->line("  {$step}...");
            });
        } catch (Throwable $e) {
            $archive->close();
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $archive->close();
        }

        $this->newLine();

        $this->table(
            ['what', 'rows'],
            collect($counts)->map(fn ($count, $what) => [$what, number_format($count)])->all(),
        );

        $this->table(
            ['step', 'seconds'],
            collect($import->timings())->map(fn ($seconds, $step) => [$step, sprintf('%.1f', $seconds)])->all(),
        );

        $this->info(sprintf(
            'Imported in %.1f s, peak memory %.0f MB',
            microtime(true) - $started,
            memory_get_peak_usage(true) / 1048576,
        ));

        return self::SUCCESS;
    }
}
