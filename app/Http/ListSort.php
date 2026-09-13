<?php

namespace App\Http;

use Illuminate\Http\Request;

/**
 * Порядок списка, прочитанный из строки запроса.
 *
 * Живёт в адресе рядом с фильтрами, а не в настройках: выбранный порядок
 * должен переживать и фильтрацию, и переход по страницам, и пересылку ссылки
 * другому человеку — «наборы 2019 года, от дорогих к дешёвым» это одна ссылка,
 * а не последовательность кликов.
 *
 * Неизвестное поле не ошибка и не повод отбросить страницу: адрес правят
 * руками, и раздел просто показывает свой обычный порядок. Он же работает,
 * когда параметра нет вовсе, поэтому старые ссылки ничего не меняют.
 */
final class ListSort
{
    /**
     * @param  array<int, string>  $allowed  ключи, которые понимает раздел
     * @return array{by: string, dir: string}
     */
    public static function read(Request $request, array $allowed, string $default, string $defaultDir = 'asc'): array
    {
        $by = (string) $request->query('sort', '');
        $by = in_array($by, $allowed, true) ? $by : $default;

        $dir = (string) $request->query('dir', '');

        // Направление спрашивается только вместе с полем: указывать его для
        // порядка по умолчанию не о чем — он и так задан разделом.
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = $by === $default ? $defaultDir : 'asc';
        }

        return ['by' => $by, 'dir' => $dir];
    }
}
