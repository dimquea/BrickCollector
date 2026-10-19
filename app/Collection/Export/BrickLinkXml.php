<?php

namespace App\Collection\Export;

use Illuminate\Http\Response;

/**
 * Выгрузка в BrickLink XML — тот же формат, которым приходит справочник.
 *
 * Документа два, и различаются они одним полем. Опись говорит «вот что у меня
 * есть» и считает штуки в QTY; список желаемого говорит «вот чего мне не
 * хватает» и просит не меньше MINQTY штук. Корень у обоих один и тот же —
 * INVENTORY, — так что по виду файла не догадаться, чем он окажется на той
 * стороне. Поэтому вызывающий выбирает не флаг, а метод: перепутать флаг легко,
 * а последствие — заказанные заново детали, которые и так лежат в коробке.
 *
 * Цвет осмыслен только у детали. У набора и фигурки его нет вовсе, и пустой
 * COLOR там был бы не «неизвестно», а «цвет номер ноль».
 *
 * Объявления <?xml …?> в файле нет намеренно: документация BrickLink требует
 * его убрать. Формат хоть и зовётся XML, но принимающая сторона ждёт документ,
 * начинающийся сразу с INVENTORY.
 */
final class BrickLinkXml
{
    /** Сколько знаков BrickLink оставляет примечанию. */
    private const REMARKS_LIMIT = 255;

    /**
     * @param  iterable<int|string, array{type: string, id: string, color?: int|null, qty: int, remarks?: string|null}>  $items
     */
    public static function inventory(iterable $items): string
    {
        return self::document($items, 'QTY');
    }

    /**
     * @param  iterable<int|string, array{type: string, id: string, color?: int|null, qty: int, remarks?: string|null}>  $items
     */
    public static function wanted(iterable $items): string
    {
        return self::document($items, 'MINQTY');
    }

    /**
     * Склад для BrickLink Mass-Upload: партии, которые выставляют в магазин.
     *
     * Тот же корень INVENTORY, но строка здесь — лот магазина: с ценой за штуку,
     * состоянием и, у набора, полнотой. Порядок и вид полей — как у BrickStore,
     * который пишет этот же формат: цена с точкой и тремя знаками, категория из
     * справочника рядом с номером.
     *
     * Валюты в формате нет вовсе: BrickLink читает цены в валюте магазина. Это
     * сказано у пункта меню — рублёвая цена в долларовом магазине молча станет
     * долларовой.
     *
     * @param  iterable<int, array<string, mixed>>  $lots  Stock::lots()
     */
    public static function massUpload(iterable $lots): string
    {
        $lines = ['<INVENTORY>'];

        foreach ($lots as $lot) {
            $lines[] = '  <ITEM>';
            $lines[] = '    <ITEMID>'.self::escape((string) $lot['id']).'</ITEMID>';
            $lines[] = '    <ITEMTYPE>'.self::escape((string) $lot['type']).'</ITEMTYPE>';
            $lines[] = '    <COLOR>'.(int) $lot['color'].'</COLOR>';

            if ($lot['category'] !== null) {
                $lines[] = '    <CATEGORY>'.(int) $lot['category'].'</CATEGORY>';
            }

            $lines[] = '    <QTY>'.(int) $lot['qty'].'</QTY>';
            $lines[] = '    <PRICE>'.self::money((float) $lot['price']).'</PRICE>';
            $lines[] = '    <CONDITION>'.($lot['used'] ? 'U' : 'N').'</CONDITION>';

            if ($lot['remarks'] !== null) {
                $lines[] = '    <REMARKS>'.self::escape(self::fit($lot['remarks'])).'</REMARKS>';
            }

            if ($lot['subcondition'] !== null) {
                $lines[] = '    <SUBCONDITION>'.$lot['subcondition'].'</SUBCONDITION>';
            }

            $lines[] = '  </ITEM>';
        }

        $lines[] = '</INVENTORY>';

        return implode("\n", $lines)."\n";
    }

    /** Цена так, как её читают BrickLink и BrickStore: точка и три знака. */
    public static function money(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    /** Готовый файл: имя говорит, из какого раздела он и чем является. */
    public static function download(string $xml, string $section, string $kind): Response
    {
        $name = 'brickcollector-'.$section.'-'.$kind.'-'.date('Y-m-d').'.xml';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /** @param iterable<int|string, array<string, mixed>> $items */
    private static function document(iterable $items, string $quantity): string
    {
        $lines = ['<INVENTORY>'];

        foreach ($items as $item) {
            $qty = (int) ($item['qty'] ?? 0);

            // Ноль штук — не строка выгрузки, а отсутствие строки. Взяться она
            // может законно: деталь попадает в список и тогда, когда в зачёте
            // не держится ни одной штукой, а только числится потерянной.
            if ($qty < 1) {
                continue;
            }

            $lines[] = '  <ITEM>';
            $lines[] = '    <ITEMTYPE>'.self::escape((string) $item['type']).'</ITEMTYPE>';
            $lines[] = '    <ITEMID>'.self::escape((string) $item['id']).'</ITEMID>';

            if (($item['color'] ?? null) !== null) {
                $lines[] = '    <COLOR>'.(int) $item['color'].'</COLOR>';
            }

            $lines[] = '    <'.$quantity.'>'.$qty.'</'.$quantity.'>';

            $remarks = trim((string) ($item['remarks'] ?? ''));

            if ($remarks !== '') {
                $lines[] = '    <REMARKS>'.self::escape(self::fit($remarks)).'</REMARKS>';
            }

            $lines[] = '  </ITEM>';
        }

        $lines[] = '</INVENTORY>';

        return implode("\n", $lines)."\n";
    }

    /**
     * Обрезает примечание по границе перечисления.
     *
     * Деталь может недоставать десяткам наборов, и список их номеров легко
     * перерастает отведённое поле. Обрубок посреди номера читался бы как другой
     * набор, поэтому режем по запятой и честно ставим многоточие.
     */
    private static function fit(string $remarks): string
    {
        if (mb_strlen($remarks) <= self::REMARKS_LIMIT) {
            return $remarks;
        }

        $cut = mb_substr($remarks, 0, self::REMARKS_LIMIT - 1);
        $lastComma = mb_strrpos($cut, ',');

        return ($lastComma === false ? $cut : mb_substr($cut, 0, $lastComma)).'…';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
