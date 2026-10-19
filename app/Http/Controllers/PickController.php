<?php

namespace App\Http\Controllers;

use App\Catalog\Models\Item as CatalogItem;
use App\Catalog\Queries\ItemInventory;
use App\Collection\Actions\AddFromLoose;
use App\Collection\Export\Export;
use App\Collection\Picking\Availability;
use App\Collection\Picking\LoosePlan;
use App\Collection\Picking\Needs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * «Подобрать из коллекции» на карточке набора или фигурки в справочнике.
 *
 * Сколько нужного уже есть — везде, в наборах, в свободных; выгрузка того,
 * чего не хватило; отчёт о том, какие свободные партии пойдут в дело; и само
 * добавление со списанием из свободных.
 *
 * Брать можно только из свободных. Деталь из другого набора — часть того
 * набора, и забрать её значит молча сделать его некомплектным.
 */
class PickController extends Controller
{
    /** Чем можно подбирать: у этого есть состав, и его раскладывают в коллекцию. */
    private const TYPES = ['S', 'M'];

    private const SCOPES = ['all', 'sets', 'loose'];

    public function __construct(private readonly ItemInventory $inventory) {}

    public function show(string $type, string $id): JsonResponse
    {
        $needs = $this->needs($type, $id);
        $parts = Availability::parts($needs);
        $figures = Availability::figures($needs);

        return response()->json([
            'parts' => array_values(array_map(fn (array $need, string $key) => $need + [
                'key' => $key,
                'available' => $parts[$key],
            ], $needs->parts, array_keys($needs->parts))),
            'figures' => array_values(array_map(fn (array $need) => $need + [
                'available' => $figures[$need['id']],
            ], $needs->figures)),
            // Сколько разных партий пойдёт в дело — это знает только план; у
            // деталей он от отметок не зависит, у фигурок окно считает само.
            'lots' => count(array_unique(array_column(
                array_filter(LoosePlan::make($needs)->takes, fn (array $take) => $take['type'] === 'P'),
                'entry_id',
            ))),
        ]);
    }

    /**
     * Чего не хватило — в выбранном месте.
     *
     * Отметка у фигурки говорит «возьми свободную», и только о свободных:
     * неотмеченная свободная — как будто её нет, а фигурка в другом наборе в
     * режимах «Везде» и «В наборах» есть независимо от отметки.
     */
    public function export(Request $request, string $type, string $id): Response
    {
        $needs = $this->needs($type, $id);
        $scope = in_array($request->query('scope'), self::SCOPES, true) ? $request->query('scope') : 'loose';
        $chosen = $this->chosen($request);

        $parts = Availability::parts($needs);
        $figures = Availability::figures($needs);

        $rows = [];

        foreach ($needs->parts as $key => $need) {
            $rows[] = [
                'type' => 'P',
                'id' => $need['id'],
                'color' => $need['color_id'],
                'qty' => max(0, $need['qty'] - $parts[$key][$scope]),
                'remarks' => $id,
            ];
        }

        foreach ($needs->figures as $figureId => $need) {
            $loose = in_array($figureId, $chosen, true) ? $figures[$figureId]['loose'] : 0;

            $held = match ($scope) {
                'all' => $figures[$figureId]['sets'] + $loose,
                'sets' => $figures[$figureId]['sets'],
                default => $loose,
            };

            $rows[] = ['type' => 'M', 'id' => $figureId, 'qty' => max(0, $need['qty'] - $held), 'remarks' => $id];
        }

        // Ноль штук — не строка, а отсутствие строки: выгрузка их и так
        // пропускает, но документу BrickStore лишние строки ни к чему.
        $rows = array_values(array_filter($rows, fn (array $row) => $row['qty'] > 0));

        return Export::respond(Export::kind($request, 'wanted'), $rows, 'pick-'.$id);
    }

    /**
     * Отчёт о перемещении: какие детали и откуда возьмёт добавление.
     *
     * Скачивают до того, как нажать «Добавить», — чтобы собрать детали по
     * ящикам, — поэтому строки отсортированы по хранилищу. Разделитель —
     * точка с запятой, и в начале файла метка UTF-8: так его без вопросов
     * открывает русский Excel, а с запятой он сваливает всё в один столбец.
     */
    public function plan(Request $request, string $type, string $id): Response
    {
        $needs = $this->needs($type, $id);
        $plan = LoosePlan::make($needs, $this->chosen($request));

        $takes = $plan->takes;

        usort($takes, fn (array $a, array $b) => [$a['storage'] ?? "\u{10FFFF}", $a['type'], $a['item_id'], $a['color_id']]
            <=> [$b['storage'] ?? "\u{10FFFF}", $b['type'], $b['item_id'], $b['color_id']]);

        $out = fopen('php://temp', 'r+');

        fwrite($out, "\u{FEFF}");
        fputcsv($out, [
            __('app.pick.csv.storage'),
            __('app.pick.csv.kind'),
            __('app.pick.csv.item'),
            __('app.pick.csv.name'),
            __('app.pick.csv.colour'),
            __('app.pick.csv.qty'),
            __('app.pick.csv.lot'),
            __('app.pick.csv.lot_qty'),
            __('app.pick.csv.tags'),
            __('app.pick.csv.acquired'),
        ], ';');

        foreach ($takes as $take) {
            $need = match (true) {
                $take['type'] === 'M' => $needs->figures[$take['key']],
                $take['spare'] => $needs->spares[$take['key']],
                default => $needs->parts[$take['key']],
            };

            fputcsv($out, [
                $take['storage'] ?? '',
                match (true) {
                    $take['type'] === 'M' => __('app.pick.csv.figure'),
                    $take['spare'] => __('app.pick.csv.spare'),
                    default => __('app.pick.csv.part'),
                },
                $take['item_id'],
                $need['name'],
                $take['type'] === 'P' ? ($need['color_name'] ?? '') : '',
                $take['qty'],
                '#'.$take['entry_id'],
                $take['lot_qty'],
                $take['tags'],
                $take['acquired_at'] ?? '',
            ], ';');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="brickcollector-pick-'.$id.'-'.date('Y-m-d').'.csv"',
        ]);
    }

    public function add(Request $request, string $type, string $id, AddFromLoose $add): RedirectResponse
    {
        $item = $this->item($type, $id);

        $entry = $add->handle($item, $this->chosen($request));

        return ($type === 'M' ? to_route('minifigures.copy', $entry) : to_route('sets.show', $entry))
            ->with('flash', ['message' => __('app.pick.added', ['name' => $item->name])]);
    }

    private function item(string $type, string $id): CatalogItem
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        $item = CatalogItem::where('type', $type)->where('id', $id)->firstOrFail();

        abort_unless($item->has_inventory, 404);

        return $item;
    }

    private function needs(string $type, string $id): Needs
    {
        $item = $this->item($type, $id);

        return Needs::of($this->inventory->tree($item->type, $item->id));
    }

    /** @return array<int, string> номера фигурок, отмеченных к переносу */
    private function chosen(Request $request): array
    {
        $figures = $request->input('figures', []);

        return is_array($figures) ? array_values(array_filter($figures, 'is_string')) : [];
    }
}
