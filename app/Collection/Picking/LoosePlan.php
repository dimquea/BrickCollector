<?php

namespace App\Collection\Picking;

use Illuminate\Support\Facades\DB;

/**
 * Что и из каких свободных партий возьмёт добавление набора.
 *
 * Одним и тем же кодом собирается и отчёт, который скачивают заранее, и само
 * добавление: отчёт обещает ровно то, что потом произойдёт, — иначе по нему
 * собрали бы из ящиков не те детали.
 *
 * Партии тратятся от старых к новым, как и при сборке: так же, как это делает
 * MoveParts. Потерянное в партии не берётся — его нет на руках.
 *
 * Фигурки берутся только те, что отметили: свободная фигурка — вещь сама по
 * себе, и отправить её в коробку молча было бы решением за человека.
 */
final class LoosePlan
{
    /**
     * @param  array<int, array{key: string, type: string, spare: bool, entry_id: int, root_id: int, item_id: string, color_id: int, qty: int, lot_qty: int, storage: ?string, tags: string, acquired_at: ?string}>  $takes
     * @param  array<string, int>  $missingParts  по ключу Needs::key()
     * @param  array<string, int>  $missingFigures  по номеру фигурки
     * @param  array<string, int>  $missingSpares  по ключу Needs::key()
     */
    private function __construct(
        public readonly array $takes,
        public readonly array $missingParts,
        public readonly array $missingFigures,
        public readonly array $missingSpares,
    ) {}

    /** @param  array<int, string>  $figures  номера фигурок, отмеченных к переносу */
    public static function make(Needs $needs, array $figures = []): self
    {
        $takes = [];
        $missingParts = [];
        $missingFigures = [];
        $missingSpares = [];

        $ids = array_column([...array_values($needs->parts), ...array_values($needs->spares)], 'id');
        $lots = self::queue('P', array_values(array_unique($ids)));

        // Сначала нужное, потом запасные — из того, что осталось: без
        // запасной набор полон, без нужной детали — нет.
        foreach ($needs->parts as $key => $need) {
            $left = self::draw($lots[$key], $need['qty'], ['key' => $key, 'type' => 'P', 'spare' => false], $takes);

            if ($left > 0) {
                $missingParts[$key] = $left;
            }
        }

        foreach ($needs->spares as $key => $need) {
            $left = self::draw($lots[$key], $need['qty'], ['key' => $key, 'type' => 'P', 'spare' => true], $takes);

            if ($left > 0) {
                $missingSpares[$key] = $left;
            }
        }

        $chosen = array_values(array_intersect(array_keys($needs->figures), $figures));
        $loose = self::queue('M', $chosen);

        foreach ($needs->figures as $id => $need) {
            $left = in_array($id, $chosen, true)
                ? self::draw($loose[Needs::key($id, 0)], $need['qty'], ['key' => $id, 'type' => 'M', 'spare' => false], $takes)
                : $need['qty'];

            if ($left > 0) {
                $missingFigures[$id] = $left;
            }
        }

        return new self($takes, $missingParts, $missingFigures, $missingSpares);
    }

    /**
     * Берёт из очереди партий сколько нужно, от старых к новым, и помечает
     * взятое: та же партия может понадобиться и запасной.
     *
     * @param  array<int, array{available: int, row: array<string, mixed>}>|null  $queue
     * @param  array<string, mixed>  $base
     * @param  array<int, array<string, mixed>>  $takes
     * @return int сколько не нашлось
     */
    private static function draw(?array &$queue, int $qty, array $base, array &$takes): int
    {
        if ($queue === null) {
            return $qty;
        }

        // По ссылке и по самой очереди, а не по `$queue ?? []`: та была бы
        // копией, и взятое осталось бы невзятым для следующего.
        foreach ($queue as &$lot) {
            if ($qty === 0) {
                break;
            }

            $take = min($lot['available'], $qty);

            if ($take > 0) {
                $takes[] = $base + ['qty' => $take] + $lot['row'];
                $lot['available'] -= $take;
                $qty -= $take;
            }
        }

        return $qty;
    }

    /** Сколько разных партий и фигурок пойдёт в дело — для итога перед подтверждением. */
    public function lots(): int
    {
        return count(array_unique(array_column($this->takes, 'entry_id')));
    }

    /**
     * Свободные партии по ключу «предмет/цвет», от старых к новым.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array<int, array{available: int, row: array<string, mixed>}>>
     */
    private static function queue(string $type, array $ids): array
    {
        $result = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('collection_entries as e')
                ->join('collection_items as ci', fn ($join) => $join
                    ->on('ci.entry_id', '=', 'e.id')
                    ->whereNull('ci.parent_id'))
                ->leftJoin('ref_storages as s', 's.id', '=', 'e.storage_id')
                ->where('e.item_type', $type)
                ->whereIn('e.item_id', $chunk)
                ->orderByRaw('COALESCE(e.acquired_at, e.created_at)')
                ->orderBy('e.id')
                ->get([
                    'e.id as entry_id', 'e.item_id', 'ci.color_id', 'ci.id as root_id', 'ci.qty', 'ci.lost_qty',
                    'e.acquired_at', 's.name as storage',
                ]);

            $tags = DB::table('entry_tags as et')
                ->join('ref_tags as t', 't.id', '=', 'et.tag_id')
                ->whereIn('et.entry_id', $rows->pluck('entry_id'))
                ->orderBy('t.sort')
                ->get(['et.entry_id', 't.name'])
                ->groupBy('entry_id');

            foreach ($rows as $row) {
                // У фигурки цвета нет; у партии он и есть половина ключа.
                $key = Needs::key($row->item_id, $type === 'P' ? (int) $row->color_id : 0);

                $result[$key][] = [
                    'available' => max(0, (int) $row->qty - (int) $row->lost_qty),
                    'row' => [
                        'entry_id' => (int) $row->entry_id,
                        'root_id' => (int) $row->root_id,
                        'item_id' => $row->item_id,
                        'color_id' => (int) $row->color_id,
                        'lot_qty' => (int) $row->qty,
                        'storage' => $row->storage,
                        'tags' => ($tags->get($row->entry_id) ?? collect())->pluck('name')->implode(', '),
                        'acquired_at' => $row->acquired_at === null ? null : substr((string) $row->acquired_at, 0, 10),
                    ],
                ];
            }
        }

        return $result;
    }
}
