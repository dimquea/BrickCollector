<?php

namespace App\Collection\Export;

/**
 * Чего недостаёт в поддереве экземпляра — строками для выгрузки.
 *
 * Правило одно на всё дерево, на какой бы глубине ни стояла строка: деталь
 * идёт своей недостачей; фигурка, потерянная целиком, идёт строкой фигурки, а
 * её детали — нет, иначе она уехала бы в файл дважды; фигурка, у которой
 * пропало что-то одно, идёт деталями, которых не хватает. Поднабор и всё
 * прочее с собственным составом — так же, как фигурка.
 *
 * Отметка недостачи у фигурки и у её деталей хранится раздельно — так задумано,
 * чтобы фигурку можно было вернуть, — поэтому решать, что из двух выгружать,
 * приходится здесь.
 */
final class Shortage
{
    /**
     * @param  array<int, array<string, mixed>>  $lots  узлы EntryContents::tree()
     * @return array<string, array{type: string, id: string, color: int|null, qty: int}>
     */
    public static function of(array $lots): array
    {
        $rows = [];

        self::walk($lots, $rows);

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lots
     * @param  array<string, array{type: string, id: string, color: int|null, qty: int}>  $rows
     */
    private static function walk(array $lots, array &$rows): void
    {
        foreach ($lots as $lot) {
            // Альтернатива в коробке не лежит, и пропасть ей неоткуда. Парная
            // строка лежит: её недостачу считают и в разделе деталей.
            $held = $lot['counts'] || $lot['is_counterpart'];

            if ($held && $lot['lost_qty'] > 0) {
                $key = $lot['type'].'/'.$lot['item_id'].'/'.$lot['color_id'];

                $rows[$key] ??= [
                    'type' => $lot['type'],
                    'id' => $lot['item_id'],
                    // Цвет есть у детали и у части снаряжения; у фигурки и
                    // набора в выгрузке его нет вовсе.
                    'color' => $lot['type'] === 'P' || $lot['color_id'] > 0 ? (int) $lot['color_id'] : null,
                    'qty' => 0,
                ];

                $rows[$key]['qty'] += $lot['lost_qty'];
            }

            // Пропало целиком — его состав уже в файле, одной строкой.
            if ($held && $lot['lost_qty'] >= $lot['qty']) {
                continue;
            }

            // Деталь не раскладывается: у составной детали внутри её же
            // половинки, и докупают её целой.
            if ($lot['type'] !== 'P') {
                self::walk($lot['children'] ?? [], $rows);
            }
        }
    }
}
