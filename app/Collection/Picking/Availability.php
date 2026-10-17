<?php

namespace App\Collection\Picking;

use Illuminate\Support\Facades\DB;

/**
 * Сколько нужного уже есть в коллекции — везде, в наборах и в свободных.
 *
 * Все три ответа приходят разом: переключатель в окне меняет таблицу на месте,
 * без нового запроса.
 *
 * Потерянное не в счёт: его нет на руках, и выдать его за наличие значило бы
 * пообещать деталь, которой не найти. Запасные тоже не в счёт — как и в
 * разделе деталей, они идут отдельной колонкой и в количество не входят.
 */
final class Availability
{
    private const SCOPES = ['all' => 0, 'sets' => 0, 'loose' => 0];

    /**
     * @return array<string, array{all: int, sets: int, loose: int}> по ключу Needs::key()
     */
    public static function parts(Needs $needs): array
    {
        $found = [];
        $ids = array_values(array_unique(array_column($needs->parts, 'id')));

        // Кусками: у большого набора под тысячу разных деталей, а у SQLite
        // предел на число параметров запроса.
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('collection_items as ci')
                ->join('collection_entries as e', 'e.id', '=', 'ci.entry_id')
                ->where('ci.item_type', 'P')
                ->where('ci.counts', 1)
                ->whereIn('ci.item_id', $chunk)
                ->groupBy('ci.item_id', 'ci.color_id')
                ->select('ci.item_id', 'ci.color_id')
                ->selectRaw('SUM(ci.qty - ci.lost_qty) as all_qty')
                // Те же места, что и в разделе деталей.
                ->selectRaw("COALESCE(SUM(CASE WHEN ci.parent_item_type = 'S'
                    OR (ci.parent_item_type IS NULL AND e.item_type = 'S')
                    THEN ci.qty - ci.lost_qty END), 0) as sets_qty")
                ->selectRaw("COALESCE(SUM(CASE WHEN ci.parent_item_type IS NULL AND e.item_type = 'P'
                    THEN ci.qty - ci.lost_qty END), 0) as loose_qty")
                ->get();

            foreach ($rows as $row) {
                $found[Needs::key($row->item_id, (int) $row->color_id)] = [
                    'all' => (int) $row->all_qty,
                    'sets' => (int) $row->sets_qty,
                    'loose' => (int) $row->loose_qty,
                ];
            }
        }

        $result = [];

        foreach (array_keys($needs->parts) as $key) {
            $result[$key] = $found[$key] ?? self::SCOPES;
        }

        return $result;
    }

    /**
     * Фигурки — целиком. Свободная — отдельный экземпляр в разделе фигурок;
     * в наборах — то, что лежит в коробках. Фигурка внутри фигурки — это её
     * часть, а не вторая фигурка, и здесь не считается.
     *
     * @return array<string, array{all: int, sets: int, loose: int}> по номеру фигурки
     */
    public static function figures(Needs $needs): array
    {
        $result = [];

        if ($needs->figures === []) {
            return $result;
        }

        $rows = DB::table('collection_items as ci')
            ->join('collection_entries as e', 'e.id', '=', 'ci.entry_id')
            ->where('ci.item_type', 'M')
            ->where('ci.counts', 1)
            ->whereIn('ci.item_id', array_keys($needs->figures))
            ->groupBy('ci.item_id')
            ->select('ci.item_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN e.item_type = 'M' AND ci.parent_id IS NULL
                THEN ci.qty - ci.lost_qty END), 0) as loose_qty")
            ->selectRaw("COALESCE(SUM(CASE WHEN e.item_type IS NOT NULL AND e.item_type <> 'M'
                AND (ci.parent_item_type IS NULL OR ci.parent_item_type <> 'M')
                THEN ci.qty - ci.lost_qty END), 0) as sets_qty")
            ->get()
            ->keyBy('item_id');

        foreach (array_keys($needs->figures) as $id) {
            $row = $rows->get($id);
            $loose = (int) ($row->loose_qty ?? 0);
            $sets = (int) ($row->sets_qty ?? 0);

            $result[$id] = ['all' => $loose + $sets, 'sets' => $sets, 'loose' => $loose];
        }

        return $result;
    }
}
