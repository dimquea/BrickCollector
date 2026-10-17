<?php

namespace App\Collection\Picking;

/**
 * Что нужно, чтобы собрать предмет справочника: детали и фигурки.
 *
 * Детали — всё засчитываемое в дереве, кроме того, что внутри фигурок:
 * фигурку берут целиком, а не собирают из головы и ног. Поднаборы
 * раскрываются, и количества перемножаются вниз, как и при добавлении в
 * коллекцию — иначе коробка из шести пакетов просила бы деталей на один.
 *
 * Запасные, альтернативы и парные к ним в нужное не входят: без них набор
 * полон, а альтернативы и вовсе нет в коробке.
 *
 * Одинаковые пары «деталь + цвет» складываются: в свободных они лежат одной
 * кучей, и спрашивать у кучи дважды бессмысленно.
 */
final class Needs
{
    /**
     * @param  array<string, array{id: string, color_id: int, name: string, color_name: ?string, color_rgb: ?string, qty: int}>  $parts
     * @param  array<string, array{id: string, name: string, image_color_id: int, qty: int}>  $figures
     * @param  array<string, array{id: string, color_id: int, name: string, color_name: ?string, color_rgb: ?string, qty: int}>  $spares
     */
    private function __construct(
        public readonly array $parts,
        public readonly array $figures,
        public readonly array $spares,
    ) {}

    /** @param  array<int, array<string, mixed>>  $tree  ItemInventory::tree() */
    public static function of(array $tree): self
    {
        $parts = [];
        $figures = [];
        $spares = [];

        $walk = function (array $nodes, int $multiplier) use (&$walk, &$parts, &$figures, &$spares): void {
            foreach ($nodes as $node) {
                $qty = $node['qty'] * $multiplier;

                // Запасная в нужное не входит, но в коробке лежит: добавление
                // возьмёт её из свободных после нужного, а иначе набор,
                // добавленный и тут же расформированный, родил бы деталей из
                // ничего.
                if ($node['is_extra'] && $node['type'] === 'P' && ! $node['is_alternate'] && ! $node['is_counterpart']) {
                    self::add($spares, $node, $qty);

                    continue;
                }

                // Не засчитанное уводит за собой всё поддерево: в пакете-
                // альтернативе нет и фигурки, что внутри него.
                if ($node['is_extra'] || $node['is_alternate'] || $node['is_counterpart']) {
                    continue;
                }

                if ($node['type'] === 'P') {
                    self::add($parts, $node, $qty);
                } elseif ($node['type'] === 'M') {
                    $figures[$node['id']] ??= [
                        'id' => $node['id'],
                        'name' => $node['name'],
                        'image_color_id' => (int) $node['image_color_id'],
                        'qty' => 0,
                    ];
                    $figures[$node['id']]['qty'] += $qty;
                } elseif ($node['type'] === 'S') {
                    $walk($node['children'], $qty);
                }
                // Снаряжение, книга, каталог — не из чего подбирать: это вещи
                // целиком, и при добавлении они пишутся как есть.
            }
        };

        $walk($tree, 1);

        ksort($parts);
        ksort($figures);
        ksort($spares);

        return new self($parts, $figures, $spares);
    }

    /** @param  array<string, array<string, mixed>>  $bucket */
    private static function add(array &$bucket, array $node, int $qty): void
    {
        $key = self::key($node['id'], $node['color_id']);

        $bucket[$key] ??= [
            'id' => $node['id'],
            'color_id' => (int) $node['color_id'],
            'name' => $node['name'],
            'color_name' => $node['color_name'],
            'color_rgb' => $node['color_rgb'],
            'qty' => 0,
        ];
        $bucket[$key]['qty'] += $qty;
    }

    public static function key(string $id, int $colorId): string
    {
        return $id.'/'.$colorId;
    }
}
