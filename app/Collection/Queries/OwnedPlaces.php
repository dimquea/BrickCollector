<?php

namespace App\Collection\Queries;

use App\Collection\AssemblyImages;
use Illuminate\Support\Facades\DB;

/**
 * Где предмет справочника лежит в коллекции.
 *
 * Карточка справочника рассказывает о предмете вообще — что это, из чего
 * состоит, куда входит. Вопрос «а он у меня есть, и где именно» до сих пор
 * задавался только из разделов коллекции, то есть ответ надо было знать
 * заранее, чтобы его получить.
 *
 * Ответ разложен по разделам, в которые ведут строки: отдельные партии,
 * экземпляры, наборы, фигурки, сборки. Пустые разделы не возвращаются вовсе —
 * вкладка, за которой ничего нет, обещает больше, чем показывает.
 *
 * Своего кода здесь немного: про деталь все четыре стороны вопроса уже умеет
 * PartPlaces, про фигурку — MinifigurePlaces. Остаётся привести строки к
 * одному виду и ответить про набор, у которого страницы «где он» не было.
 */
class OwnedPlaces
{
    public function __construct(
        private readonly string $type,
        private readonly string $id,
        /** Цвет карточки; у набора и фигурки его нет. */
        private readonly int $colorId = 0,
    ) {}

    /**
     * Разделы со строками, или null — если предмета в коллекции нет.
     *
     * @return array<int, array{key: string, rows: array<int, array<string, mixed>>}>|null
     */
    public function groups(): ?array
    {
        $groups = array_values(array_filter(
            $this->type === 'P' ? $this->part() : $this->whole(),
            fn (array $group) => $group['rows'] !== [],
        ));

        return $groups === [] ? null : $groups;
    }

    /** @return array<int, array{key: string, rows: array<int, array<string, mixed>>}> */
    private function part(): array
    {
        $places = new PartPlaces($this->id, $this->colorId);
        $images = app(AssemblyImages::class);

        return [
            ['key' => 'loose', 'rows' => $places->loose()->map(fn (array $lot) => [
                'kind' => 'lot',
                'entry_id' => $lot['entry_id'],
                'type' => 'P',
                'item_id' => $this->id,
                'image_color_id' => $this->colorId,
                // У партии нет названия: её отличают день и место, а предмет у
                // всех строк здесь один и тот же.
                'title' => $lot['date'],
                'subtitle' => implode(' · ', array_filter([$lot['storage'], $lot['source']])) ?: null,
                'qty' => $lot['qty'],
                'lost' => $lot['lost'],
            ])->all()],

            ['key' => 'entries', 'rows' => $places->inEntries()->map(fn (array $row) => [
                'kind' => 'entry',
                'entry_id' => $row['entry_id'],
                'type' => $row['type'],
                'item_id' => $row['item_id'],
                'image_color_id' => $row['image_color_id'],
                'title' => $row['name'],
                'subtitle' => $row['item_id'],
                'qty' => $row['qty'],
                'lost' => $row['lost'],
            ])->all()],

            ['key' => 'minifigures', 'rows' => $places->inMinifigures()->map(fn (object $row) => [
                // Строка про фигурку, а не про экземпляр: одна и та же фигурка
                // попадается в нескольких наборах, и ведёт она в раздел
                // фигурок, где они как раз сведены вместе.
                'kind' => 'figure',
                'entry_id' => null,
                'type' => 'M',
                'item_id' => $row->item_id,
                'image_color_id' => (int) $row->image_color_id,
                'title' => $row->name,
                'subtitle' => $row->item_id,
                'qty' => (int) $row->qty,
                'lost' => (int) $row->lost,
                'counts' => (bool) $row->counts,
            ])->all()],

            ['key' => 'assemblies', 'rows' => $places->assemblies()->map(fn (array $row) => [
                'kind' => 'assembly',
                'entry_id' => $row['entry_id'],
                'type' => null,
                'item_id' => null,
                'has_image' => $images->has($row['entry_id']),
                'title' => $row['name'],
                'subtitle' => null,
                'qty' => $row['qty'],
                'lost' => $row['lost'],
            ])->all()],
        ];
    }

    /**
     * Набор, фигурка и всё прочее, чем владеют целиком.
     *
     * Две стороны: свои экземпляры и то, внутри чего предмет лежит. Второе
     * бывает и у набора — поднабор внутри большого, — и у фигурки, которая
     * чаще всего только так и достаётся.
     *
     * @return array<int, array{key: string, rows: array<int, array<string, mixed>>}>
     */
    private function whole(): array
    {
        return [
            ['key' => 'copies', 'rows' => $this->copies()],
            ['key' => 'entries', 'rows' => $this->builtInto()],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function copies(): array
    {
        return DB::table('collection_entries as e')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'e.item_type')
                ->on('i.id', '=', 'e.item_id'))
            ->leftJoin('collection_items as ci', fn ($join) => $join
                ->on('ci.entry_id', '=', 'e.id')
                ->whereColumn('ci.item_type', 'e.item_type')
                ->whereColumn('ci.item_id', 'e.item_id')
                ->whereNull('ci.parent_id'))
            ->where('e.item_type', $this->type)
            ->where('e.item_id', $this->id)
            ->orderByRaw('COALESCE(e.acquired_at, e.created_at)')
            ->orderBy('e.id')
            ->get([
                'e.id as entry_id',
                'e.acquired_at',
                'e.created_at',
                DB::raw('COALESCE(i.name, e.item_id) as name'),
                DB::raw('COALESCE(i.image_color_id, 0) as image_color_id'),
                DB::raw('COALESCE(ci.qty, 1) as qty'),
                DB::raw('COALESCE(ci.lost_qty, 0) as lost'),
            ])
            ->map(fn (object $row) => [
                'kind' => 'entry',
                'entry_id' => (int) $row->entry_id,
                'type' => $this->type,
                'item_id' => $this->id,
                'image_color_id' => (int) $row->image_color_id,
                'title' => $row->name,
                // Экземпляры одного предмета отличает день приобретения:
                // название у них одно на всех.
                'subtitle' => substr((string) ($row->acquired_at ?? $row->created_at), 0, 10) ?: null,
                'qty' => (int) $row->qty,
                'lost' => (int) $row->lost,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function builtInto(): array
    {
        return DB::table('collection_items as ci')
            ->join('collection_entries as e', 'e.id', '=', 'ci.entry_id')
            ->leftJoin('bl_items as i', fn ($join) => $join
                ->on('i.type', '=', 'e.item_type')
                ->on('i.id', '=', 'e.item_id'))
            ->where('ci.item_type', $this->type)
            ->where('ci.item_id', $this->id)
            // Внутри чего-то, а не сам по себе. Корневую строку собственного
            // экземпляра отличает не отсутствие родителя — минифигурки набора
            // тоже лежат в его корне, — а то, что экземпляр и есть этот предмет.
            ->whereNot(fn ($where) => $where
                ->whereNull('ci.parent_item_type')
                ->where('e.item_type', $this->type)
                ->where('e.item_id', $this->id))
            ->groupBy('e.id')
            ->orderBy('name')
            ->get([
                'e.id as entry_id',
                'e.item_type as type',
                'e.item_id',
                'e.name as entry_name',
                DB::raw('COALESCE(i.name, e.item_id) as name'),
                DB::raw('COALESCE(i.image_color_id, 0) as image_color_id'),
                DB::raw('SUM(ci.qty) as qty'),
                DB::raw('SUM(ci.lost_qty) as lost'),
            ])
            ->map(fn (object $row) => [
                'kind' => 'entry',
                'entry_id' => (int) $row->entry_id,
                'type' => $row->type,
                'item_id' => $row->item_id,
                'image_color_id' => (int) $row->image_color_id,
                'title' => $row->entry_name ?? $row->name,
                'subtitle' => $row->item_id,
                'qty' => (int) $row->qty,
                'lost' => (int) $row->lost,
            ])
            ->all();
    }
}
