<?php

namespace App\Console\Commands;

use App\Catalog\Images\ItemImages;
use App\Catalog\Models\Item;
use Illuminate\Console\Command;

/**
 * Fills the image cache with pictures of what the person owns.
 *
 * Runs outside the request cycle on purpose: fetching in a request made a
 * single card take seconds and a full page of them exhaust the web server.
 * Nothing waits on this — a picture that is not cached yet is shown straight
 * from the source. The cache is what makes the collection keep its pictures
 * when the source will not answer.
 */
class FetchItemImagesCommand extends Command
{
    protected $signature = 'catalog:images
                            {--limit=200 : How many images to fetch in this run}
                            {--retry : Also retry lookups that previously failed}
                            {--pause=100 : Milliseconds to wait between requests}
                            {--no-queue : Do not look for newly owned items first}';

    protected $description = 'Download pictures of items in the collection';

    public function handle(ItemImages $images): int
    {
        if (! $this->option('no-queue')) {
            $added = $images->queueCollection();

            if ($added > 0) {
                $this->line("Queued {$added} newly owned items.");
            }
        }

        $pending = $images->pending((int) $this->option('limit'), (bool) $this->option('retry'));

        if ($pending->isEmpty()) {
            $this->info('Nothing to fetch.');

            return self::SUCCESS;
        }

        $pause = (int) $this->option('pause') * 1000;
        $tally = ['ok' => 0, 'missing' => 0, 'error' => 0];
        $bar = $this->output->createProgressBar($pending->count());

        foreach ($pending as $row) {
            $item = Item::where('type', $row->item_type)->where('id', $row->item_id)->first();

            if ($item) {
                $tally[$images->fetch($item, (int) $row->color_id)]++;
            }

            $bar->advance();

            // The source is somebody else's server; do not hammer it.
            usleep($pause);
        }

        $bar->finish();
        $this->newLine(2);

        foreach ($tally as $status => $count) {
            $this->line(sprintf('  %-8s %d', $status, $count));
        }

        if ($tally['ok'] === 0 && $tally['missing'] > 0) {
            $this->warn('No image was retrieved. The source may be refusing requests from this network;');
            $this->warn('BRICKCOLLECTOR_IMAGE_URL can point at a different one.');
        }

        return self::SUCCESS;
    }
}
