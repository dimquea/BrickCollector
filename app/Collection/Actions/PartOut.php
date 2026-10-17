<?php

namespace App\Collection\Actions;

use App\Collection\Models\Entry;
use App\Collection\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Расформировывает набор: раскладывает его в свободные.
 *
 * Обратное к добавлению из свободных, и тот же закон: детали не создаются и
 * не исчезают, а переезжают. Что лежало в коробке, оказывается в ящиках:
 *
 * - детали, засчитанные и запасные, — по свободной партии на пару «деталь +
 *   цвет»; с уже лежащими партиями они не сливаются: партия — это покупка;
 * - фигурки — отдельными экземплярами, со своими отметками недостачи деталей;
 * - снаряжение, книги, каталоги — отдельными экземплярами своего типа;
 * - поднаборы раскладываются так же, сколь угодно глубоко.
 *
 * Потерянное не переносится: его нет и в коробке. Альтернатив и парных строк
 * в коробке не было никогда.
 *
 * Хранилище и дата покупки переходят на новые экземпляры: детали лежат там,
 * где лежал набор, и куплены были тогда же. Цена и теги — нет: цена была одна
 * на весь набор, и делить её по деталям значило бы её выдумать.
 */
class PartOut
{
    public function __construct(
        private readonly WriteContents $contents,
        private readonly RecalculateEntryFlags $flags,
    ) {}

    /**
     * Что получится, без записи — для подтверждения.
     *
     * @return array{parts: int, lots: int, figures: int, others: int, lost: int}
     */
    public function preview(Entry $set): array
    {
        return $this->summary($this->plan($set));
    }

    /** @return array{parts: int, lots: int, figures: int, others: int, lost: int} */
    public function handle(Entry $set): array
    {
        return DB::transaction(function () use ($set) {
            $plan = $this->plan($set);

            $meta = [
                'storage_id' => $set->storage_id,
                'acquired_at' => $set->acquired_at,
                'flag_incomplete' => false,
                'flag_missing_figs' => false,
            ];

            foreach ($plan['parts'] as $part) {
                $lot = Entry::create($meta + [
                    'item_type' => 'P',
                    'item_id' => $part['id'],
                    'color_id' => $part['color_id'],
                ]);

                $this->contents->root($lot, 'P', $part['id'], $part['color_id'], $part['qty']);
            }

            foreach ($plan['figures'] as [$row, $present]) {
                // Комментарий фигурки в наборе — «трещина на ноге» — становится
                // заметкой её собственного экземпляра: дефект никуда не делся.
                $figure = Entry::create($meta + [
                    'item_type' => 'M',
                    'item_id' => $row->item_id,
                    'note' => $row->note,
                ]);

                $this->copy($row, $figure, $present, $row->qty, null, null);

                $this->flags->handle($figure);
            }

            foreach ($plan['others'] as [$row, $present]) {
                // Каждая вещь — свой экземпляр: так их считает раздел, где
                // снаряжение живёт рядом с наборами.
                for ($i = 0; $i < $present; $i++) {
                    $thing = Entry::create($meta + [
                        'item_type' => $row->item_type,
                        'item_id' => $row->item_id,
                        'color_id' => (int) $row->color_id > 0 ? (int) $row->color_id : null,
                    ]);

                    $this->contents->root($thing, $row->item_type, $row->item_id, (int) $row->color_id, 1);
                }
            }

            $set->delete();

            return $this->summary($plan);
        });
    }

    /**
     * Обходит дерево и решает, что куда уйдёт.
     *
     * Поднабор, потерянный частью, уводит за собой ту же долю своего
     * содержимого: из двух пакетов на руках один, и деталей в нём — на один.
     *
     * @return array{parts: array<string, array{id: string, color_id: int, qty: int}>, figures: array<int, array{0: Item, 1: int}>, others: array<int, array{0: Item, 1: int}>, lost: int}
     */
    private function plan(Entry $set): array
    {
        $children = Item::where('entry_id', $set->id)->orderBy('id')->get()
            ->groupBy(fn (Item $row) => $row->parent_id ?? 0);

        $plan = ['parts' => [], 'figures' => [], 'others' => [], 'lost' => 0];

        $walk = function (Collection $rows, int $num, int $den, bool $parentCounts) use (&$walk, &$plan, $children): void {
            foreach ($rows as $row) {
                // Засчитанное — в коробке; запасная — тоже, если в коробке
                // её родитель. Альтернатива, парная строка и всё внутри них —
                // нет.
                if (! $row->counts && ! ($row->is_extra && $parentCounts)) {
                    continue;
                }

                $present = intdiv(max(0, $row->qty - $row->lost_qty) * $num, max(1, $den));

                $plan['lost'] += $row->item_type === 'P' || $row->item_type === 'M'
                    ? min($row->qty, $row->lost_qty)
                    : 0;

                if ($row->item_type === 'S') {
                    $walk(
                        $children->get($row->id, collect()),
                        $num * max(0, $row->qty - $row->lost_qty),
                        $den * max(1, $row->qty),
                        $row->counts,
                    );

                    continue;
                }

                if ($present === 0) {
                    continue;
                }

                if ($row->item_type === 'P') {
                    $key = $row->item_id.'/'.(int) $row->color_id;

                    $plan['parts'][$key] ??= ['id' => $row->item_id, 'color_id' => (int) $row->color_id, 'qty' => 0];
                    $plan['parts'][$key]['qty'] += $present;
                } elseif ($row->item_type === 'M') {
                    $plan['figures'][] = [$row, $present];
                } else {
                    $plan['others'][] = [$row, $present];
                }
            }
        };

        $walk($children->get(0, collect()), 1, 1, true);

        ksort($plan['parts']);

        return $plan;
    }

    /**
     * Копирует фигурку с поддеревом в новый экземпляр, на столько штук,
     * сколько их на руках. Отметки недостачи её деталей едут с ней.
     */
    private function copy(Item $source, Entry $target, int $qty, int $of, ?int $parentId, ?string $parentType): void
    {
        $scaled = $parentId === null ? $qty : intdiv($source->qty * $qty, max(1, $of));

        $id = $target->items()->create([
            'parent_id' => $parentId,
            'parent_item_type' => $parentType,
            'item_type' => $source->item_type,
            'item_id' => $source->item_id,
            'color_id' => $source->color_id,
            'qty' => $scaled,
            // Сама фигурка цела — иначе её бы не перенесли; детали её — как
            // были отмечены.
            'lost_qty' => $parentId === null ? 0 : min($source->lost_qty, $scaled),
            'is_extra' => $parentId === null ? false : $source->is_extra,
            'is_alternate' => $parentId === null ? false : $source->is_alternate,
            'is_counterpart' => $parentId === null ? false : $source->is_counterpart,
            'match_id' => $parentId === null ? 0 : $source->match_id,
            'counts' => $parentId === null ? true : $source->counts,
            // У корня комментарий уехал в заметку экземпляра; у вложенного —
            // фигурки в фигурке — остаётся на строке.
            'note' => $parentId === null ? null : $source->note,
        ])->id;

        foreach ($source->children()->orderBy('id')->get() as $child) {
            $this->copy($child, $target, $qty, $of, $id, $source->item_type);
        }
    }

    /**
     * @param  array{parts: array<string, array{qty: int}>, figures: array<int, array{0: Item, 1: int}>, others: array<int, array{0: Item, 1: int}>, lost: int}  $plan
     * @return array{parts: int, lots: int, figures: int, others: int, lost: int}
     */
    private function summary(array $plan): array
    {
        return [
            'parts' => (int) array_sum(array_column($plan['parts'], 'qty')),
            'lots' => count($plan['parts']),
            'figures' => (int) array_sum(array_map(fn (array $figure) => $figure[1], $plan['figures'])),
            'others' => (int) array_sum(array_map(fn (array $other) => $other[1], $plan['others'])),
            'lost' => $plan['lost'],
        ];
    }
}
