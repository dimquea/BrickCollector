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

            // Позиция, которой ноль, в документе не нужна: строка попадает сюда
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

    /**
     * Склад: лоты с ценой, состоянием и заметкой — то, что BrickStore заливает
     * в магазин.
     *
     * В отличие от простого документа здесь каждая строка — экземпляр
     * коллекции. Наш номер партии едет в MarkerText: это метка строки, видна
     * только в BrickStore и в магазин не уходит, — чтобы там было понятно, какая
     * это партия у нас. LotID для этого не годится: это номер лота на
     * BrickLink, и BrickStore по нему открывает страницу в магазине и шлёт
     * правки при Mass-Update.
     *
     * Названия предмета и цвета пишутся рядом с номерами: BrickStore берёт их,
     * только если номер не нашёлся, — так переименование в каталоге не
     * превращает строку в загадку. ItemTypeName по-прежнему не пишем.
     *
     * @param  iterable<int, array<string, mixed>>  $lots  Stock::lots()
     */
    public static function stock(iterable $lots, ?string $currency = null): string
    {
        $currency = $currency ?? Settings::currency();

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<BrickStoreXML>',
            ' <Inventory Currency="'.self::escape($currency).'">',
        ];

        foreach ($lots as $lot) {
            $lines[] = '  <Item>';
            $lines[] = '   <ItemID>'.self::escape((string) $lot['id']).'</ItemID>';
            $lines[] = '   <ItemTypeID>'.self::escape((string) $lot['type']).'</ItemTypeID>';
            $lines[] = '   <ColorID>'.(int) $lot['color'].'</ColorID>';
            $lines[] = '   <Status>I</Status>';
            $lines[] = '   <Qty>'.(int) $lot['qty'].'</Qty>';
            $lines[] = '   <Price>'.BrickLinkXml::money((float) $lot['price']).'</Price>';
            $lines[] = '   <Condition>'.($lot['used'] ? 'U' : 'N').'</Condition>';

            if ($lot['subcondition'] !== null) {
                $lines[] = '   <SubCondition>'.$lot['subcondition'].'</SubCondition>';
            }

            if ($lot['remarks'] !== null) {
                $lines[] = '   <Remarks>'.self::escape($lot['remarks']).'</Remarks>';
            }

            $lines[] = '   <MarkerText>#'.(int) $lot['entry_id'].'</MarkerText>';

            if ($lot['name'] !== null) {
                $lines[] = '   <ItemName>'.self::escape($lot['name']).'</ItemName>';
            }

            if ($lot['color_name'] !== null && (int) $lot['color'] !== 0) {
                $lines[] = '   <ColorName>'.self::escape($lot['color_name']).'</ColorName>';
            }

            if ($lot['category'] !== null) {
                $lines[] = '   <CategoryID>'.(int) $lot['category'].'</CategoryID>';
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
