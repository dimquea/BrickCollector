<?php

namespace App\Collection\Export;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Какой файл просят и чем его собрать.
 *
 * Видов пять: опись BrickLink, список желаемого BrickLink, документ BrickStore
 * и склад — в BrickStore и для BrickLink Mass-Upload.
 * Первые два отличаются не только разметкой, но и смыслом строк — «сколько
 * есть» против «сколько не хватает», — поэтому строки собирает вызывающий, а
 * здесь остаётся выбор вида и сборка документа.
 *
 * Вид приходит в адресе, потому что выгрузка — обычная ссылка: её открывают в
 * новой вкладке, сохраняют в закладки и пересылают.
 */
final class Export
{
    private const KINDS = ['inventory', 'wanted', 'bsx', 'stock-bsx', 'stock-xml'];

    /** Склад: строки — экземпляры, а не итоги, и собирает их Stock. */
    public const STOCK = ['stock-bsx', 'stock-xml'];

    /** Вид из адреса; что-то неизвестное — не повод отказывать, берём обычное. */
    public static function kind(Request $request, string $fallback = 'inventory'): string
    {
        $asked = (string) $request->query('as', '');

        return in_array($asked, self::KINDS, true) ? $asked : $fallback;
    }

    public static function isStock(string $kind): bool
    {
        return in_array($kind, self::STOCK, true);
    }

    /**
     * Склад из экземпляров коллекции.
     *
     * @param  iterable<int, int>  $entryIds
     */
    public static function stock(string $kind, iterable $entryIds, string $section): Response
    {
        $lots = Stock::lots($entryIds);

        return $kind === 'stock-xml'
            ? BrickLinkXml::download(BrickLinkXml::massUpload($lots), $section, 'stock')
            : BrickStoreBsx::download(BrickStoreBsx::stock($lots), $section.'-stock');
    }

    /**
     * @param  iterable<int|string, array{type: string, id: string, color?: int|null, qty: int, remarks?: string|null}>  $rows
     */
    public static function respond(string $kind, iterable $rows, string $section): Response
    {
        return match ($kind) {
            'bsx' => BrickStoreBsx::download(BrickStoreBsx::inventory($rows), $section),
            'wanted' => BrickLinkXml::download(BrickLinkXml::wanted($rows), $section, 'wanted'),
            default => BrickLinkXml::download(BrickLinkXml::inventory($rows), $section, 'inventory'),
        };
    }
}
