<?php

namespace App\Collection\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Последний прогон переименований — для настроек.
 *
 * Только последний: отчёт отвечает на вопрос «что поменялось после
 * обновления», а не ведёт историю. Прогон, в котором переименовывать было
 * нечего, строк не пишет, поэтому отчёт держится до следующего настоящего.
 */
final class RenameReport
{
    /** Больше на странице не прочесть; остальное — числом. */
    private const SHOWN = 200;

    /**
     * @return array{at: string, total: int, marks: int, items: array<int, array<string, mixed>>}|null
     */
    public static function latest(): ?array
    {
        $at = DB::table('collection_renames')->max('renamed_at');

        if ($at === null) {
            return null;
        }

        $rows = DB::table('collection_renames as r')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'r.to_type')
                ->on('i.id', '=', 'r.to_id'))
            ->where('r.renamed_at', $at)
            // Сначала то, что осталось на человеке, потом то, где были отметки.
            ->orderBy('r.applied')
            ->orderByDesc('r.marks')
            ->orderBy('r.from_id');

        return [
            'at' => $at,
            'total' => (clone $rows)->count(),
            'marks' => (int) (clone $rows)->sum('r.marks'),
            'items' => $rows->limit(self::SHOWN)->get([
                'r.from_type', 'r.from_id', 'r.to_type', 'r.to_id', 'r.entries', 'r.rows', 'r.wishes',
                'r.marks', 'r.applied', DB::raw('COALESCE(i.name, r.to_id) as name'),
            ])->map(fn (object $row) => [
                'from_type' => $row->from_type,
                'from_id' => $row->from_id,
                'to_type' => $row->to_type,
                'to_id' => $row->to_id,
                'name' => $row->name,
                'entries' => (int) $row->entries,
                'rows' => (int) $row->rows,
                'wishes' => (int) $row->wishes,
                'marks' => (int) $row->marks,
                'applied' => (bool) $row->applied,
            ])->all(),
        ];
    }
}
