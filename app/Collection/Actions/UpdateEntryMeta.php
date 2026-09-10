<?php

namespace App\Collection\Actions;

use App\Collection\Models\Entry;
use Illuminate\Support\Facades\DB;

/**
 * Saves what the user knows about a copy: when it was bought, for how much,
 * where it came from, where it lives, how it is doing, and any note.
 */
class UpdateEntryMeta
{
    /**
     * @param  array{
     *     acquired_at?: ?string,
     *     price?: ?int,
     *     source_id?: ?int,
     *     storage_id?: ?int,
     *     note?: ?string,
     *     status_ids?: array<int, int>,
     *     tag_ids?: array<int, int>,
     * }  $data
     */
    public function handle(Entry $entry, array $data): Entry
    {
        return DB::transaction(function () use ($entry, $data) {
            $entry->fill([
                'acquired_at' => $data['acquired_at'] ?? null,
                // Minor units throughout. Nothing in the application handles a
                // fractional amount; the interface converts on the way in and
                // on the way out.
                'price' => $data['price'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'storage_id' => $data['storage_id'] ?? null,
                'note' => $data['note'] ?? null,
            ])->save();

            if (array_key_exists('status_ids', $data)) {
                $entry->statuses()->sync($data['status_ids'] ?? []);
            }

            if (array_key_exists('tag_ids', $data)) {
                $entry->tags()->sync($data['tag_ids'] ?? []);
            }

            return $entry->refresh();
        });
    }
}
