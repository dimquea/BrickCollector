<?php

namespace App\Collection\Actions;

use App\Catalog\Models\Item as CatalogItem;
use App\Catalog\Queries\ItemInventory;
use App\Collection\Models\Entry;
use App\Collection\Models\Item;
use App\Collection\Picking\LoosePlan;
use App\Collection\Picking\Needs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Добавляет набор, собранный из свободных деталей.
 *
 * Набор записывается обычным путём, целиком из справочника, а затем каждая
 * его строка берёт своё из свободных. Чего не нашлось, ставится недостачей —
 * той же отметкой, какую человек поставил бы руками, разбирая коробку.
 *
 * Детали не создаются и не исчезают, а переезжают: из свободной партии в
 * набор. Партия, потраченная до конца, удаляется, как и при сборке. Запасные
 * берутся тоже, после нужного: запасная, записанная в набор просто так, при
 * расформировании уехала бы в свободные, и детали родились бы из ничего.
 *
 * Фигурка переезжает, только если её отметили. Вместе с ней едут отметки
 * недостачи её деталей: голова, потерянная у свободной фигурки, не находится
 * оттого, что фигурку положили в коробку. Неотмеченная фигурка остаётся
 * свободной, а в наборе считается потерянной.
 *
 * Всё — одной транзакцией. Набор, добавленный наполовину, хуже недобавленного:
 * детали уже ушли из свободных, а отметок недостачи ещё нет.
 */
class AddFromLoose
{
    public function __construct(
        private readonly AddToCollection $add,
        private readonly ItemInventory $inventory,
        private readonly MoveParts $move,
        private readonly RecalculateEntryFlags $flags,
    ) {}

    /** @param  array<int, string>  $figures  номера фигурок, отмеченных к переносу */
    public function handle(CatalogItem $item, array $figures): Entry
    {
        return DB::transaction(function () use ($item, $figures) {
            // План — до добавления. Добавляемая фигурка сама стала бы
            // свободной фигуркой и нашлась бы в собственном плане.
            $plan = LoosePlan::make(Needs::of($this->inventory->tree($item->type, $item->id)), $figures);

            $entry = $this->add->handle($item);

            [$parts, $figureRows, $spares] = $this->rows($entry);

            $taken = [];

            foreach ($plan->takes as $take) {
                if ($take['type'] === 'P') {
                    $this->move->spend(Entry::findOrFail($take['entry_id']), $take['qty']);
                } else {
                    $taken[$take['key']][] = $take;
                }
            }

            $this->markLost($parts, $plan->missingParts);

            // Запасной, которой не нашлось, тоже нет в коробке. Отметка на ней
            // некомплектом набор не делает — без запасной он полон, — но
            // расформирование не вынет из коробки того, чего туда не клали.
            $this->markLost($spares, $plan->missingSpares);

            foreach ($figureRows as $id => $rows) {
                $this->figures($rows, $taken[$id] ?? []);
            }

            return $this->flags->handle($entry);
        });
    }

    /**
     * Строки нового экземпляра, которым подбирают пару: детали по ключу
     * «деталь/цвет» и фигурки по номеру, в порядке записи.
     *
     * Правила те же, что у Needs: поднаборы раскрываются, внутрь фигурки не
     * заходим, не засчитанное пропускаем вместе с поддеревом.
     *
     * @return array{0: array<string, array<int, Item>>, 1: array<string, array<int, Item>>, 2: array<string, array<int, Item>>}
     */
    private function rows(Entry $entry): array
    {
        $children = Item::where('entry_id', $entry->id)->orderBy('id')->get()
            ->groupBy(fn (Item $row) => $row->parent_id ?? 0);

        $parts = [];
        $figures = [];
        $spares = [];

        $walk = function (Collection $rows) use (&$walk, &$parts, &$figures, &$spares, $children): void {
            foreach ($rows as $row) {
                if (! $row->counts) {
                    // Сюда доходят только строки засчитанных родителей, так что
                    // запасная здесь — в коробке.
                    if ($row->is_extra && $row->item_type === 'P') {
                        $spares[Needs::key($row->item_id, (int) $row->color_id)][] = $row;
                    }

                    continue;
                }

                match ($row->item_type) {
                    'P' => $parts[Needs::key($row->item_id, (int) $row->color_id)][] = $row,
                    'M' => $figures[$row->item_id][] = $row,
                    'S' => $walk($children->get($row->id, collect())),
                    default => null,
                };
            }
        };

        $roots = $children->get(0, collect());

        // У фигурки из справочника корень — она сама; подбирают ей её детали.
        $walk($entry->item_type === 'S' ? $roots : $children->get($roots->first()?->id, collect()));

        return [$parts, $figures, $spares];
    }

    /**
     * Ставит недостачу на строки набора, по порядку записи.
     *
     * @param  array<string, array<int, Item>>  $rows
     * @param  array<string, int>  $missing
     */
    private function markLost(array $rows, array $missing): void
    {
        foreach ($missing as $key => $left) {
            foreach ($rows[$key] ?? [] as $row) {
                $lost = min($row->qty, $left);
                $row->update(['lost_qty' => $lost]);
                $left -= $lost;

                if ($left === 0) {
                    break;
                }
            }
        }
    }

    /**
     * Раздаёт строкам фигурки набора свободные фигурки из плана.
     *
     * @param  array<int, Item>  $rows
     * @param  array<int, array<string, mixed>>  $takes
     */
    private function figures(array $rows, array $takes): void
    {
        foreach ($rows as $row) {
            $wanted = $row->qty;

            while ($wanted > 0 && $takes !== []) {
                $units = min($wanted, $takes[0]['qty']);

                $this->moveFigure($takes[0]['entry_id'], $row, $units);

                $wanted -= $units;
                $takes[0]['qty'] -= $units;

                if ($takes[0]['qty'] === 0) {
                    array_shift($takes);
                }
            }

            // Чего не досталось — потеряно целиком. Детали этой фигурки не
            // трогаем: так же помечают фигурку, которой нет в коробке.
            if ($wanted > 0) {
                $row->update(['lost_qty' => $wanted]);
            }
        }
    }

    /** Переносит из свободной фигурки сколько-то штук в строку набора. */
    private function moveFigure(int $entryId, Item $target, int $units): void
    {
        $source = Entry::findOrFail($entryId);
        $root = $source->roots()->firstOrFail();

        $this->carry($root, $target, $units, $root->qty);

        // Заметка свободной фигурки — её особая отметка — едет с ней и
        // становится комментарием строки в наборе. Две фигурки в одну строку
        // — обе заметки, через точку с запятой.
        if ($source->note !== null && trim($source->note) !== '') {
            $target->update(['note' => implode('; ', array_filter([$target->note, trim($source->note)]))]);
        }

        if ($units >= $root->qty) {
            $source->delete();

            return;
        }

        $this->shrink($root, $root->qty - $units, $root->qty);
    }

    /**
     * Переносит отметки недостачи деталей с источника на цель.
     *
     * Сколько штук фигурки уходит, такая доля строк и едет, но не больше
     * отмеченного: голова, потерянная у одной из двух одинаковых фигурок, едет
     * с той, что ушла, — какая из двух без головы, не записано, и честнее
     * признать отсутствие головы там, куда переложили, чем там, где её никто
     * не станет искать.
     */
    private function carry(Item $source, Item $target, int $units, int $sourceUnits): void
    {
        $targets = $target->children()->orderBy('id')->get();

        foreach ($source->children()->orderBy('id')->get() as $child) {
            $moved = min($child->lost_qty, intdiv($child->qty * $units, max(1, $sourceUnits)));

            $match = $targets->first(fn (Item $row) => $row->item_type === $child->item_type
                && $row->item_id === $child->item_id
                && (int) $row->color_id === (int) $child->color_id
                && $row->is_extra === $child->is_extra);

            if ($match !== null && $moved > 0) {
                $match->update(['lost_qty' => min($match->qty, $match->lost_qty + $moved)]);
            }

            // Ушедшее с фигуркой у источника больше не числится.
            $child->lost_qty -= $moved;
            $child->save();

            if ($match !== null) {
                $this->carry($child, $match, $units, $sourceUnits);
            }
        }
    }

    /** Уменьшает свободную фигурку на ушедшие штуки, вместе с её деталями. */
    private function shrink(Item $row, int $qty, int $old): void
    {
        $new = intdiv($row->qty * $qty, max(1, $old));

        $row->update(['qty' => $new, 'lost_qty' => min($row->lost_qty, $new)]);

        foreach ($row->children as $child) {
            $this->shrink($child, $qty, $old);
        }
    }
}
