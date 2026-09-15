<?php

namespace App\Http\Controllers;

use App\Catalog\Models\Item;
use App\Collection\Actions\AddToCollection;
use App\Collection\Import\BrickLinkFile;
use App\Collection\Models\Entry;
use App\Collection\Models\Source;
use App\Collection\Models\Storage;
use App\Collection\Models\Tag;
use App\Collection\Models\Wish;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Импорт из файла BrickLink XML.
 *
 * Разбор и добавление разнесены нарочно. Файл сначала читается и показывается —
 * что в нём, чего мы не знаем, что похоже на запасные детали, — и только потом,
 * по отдельному нажатию, из отмеченных строк создаются записи. Между этими
 * шагами в базе не появляется ничего: человек должен увидеть, на что
 * соглашается, прежде чем соглашаться.
 */
class ImportController extends Controller
{
    /** Что коллекция умеет держать: у остальных типов нет своего раздела. */
    private const HOLDABLE = [...SetsController::TYPES, 'M', 'P'];

    public function index(): Response
    {
        return Inertia::render('Import/Index', [
            // Статусов здесь нет: коробка и инструкция — свойство экземпляра,
            // а не строки файла, и разбирать их при импорте нечем.
            'dictionaries' => [
                'sources' => Source::where('is_active', true)->orderBy('sort')->get(['id', 'name']),
                'storages' => Storage::where('is_active', true)->orderBy('sort')->get(['id', 'name']),
                'tags' => Tag::orderBy('sort')->get(['id', 'name', 'color']),
                'statuses' => [],
            ],
            'currency' => Settings::currency(),
            'limit' => BrickLinkFile::LIMIT,
        ]);
    }

    /** Читает файл и показывает, что в нём. В базу не пишется ничего. */
    public function parse(Request $request, BrickLinkFile $file): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:8192'],
        ]);

        $found = $file->read((string) $request->file('file')->get());

        if ($found === null) {
            return response()->json(['message' => __('app.import.unreadable')], 422);
        }

        return response()->json([
            'rows' => $this->describe($found['items']),
            'wanted' => $found['wanted'],
            'total' => $found['total'],
        ]);
    }

    /**
     * Создаёт отмеченное.
     *
     * Названия и картинки из запроса не берутся вовсе: строке верим только в
     * том, что нельзя вывести самим, — тип, артикул, цвет, количество и мета.
     * Остальное у нас есть в справочнике, и доверять этому присланному значило
     * бы позволить записать в коллекцию что угодно под любым именем.
     */
    public function store(Request $request, AddToCollection $add): JsonResponse
    {
        $meta = [
            'nullable', 'array',
        ];

        $validated = $request->validate([
            'destination' => ['required', 'string', 'in:collection,wishlist'],
            'rows' => ['required', 'array', 'min:1', 'max:'.BrickLinkFile::LIMIT],
            'rows.*.type' => ['required', 'string', 'size:1'],
            'rows.*.id' => ['required', 'string', 'max:120'],
            'rows.*.color_id' => ['nullable', 'integer'],
            'rows.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'rows.*.meta' => $meta,
            'meta' => $meta,
            ...$this->metaRules('meta'),
            ...$this->metaRules('rows.*.meta'),
        ]);

        if ($validated['destination'] === 'wishlist') {
            return response()->json($this->wish($validated['rows']));
        }

        return response()->json($this->collect($validated['rows'], $validated['meta'] ?? [], $add));
    }

    /**
     * Правила одного набора меты — общего и построчного.
     *
     * @return array<string, array<int, string>>
     */
    private function metaRules(string $prefix): array
    {
        return [
            $prefix.'.acquired_at' => ['nullable', 'date'],
            // Целое в минимальных единицах: дробей приложение не знает нигде.
            $prefix.'.price' => ['nullable', 'integer', 'min:0'],
            $prefix.'.source_id' => ['nullable', 'integer', 'exists:ref_sources,id'],
            $prefix.'.storage_id' => ['nullable', 'integer', 'exists:ref_storages,id'],
            $prefix.'.note' => ['nullable', 'string', 'max:5000'],
            $prefix.'.tag_ids' => ['nullable', 'array'],
            $prefix.'.tag_ids.*' => ['integer', 'exists:ref_tags,id'],
        ];
    }

    /**
     * Добавляет отмеченное в коллекцию.
     *
     * Набор и фигурка с количеством больше единицы дают столько же записей:
     * три одинаковых набора — это три набора с тремя ценами, тремя местами
     * хранения и тремя историями. Деталь — одна новая партия: поступление со
     * своей датой и ценой, и сливать его со старой партией значило бы эти
     * сведения потерять.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $shared
     * @return array<string, int>
     */
    private function collect(array $rows, array $shared, AddToCollection $add): array
    {
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $shared, $add, &$created, &$skipped): void {
            foreach ($rows as $row) {
                $item = Item::where('type', $row['type'])->where('id', $row['id'])->first();

                if ($item === null || ! in_array($item->type, self::HOLDABLE, true)) {
                    $skipped++;

                    continue;
                }

                // Построчное перебивает общее поле за полем: заданная в строке
                // цена не должна заодно стирать общий источник.
                $meta = array_replace($shared, array_filter(
                    $row['meta'] ?? [],
                    static fn ($value) => $value !== null,
                ));

                $copies = $item->type === 'P' ? 1 : $row['qty'];
                $meta['qty'] = $item->type === 'P' ? $row['qty'] : 1;
                $meta['color_id'] = $row['color_id'] ?? (int) $item->image_color_id;

                for ($copy = 0; $copy < $copies; $copy++) {
                    $entry = $add->handle($item, $meta);

                    if (! empty($meta['tag_ids'])) {
                        $entry->tags()->sync($meta['tag_ids']);
                    }

                    $created++;
                }
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Кладёт отмеченное в желаемое.
     *
     * Количества у желания нет: хотят вещь, а не пять её штук, — поэтому
     * повторы в файле схлопываются в одно желание. Меты у желания тоже нет:
     * цена и место хранения описывают то, чем уже владеешь.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function wish(array $rows): array
    {
        $wished = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $item = Item::where('type', $row['type'])->where('id', $row['id'])->first();

            if ($item === null) {
                $skipped++;

                continue;
            }

            $colour = $item->type === 'P'
                ? (int) ($row['color_id'] ?? $item->image_color_id)
                : 0;

            $wish = Wish::firstOrCreate([
                'item_type' => $item->type,
                'item_id' => $item->id,
                'color_id' => $colour,
            ]);

            if ($wish->wasRecentlyCreated) {
                $wished++;
            }
        }

        return ['wished' => $wished, 'skipped' => $skipped];
    }

    /**
     * Дополняет строки тем, что знает справочник.
     *
     * Двумя запросами на весь файл, а не запросом на строку: в описи набора
     * строк бывает под тысячу.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function describe(array $rows): array
    {
        $known = Item::whereIn('id', collect($rows)->pluck('id')->unique()->values())
            ->get(['type', 'id', 'name', 'image_color_id'])
            ->keyBy(fn (Item $item) => $item->type.'/'.$item->id);

        $colours = DB::table('bl_colors')
            ->whereIn('id', collect($rows)->pluck('color_id')->filter()->unique()->values())
            ->get(['id', 'name', 'rgb'])
            ->keyBy('id');

        return collect($rows)->map(function (array $row) use ($known, $colours) {
            $item = $known->get($row['type'].'/'.$row['id']);
            $colour = $row['color_id'] === null ? null : $colours->get($row['color_id']);

            return $row + [
                'name' => $item->name ?? null,
                'image_color_id' => (int) ($item->image_color_id ?? 0),
                'color_name' => $colour->name ?? null,
                'color_rgb' => $colour->rgb ?? null,
                // Чего справочник не знает и чего коллекция не держит — видно,
                // но не отмечаемо: обещать страницу, которой не будет, нечестно.
                'known' => $item !== null,
                'holdable' => $item !== null && in_array($item->type, self::HOLDABLE, true),
            ];
        })->all();
    }
}
