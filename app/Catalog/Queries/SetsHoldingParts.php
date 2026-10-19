<?php

namespace App\Catalog\Queries;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Наборы, в которых есть каждая из названных деталей.
 *
 * «Есть» — хотя бы одна штука в этом цвете где угодно в дереве набора: прямо
 * в описи, в фигурке, в поднаборе, сколь угодно глубоко. Так ищут набор по
 * горсти найденных деталей, и деталь, сидящая в фигурке, в коробке ровно так
 * же, как лежащая россыпью.
 *
 * Альтернатива не в счёт — её в коробке нет; парная строка тоже — это та же
 * деталь, описанная второй раз. Запасная в счёт: она лежит в коробке.
 * Составная деталь не раскрывается — рука внутри торса не ищется, — как и при
 * добавлении в коллекцию.
 *
 * Идём от детали вверх, а не от наборов вниз: индекс в описи стоит на ребёнке,
 * и подъём даже от самой частой детали — это десятки миллисекунд, а спуск по
 * двумстам тысячам предметов — минуты. Унарный плюс у parent_type не даёт
 * SQLite взять индекс по родителю: с ним планировщик на каждом шаге перебирал
 * всю опись, и запрос, работавший за 16 мс, не укладывался в две минуты.
 */
final class SetsHoldingParts
{
    /** Сколько деталей можно искать разом. */
    public const LIMIT = 10;

    /**
     * Разбирает отбор из адреса: «3001:11,3023:» — артикул и цвет, пустой цвет
     * значит «любой».
     *
     * Что разобрать нельзя, отбрасывается, а не отказывает: адрес правят руками
     * и пересылают, и опечатка в одной паре не повод потерять остальные.
     *
     * @return array<int, array{id: string, color: ?int}>
     */
    public static function parse(?string $raw): array
    {
        $parts = [];

        foreach (explode(',', (string) $raw) as $pair) {
            [$id, $colour] = array_pad(explode(':', trim($pair), 2), 2, '');
            $id = trim($id);
            $colour = trim($colour);

            if ($id === '' || mb_strlen($id) > 60 || ($colour !== '' && ! ctype_digit($colour))) {
                continue;
            }

            $parts[$id.':'.$colour] = ['id' => $id, 'color' => $colour === '' ? null : (int) $colour];
        }

        return array_slice(array_values($parts), 0, self::LIMIT);
    }

    /**
     * Сужает поиск справочника до наборов со всеми деталями.
     *
     * @param  array<int, array{id: string, color: ?int}>  $parts
     */
    public static function apply(Builder|EloquentBuilder $query, array $parts, string $typeColumn, string $idColumn): void
    {
        if ($parts === []) {
            return;
        }

        $ctes = [];
        $selects = [];
        $bindings = [];

        foreach ($parts as $index => $part) {
            $name = 'held'.$index;

            $ctes[] = "{$name}(type, id) AS (
                SELECT parent_type, parent_id FROM bl_inventory
                 WHERE child_type = 'P' AND child_id = ?".($part['color'] === null ? '' : ' AND color_id = ?')."
                   AND +parent_type IN ('S', 'M') AND is_alternate = 0 AND is_counterpart = 0
                UNION
                SELECT inv.parent_type, inv.parent_id FROM {$name} up
                  JOIN bl_inventory inv ON inv.child_type = up.type AND inv.child_id = up.id
                 WHERE +inv.parent_type IN ('S', 'M') AND inv.is_alternate = 0 AND inv.is_counterpart = 0
            )";

            $bindings[] = $part['id'];

            if ($part['color'] !== null) {
                $bindings[] = $part['color'];
            }

            $selects[] = "SELECT id FROM {$name} WHERE type = 'S'";
        }

        $sql = 'WITH RECURSIVE '.implode(', ', $ctes).' '.implode(' INTERSECT ', $selects);

        $query->where($typeColumn, 'S')->whereRaw("{$idColumn} IN ({$sql})", $bindings);
    }
}
