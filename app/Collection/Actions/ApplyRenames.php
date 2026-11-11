<?php

namespace App\Collection\Actions;

use App\Catalog\Queries\Renames;
use Illuminate\Support\Facades\DB;

/**
 * Переводит коллекцию на нынешние артикулы справочника.
 *
 * BrickLink переименовывает предметы постоянно — за 2025 год двенадцать тысяч
 * раз, — а коллекция хранит артикул, каким он был в день добавления. После
 * обновления справочника такая строка ссылается в пустоту: без названия и
 * картинки, а «Обновить описи» не узнаёт её в свежей описи и теряет отметку о
 * недостаче.
 *
 * Переименование — не изменение состава: на полке та же деталь, у неё лишь
 * другой номер. Поэтому идёт само, после каждого импорта справочника, без
 * спроса; что поменялось, записывается в отчёт для страницы настроек.
 * Количества, недостача и комментарии остаются при своих строках — меняется
 * только артикул.
 *
 * Смену типа в описи не пишем. Фигурка, ставшая деталью, со своими деталями
 * внутри посчиталась бы дважды; правильный её вид — с новым деревом под ней —
 * даёт только пересборка описи, и «Обновить описи» сама переносит отметки на
 * сменившую тип позицию. Экземпляр, сменивший тип, — набор, ставший gear, —
 * остаётся как есть: у набора и прочих вещей разное устройство строк, и
 * переделывать его молча нельзя. В отчёте видно и то и другое.
 */
class ApplyRenames
{
    public function __construct(private readonly Renames $renames) {}

    /**
     * @param  bool  $reportSkipped  записывать ли в отчёт оставленные смены
     *     типа. «Обновить описи» их не записывает: она исправит их тут же сама.
     * @return array{renamed: int, skipped: int, left: array<string, array{type: string, id: string}>}
     *     предметов переименовано и оставлено; left — оставленные, «тип/артикул» => куда ведёт
     */
    public function handle(bool $reportSkipped = true): array
    {
        $map = $this->renames->resolve($this->dangling());

        if ($map === []) {
            return ['renamed' => 0, 'skipped' => 0, 'left' => []];
        }

        $now = now()->toDateTimeString();
        $summary = ['renamed' => 0, 'skipped' => 0, 'left' => []];

        DB::transaction(function () use ($map, $now, $reportSkipped, &$summary) {
            foreach ($map as $key => $to) {
                [$type, $id] = explode('/', $key, 2);

                $report = $this->one($type, $id, $to['type'], $to['id']);

                $summary[$report['applied'] ? 'renamed' : 'skipped']++;

                if (! $report['applied']) {
                    $summary['left'][$key] = $to;

                    if (! $reportSkipped) {
                        continue;
                    }
                }

                DB::table('collection_renames')->insert($report + [
                    'renamed_at' => $now,
                    'from_type' => $type,
                    'from_id' => $id,
                    'to_type' => $to['type'],
                    'to_id' => $to['id'],
                ]);
            }
        });

        return $summary;
    }

    /**
     * Один предмет: экземпляры, строки описей, желаемое.
     *
     * @return array{entries: int, rows: int, wishes: int, marks: int, applied: bool}
     */
    private function one(string $type, string $id, string $toType, string $toId): array
    {
        $sameType = $type === $toType;

        $entries = DB::table('collection_entries')->where('item_type', $type)->where('item_id', $id);
        $rows = DB::table('collection_items')->where('item_type', $type)->where('item_id', $id);

        $report = [
            'entries' => (clone $entries)->count(),
            'rows' => (clone $rows)->count(),
            'marks' => (clone $rows)->where(fn ($q) => $q->where('lost_qty', '>', 0)->orWhereNotNull('note'))->count(),
            'wishes' => $this->wishes($type, $id, $toType, $toId),
            'applied' => $sameType,
        ];

        if ($sameType) {
            $entries->update(['item_id' => $toId]);
            $rows->update(['item_id' => $toId]);
        }

        // Если трогать оказалось только желаемое, а его тип сменить можно, —
        // это полноценное переименование, не пропуск.
        $report['applied'] = $sameType || ($report['entries'] === 0 && $report['rows'] === 0);

        return $report;
    }

    /**
     * Желаемое — список артикулов, без описей и устройства внутри, поэтому
     * переводится всегда, и с типом тоже. Слияние может свести два желания в
     * одно: уже желаемое под новым номером остаётся, старое уходит.
     */
    private function wishes(string $type, string $id, string $toType, string $toId): int
    {
        $wishes = DB::table('wishlist')->where('item_type', $type)->where('item_id', $id)->get(['id', 'color_id']);

        foreach ($wishes as $wish) {
            $taken = DB::table('wishlist')
                ->where('item_type', $toType)
                ->where('item_id', $toId)
                ->where('color_id', $wish->color_id)
                ->exists();

            $taken
                ? DB::table('wishlist')->where('id', $wish->id)->delete()
                : DB::table('wishlist')->where('id', $wish->id)->update(['item_type' => $toType, 'item_id' => $toId]);
        }

        return $wishes->count();
    }

    /**
     * Артикулы коллекции, которых нет в справочнике.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function dangling(): array
    {
        $missing = fn (string $table) => DB::table($table)
            ->whereNotNull("{$table}.item_type")
            ->whereNotExists(fn ($sub) => $sub
                ->from('bl_items as i')
                ->whereColumn('i.type', "{$table}.item_type")
                ->whereColumn('i.id', "{$table}.item_id")
                ->selectRaw('1'))
            ->select('item_type', 'item_id');

        return $missing('collection_entries')
            ->union($missing('collection_items'))
            ->union($missing('wishlist'))
            ->get()
            ->map(fn (object $row) => [$row->item_type, $row->item_id])
            ->all();
    }
}
