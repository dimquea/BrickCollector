<?php

namespace App\Collection\Export;

use App\Support\Settings;
use Illuminate\Http\Response;

/**
 * Выгрузка в BSX — собственный формат BrickStore.
 *
 * BrickStore читает и обычный BrickLink XML, но только через «Импорт», и
 * открыть такой файл двойным щелчком нельзя. BSX — его родной документ: он
 * открывается как свой, хранит валюту и переживает переименования в каталоге,
 * потому что рядом с артикулом лежат названия.
 *
 * Поля и их правила сверены с исходниками BrickStore (схема BrickStoreXML.rnc
 * и documentio.cpp), и три из них стоят отдельного слова:
 *
 * — Condition обязателен не на словах: его отсутствие читается как «б/у».
 *   Состояния мы не храним, поэтому пишем «новое» всем: угадывать по тегам
 *   значило бы выдавать догадку за сведения.
 * — Price пишется с точкой и ровно тремя знаками; запятая читается как ноль.
 *   Цена у нас хранится на экземпляре целиком, а не на позиции, так что
 *   разложить её по строкам нечестно — пишем ноль.
 * — ItemTypeName не пишем вовсе: первая его буква перебивает ItemTypeID, и
 *   название, разошедшееся с кодом, сломало бы поиск предмета.
 */
final class BrickStoreBsx
{
    /**
     * @param  iterable<int|string, array{type: string, id: string, color?: int|null, qty: int, remarks?: string|null}>  $items
     */
    public static function inventory(iterable $items, ?string $currency = null): string
    {
        $currency = $currency ?? Settings::currency();

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<BrickStoreXML>',
            ' <Inventory Currency="'.self::escape($currency).'">',
        ];

        foreach ($items as $item) {
            $qty = (int) $item['qty'];

            // Позиция, которой ноль, в корзине не нужна: строка попадает сюда
            // и просто потому, что по ней записана недостача.
            if ($qty < 1) {
                continue;
            }

            $lines[] = '  <Item>';
            $lines[] = '   <ItemID>'.self::escape($item['id']).'</ItemID>';
            $lines[] = '   <ItemTypeID>'.self::escape($item['type']).'</ItemTypeID>';
            // Ноль — это «цвет неприменим», и у набора с фигуркой он такой же
            // осмысленный, как у детали её настоящий номер. Пропуск поля
            // оставил бы позицию вовсе без цвета.
            $lines[] = '   <ColorID>'.(int) ($item['color'] ?? 0).'</ColorID>';
            $lines[] = '   <Status>I</Status>';
            $lines[] = '   <Qty>'.$qty.'</Qty>';
            $lines[] = '   <Price>0.000</Price>';
            $lines[] = '   <Condition>N</Condition>';

            if (! empty($item['remarks'])) {
                $lines[] = '   <Remarks>'.self::escape($item['remarks']).'</Remarks>';
            }

            $lines[] = '  </Item>';
        }

        $lines[] = ' </Inventory>';
        $lines[] = '</BrickStoreXML>';

        return implode("\n", $lines)."\n";
    }

    /** Готовый файл: имя говорит, из какого раздела он. */
    public static function download(string $xml, string $section): Response
    {
        $name = 'brickcollector-'.$section.'-'.date('Y-m-d').'.bsx';

        return response($xml, 200, [
            'Content-Type' => 'application/x-brickstore-xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
