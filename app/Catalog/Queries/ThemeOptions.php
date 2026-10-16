<?php

namespace App\Catalog\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Темы, которые можно предложить в отборе.
 *
 * Тема — узел дерева, которое справочник присылает строкой: в его файлах
 * написано «Star Wars / Ultimate Collector Series / Star Wars Episode 4/5/6»,
 * а импорт режет её на уровни. Отбор по любому узлу уже умеет захватывать всё
 * поддерево, поэтому выбор глубокого узла — это просто другой theme_id, а не
 * второй параметр: ссылка остаётся ссылкой.
 *
 * Корни и подтемы считаются по одним и тем же правилам для справочника и для
 * разделов коллекции, разница только в том, из чего выбирать: справочник знает
 * всё дерево, а раздел — лишь пути того, чем владеют. Предлагать там тему, за
 * которой ничего нет, значит звать человека в пустой список.
 */
final class ThemeOptions
{
    /**
     * Верхний уровень дерева.
     *
     * @param  Collection<int, string>|null  $paths  пути представленного; null — весь справочник
     * @return array<int, array<string, mixed>>
     */
    public static function roots(?Collection $paths = null): array
    {
        $query = DB::table('bl_themes')->where('depth', 0);

        if ($paths !== null) {
            $roots = $paths->filter()->map(self::root(...))->unique()->values();

            if ($roots->isEmpty()) {
                return [];
            }

            $query->whereIn('path', $roots);
        }

        return $query->orderBy('path')
            ->get(['id', 'path'])
            ->map(fn (object $row) => (array) $row)
            ->all();
    }

    /**
     * Подтемы того корня, в котором сейчас находятся.
     *
     * Плоским списком, с путём относительно корня: внутри одного корня 403
     * пары подтем с одинаковыми именами — «Star Wars Episode 4/5/6» есть и под
     * Boost, и под Buildable Figures, и под Ultimate Collector Series, — и
     * короткое имя заставляло бы выбирать наугад.
     *
     * Для раздела коллекции список собирается из путей того, чем владеют,
     * вместе с промежуточными уровнями: наборов ровно на «Star Wars /
     * Buildable Figures» не стоит ни одного, но выбрать все семь, что лежат
     * под ним, хочется.
     *
     * @param  Collection<int, string>|null  $paths  пути представленного; null — весь справочник
     * @return array<int, array<string, mixed>>
     */
    public static function under(?int $themeId, ?Collection $paths = null): array
    {
        $root = self::rootPath($themeId);

        if ($root === null) {
            return [];
        }

        $prefix = $root.' / ';

        if ($paths === null) {
            $wanted = DB::table('bl_themes')
                ->where('path', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')
                ->orderBy('path')
                ->get(['id', 'path']);
        } else {
            $deep = $paths
                ->filter(fn (string $path) => str_starts_with($path, $prefix))
                ->flatMap(self::prefixes(...))
                ->filter(fn (string $path) => str_starts_with($path, $prefix))
                ->unique()
                ->values();

            if ($deep->isEmpty()) {
                return [];
            }

            $wanted = DB::table('bl_themes')->whereIn('path', $deep)->orderBy('path')->get(['id', 'path']);
        }

        return $wanted
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'path' => substr($row->path, strlen($prefix)),
            ])
            ->all();
    }

    /**
     * Корень выбранной темы — то, что показывает верхний список, когда выбран
     * глубокий узел. Ссылку с ним присылают со стороны, и страница должна
     * открыться в том же виде, в каком её отправили.
     */
    public static function rootId(?int $themeId): ?int
    {
        $root = self::rootPath($themeId);

        return $root === null
            ? null
            : DB::table('bl_themes')->where('path', $root)->where('depth', 0)->value('id');
    }

    private static function rootPath(?int $themeId): ?string
    {
        if ($themeId === null) {
            return null;
        }

        $path = DB::table('bl_themes')->where('id', $themeId)->value('path');

        return $path === null ? null : self::root($path);
    }

    private static function root(string $path): string
    {
        return explode(' / ', $path)[0];
    }

    /** @return array<int, string> «A», «A / B», «A / B / C» */
    private static function prefixes(string $path): array
    {
        $segments = explode(' / ', $path);
        $walked = [];

        return array_map(function (string $segment) use (&$walked) {
            $walked[] = $segment;

            return implode(' / ', $walked);
        }, $segments);
    }
}
