<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Кнопки на чужие каталоги для открытого предмета.
 *
 * Шесть блоков засеяны миграцией, пользователь их только настраивает. Кнопка
 * появляется, если блок включён и заполнен паттерн под тип предмета: пустой
 * паттерн и есть признак «этот сайт про такое не знает». Так, у Rebrickable не
 * заполнены фигурки — у них своя нумерация, и наш артикул привёл бы в никуда.
 */
class ExternalLinks
{
    /** Какая колонка описывает какой тип предмета. */
    private const COLUMNS = [
        'S' => 'url_set',
        'M' => 'url_minifig',
        'P' => 'url_part',
    ];

    /**
     * @return array<int, array{label: string, url: string}>
     */
    public static function for(string $type, string $id, ?int $colorId = null): array
    {
        // Gear, книги и каталоги на чужих сайтах живут там же, где наборы:
        // отдельной нумерации у них нет.
        $column = self::COLUMNS[$type] ?? 'url_set';

        $rows = DB::table('ref_links')
            ->where('enabled', true)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->orderBy('sort')
            ->get(['code', 'label', $column.' as pattern']);

        return $rows
            ->map(fn ($row) => [
                'label' => $row->label ?: $row->code,
                'url' => self::fill($row->pattern, $type, $id, $colorId),
            ])
            ->all();
    }

    private static function fill(string $pattern, string $type, string $id, ?int $colorId): string
    {
        return strtr($pattern, [
            '{id}' => rawurlencode($id),
            // Номер без варианта: Brickset адресует набор как 75005, а не 75005-1.
            '{number}' => rawurlencode(preg_replace('/-\d+$/', '', $id)),
            '{type}' => $type,
            '{color}' => (string) ($colorId ?? 0),
        ]);
    }
}
