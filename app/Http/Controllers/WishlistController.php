<?php

namespace App\Http\Controllers;

use App\Catalog\Images\ItemImages;
use App\Catalog\Models\Item;
use App\Catalog\Models\ItemType;
use App\Collection\Export\BrickLinkXml;
use App\Collection\Models\Wish;
use App\Collection\Queries\WishedItems;
use App\Http\ListFilters;
use App\Http\ListSort;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Желаемое: чего в коллекции нет, но хочется.
 *
 * Меты у желания нет и не будет: дата покупки, цена, место хранения — свойства
 * вещи, которой владеешь. Пока её нет, описывать нечего, кроме самого предмета,
 * поэтому строка отсюда ведёт в справочник, а не на свою страницу.
 */
class WishlistController extends Controller
{
    public function index(Request $request, WishedItems $wished, ItemImages $images): Response
    {
        $filters = $this->filters($request);
        $sort = $this->sort($request);

        $items = $wished->filters($filters)->sort($sort)->paginate(Settings::perPage('wishlist'));

        // Один запрос на страницу вместо запроса на карточку: иначе список
        // устраивает серверу поток обращений за заглушками.
        $available = $images->availability(
            collect($items->items())
                ->map(fn ($row) => [$row->type, $row->item_id, (int) $row->color_id])
                ->all(),
        );

        $facets = $wished->facets();

        return Inertia::render('Wishlist/Index', [
            'cardSize' => Settings::cardSize('wishlist'),
            'filters' => $filters,
            'sort' => $sort,
            'items' => $items->through(fn ($row) => [
                'wish_id' => (int) $row->wish_id,
                'type' => $row->type,
                'id' => $row->item_id,
                'name' => $row->name,
                'year' => $row->year,
                'theme' => $row->theme,
                'has_inventory' => (bool) $row->has_inventory,
                'color_id' => (int) $row->color_id,
                'color_name' => $row->color_name,
                'color_rgb' => $row->color_rgb,
                'image_color_id' => (int) ($row->color_id ?: $row->image_color_id),
                'has_image' => $available[$row->type.'/'.$row->item_id.'/'.(int) $row->color_id] ?? false,
            ]),
            'itemTypes' => $facets['types'],
            'themes' => $facets['themes'],
            'years' => $facets['years'],
        ]);
    }

    /**
     * Желаемое в BrickLink XML — списком желаемого, иначе и быть не может.
     *
     * Количества у желания нет: хотят вещь, а не пять её штук. MINQTY поэтому
     * всегда единица — меньше нельзя, а больше взять неоткуда.
     */
    public function export(Request $request, WishedItems $wished): HttpResponse
    {
        $rows = $wished->filters($this->filters($request))->sort($this->sort($request))->all();

        return BrickLinkXml::download(BrickLinkXml::wanted($rows->map(fn (object $row) => [
            'type' => $row->type,
            'id' => $row->item_id,
            // Цвет осмыслен только у детали, и только если известен.
            'color' => $row->type === 'P' && (int) $row->color_id > 0 ? (int) $row->color_id : null,
            'qty' => 1,
        ])), 'wishlist', 'wanted');
    }

    /**
     * Список и выгрузка читают фильтр одними правилами: разойдись они, выгрузка
     * отдала бы не то, что на экране.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return ListFilters::read($request, [
            'q' => ['string', 'max:120'],
            'type' => ['string', 'in:'.implode(',', ItemType::BROWSABLE)],
            'theme_id' => ['integer'],
            'year' => ['integer', 'min:1949', 'max:'.(date('Y') + 1)],
        ]);
    }

    /**
     * «added» — не поле, а порядок добавления: последнее желание сверху.
     *
     * @return array{by: string, dir: string}
     */
    private function sort(Request $request): array
    {
        return ListSort::read($request, ['id', 'name', 'year'], 'added', 'desc');
    }

    /**
     * Отвечает JSON, а не редиректом.
     *
     * Желание добавляют и убирают, не сходя со страницы справочника: кнопка
     * меняет вид по ответу, и уводить человека никуда не нужно. Прежний возврат
     * «назад» под Home Assistant вдобавок промахивался — в сессии предыдущей
     * страницы нет, и он падал на корень, выбрасывая на главную.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'size:1'],
            'id' => ['required', 'string', 'max:120'],
            'color_id' => ['nullable', 'integer', 'exists:bl_colors,id'],
        ]);

        $item = Item::where('type', $validated['type'])->where('id', $validated['id'])->firstOrFail();

        // Цвет осмыслен только у детали: хотят «2431 в тёмно-сером», а не
        // «2431 вообще». У остального это 0 — «неприменимо».
        $colour = $item->type === 'P'
            ? (int) ($validated['color_id'] ?? $item->image_color_id)
            : 0;

        // Повторное нажатие — не ошибка, а просто ничего: у желания нет
        // количества, и второе такое же ничего к списку не прибавляет.
        $wish = Wish::firstOrCreate([
            'item_type' => $item->type,
            'item_id' => $item->id,
            'color_id' => $colour,
        ]);

        return response()->json([
            'message' => __('app.wishlist.added', ['name' => $item->name]),
            'wish' => ['id' => $wish->id, 'color_id' => $wish->color_id],
        ]);
    }

    /**
     * Убирает желание.
     *
     * Принимает номер, а не готовую строку: по двойному клику второй запрос
     * приходит за тем, чего уже нет, и связывание модели ответило бы 404 на
     * действие, которое на самом деле удалось. Желания нет — значит, всё в
     * порядке.
     */
    public function destroy(int $wish): JsonResponse
    {
        Wish::find($wish)?->delete();

        return response()->json(['message' => __('app.wishlist.removed')]);
    }
}
