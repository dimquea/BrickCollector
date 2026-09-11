<?php

namespace App\Console\Commands;

use App\Catalog\Import\CatalogArchive;
use App\Catalog\Import\CatalogStatus;
use App\Catalog\Import\ImportCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class ImportCatalogCommand extends Command
{
    protected $signature = 'catalog:import
                            {--archive= : Path to a downloads.zip to import instead of the configured one}
                            {--download : Fetch a fresh archive before importing}';

    protected $description = 'Import the BrickLink catalog from a brickstore-database archive';

    public function handle(ImportCatalog $import): int
    {
        $path = $this->option('archive') ?: config('brickcollector.archive_path');

        CatalogStatus::start();

        $this->line("Archive: {$path}");

        if ($this->option('download') && ! $this->download($path)) {
            return self::FAILURE;
        }

        try {
            $archive = new CatalogArchive($path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->line('Download a release from '.config('brickcollector.release_url'));
            CatalogStatus::failed($e->getMessage());

            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $counts = $import->handle($archive, function (string $step) {
                $this->line("  {$step}...");

                // Шаг — он же признак жизни: страница показывает, на чём мы,
                // а брошенный процесс перестаёт обновлять состояние.
                CatalogStatus::step($step);
            });
        } catch (Throwable $e) {
            $archive->close();
            $this->error($e->getMessage());
            CatalogStatus::failed($e->getMessage());

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

        CatalogStatus::finished((int) ($counts['items'] ?? 0));

        $this->info(sprintf(
            'Imported in %.1f s, peak memory %.0f MB',
            microtime(true) - $started,
            memory_get_peak_usage(true) / 1048576,
        ));

        return self::SUCCESS;
    }

    /**
     * Скачивает свежий архив поверх старого.
     *
     * Пишем во временный файл и подменяем одним движением: оборванная закачка
     * не должна оставить вместо справочника огрызок, который потом не
     * распакуется.
     */
    private function download(string $path): bool
    {
        $url = config('brickcollector.release_url');
        $temporary = $path.'.part';

        $this->line("Downloading {$url}");

        try {
            @mkdir(dirname($path), 0775, true);

            $response = Http::timeout(600)->sink($temporary)->get($url);

            if (! $response->successful()) {
                throw new \RuntimeException("The download answered {$response->status()}.");
            }

            rename($temporary, $path);
        } catch (Throwable $e) {
            @unlink($temporary);
            $this->error($e->getMessage());
            CatalogStatus::failed($e->getMessage());

            return false;
        }

        $this->line(sprintf('  %.0f MB', filesize($path) / 1048576));

        return true;
    }
}
