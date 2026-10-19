<?php

namespace App\Http\Controllers;

use App\Catalog\Models\Item;
use App\Catalog\Models\ItemType;
use App\Catalog\Models\Theme;
use App\Catalog\Images\ItemImages;
use App\Catalog\Queries\ItemIdentifiers;
use App\Catalog\Queries\ItemInventory;
use App\Catalog\Queries\ItemParents;
use App\Catalog\Queries\SearchItems;
use App\Catalog\Queries\ThemeOptions;
use App\Collection\Actions\AddToCollection;
use App\Collection\Actions\MoveParts;
use App\Collection\Actions\ResizeLot;
use App\Collection\Models\Entry;
use App\Collection\Models\Wish;
use App\Collection\Queries\OwnedPlaces;
use App\Collection\Queries\PartPlaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use App\Support\ExternalLinks;
use App\Support\Settings;
use App\Http\ListFilters;
use App\Http\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request, SearchItems $search): Response
    {
        $filters = ListFilters::read($request, [
            'q' => ['string', 'max:120'],
            'type' => ['string', 'in:'.implode(',', ItemType::BROWSABLE)],
            'theme_id' => ['integer'],
            'year' => ['integer', 'min:1949', 'max:'.(date('Y') + 1)],
            // «3001:11,3023:» — детали, которые должны быть в наборе.
            'parts' => ['string', 'max:800'],
        ], switches: ['has_inventory']);

        // «default» — не поле, а отсутствие выбора: справочник тогда работает
        // по-своему, релевантностью при поиске и артикулом без него.
        $sort = ListSort::read($request, ['id', 'name', 'year'], 'default');

        $results = $search->filters($filters)->sort($sort)->paginate(Settings::perPage('catalog'));

        // Код элемента называет не деталь вообще, а деталь в цвете: его
        // переписывают из инструкции, где нужен ровно один кирпичик. Поэтому
        // найденное по коду показывается в этом цвете и открывается в нём же,
        // а не в том, в каком деталь нарисована в справочнике.
        $asked = $this->elementCodeColours($filters['q'] ?? null);

        $colourOf = fn (Item $item) => $asked[$item->type.'/'.$item->id] ?? (int) $item->image_color_id;

        $images = app(ItemImages::class)->availability(
            collect($results->items())
                ->map(fn (Item $item) => [$item->type, $item->id, $colourOf($item)])
                ->all(),
        );

        return Inertia::render('Catalog/Index', [
            'cardSize' => Settings::cardSize('catalog'),
            'filters' => $filters,
            'sort' => $sort,
            'results' => $results->through(fn (Item $item) => [
                'type' => $item->type,
                'id' => $item->id,
                'name' => $item->name,
                'year' => $item->year,
                'theme' => $item->theme?->path,
                'has_inventory' => $item->has_inventory,
                'image_color_id' => $colourOf($item),
                // Цвет, названный кодом, уезжает в ссылку: карточка откроется
                // сразу в нём. У остальных строк его нет, и ссылка короткая.
                'code_color' => $asked[$item->type.'/'.$item->id] ?? null,
                'has_image' => $images[$item->type.'/'.$item->id.'/'.$colourOf($item)] ?? false,
            ]),
            'itemTypes' => ItemType::whereIn('code', ItemType::BROWSABLE)
                ->orderByRaw("CASE code ".implode(' ', array_map(
                    fn ($code, $i) => "WHEN '{$code}' THEN {$i}",
                    ItemType::BROWSABLE,
                    array_keys(ItemType::BROWSABLE),
                ))." END")
                ->get(['code', 'name']),
            // Корни, а не всё дерево: в нём 6400 узлов, и даже два уровня —
            // это три с половиной тысячи вариантов в каждом ответе. Выбор
            // корня захватывает всё поддерево, так что недостижимого нет.
            'themes' => ThemeOptions::roots(),
            // Подтемы выбранного корня — второй список, который появляется
            // после выбора первого. «Star Wars» — это тысячи наборов, и сузить
            // до Ultimate Collector Series хочется прямо здесь.
            'subthemes' => ThemeOptions::under($filters['theme_id'] ?? null),
            'themeRoot' => ThemeOptions::rootId($filters['theme_id'] ?? null),
            'years' => $this->years(),
            // Кнопка поиска по фото появляется только с согласия: снимок уходит
            // наружу, и предлагать её не спросив было бы обещанием, которого
            // приложение в остальном не даёт.
            'photoSearch' => Settings::photoSearch(),
            'cameraButton' => Settings::photoSearch() && Settings::cameraButton(),
        ]);
    }

    /**
     * Деталь для поиска набора по деталям — по тому, что вписали в поле.
     *
     * Вписывают артикул, а иногда номер из инструкции: 4243815 называет деталь
     * вместе с цветом, и тогда цвет подставляется сам. Второе имя предмета
     * цвета не знает. Цвета в ответе — только те, в которых деталь встречается
     * в описях: в другом её не найдётся ни в одном наборе.
     */
    public function lookupPart(Request $request, ItemParents $parents): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if ($term === '' || mb_strlen($term) > 60) {
            return response()->json(['found' => false]);
        }

        $item = Item::where('type', 'P')->where('id', $term)->first();
        $colour = null;

        if ($item === null) {
            $named = ItemIdentifiers::resolve($term)->firstWhere('type', 'P');

            $item = $named === null ? null : Item::where('type', 'P')->where('id', $named->id)->first();
            $colour = $named?->color_id === null ? null : (int) $named->color_id;
        }

        if ($item === null) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'id' => $item->id,
            'name' => $item->name,
            'color_id' => $colour,
            'colours' => $parents->colours($item->id),
        ]);
    }

    public function show(
        Request $request,
        string $type,
        string $id,
        ItemInventory $inventory,
        ItemParents $parents,
    ): Response {
        $item = Item::with('theme', 'category')
            ->where('type', $type)
            ->where('id', $id)
            ->firstOrFail();

        $tree = $item->has_inventory ? $inventory->tree($type, $id) : [];

        // A part is added in a colour, and either as a lot of its own or onto
        // one already held, so its dialog needs both lists. Nothing else
        // has either.
        $lots = $item->type === 'P' ? PartPlaces::lots($item->id) : collect();

        // Цвет, в котором смотрят деталь. Приходит из адреса — со страницы
        // детали, из состава набора, из желаемого — и держит всю карточку:
        // картинку, внешние ссылки, окно добавления и желание. Прежде цвет знало
        // одно лишь желание, и карточка, открытая в Reddish Brown, предлагала
        // добавить деталь в Dark Tan — том цвете, в каком она нарисована.
        $asked = $request->query('color');
        $asked = ctype_digit((string) $asked) ? (int) $asked : null;

        $colours = $this->colours($item, $lots, $asked);

        // Цвет, которого справочник не знает, отбрасывается: адрес правят руками
        // и пересылают, и ?color=нечто заслуживает обычной карточки, а не отказа
        // и не картинки, которой нет.
        $offered = array_map(static fn (array $row) => (int) $row['id'], $colours);

        $colour = $asked !== null && in_array($asked, $offered, true)
            ? $asked
            : (int) $item->image_color_id;

        return Inertia::render('Catalog/Show', [
            // Цвет уходит и во внешние ссылки — но только если у предмета он
            // вообще бывает: у набора ссылка с цветом ноль ведёт туда же, а
            // лишний параметр в адресе ни к чему.
            'links' => ExternalLinks::for($item->type, $item->id, $colours === [] ? 0 : $colour),
            'item' => [
                'type' => $item->type,
                'id' => $item->id,
                'name' => $item->name,
                'year' => $item->year,
                'weight' => $item->weight,
                'theme' => $item->theme?->path,
                'theme_id' => $item->theme_id,
                'category' => $item->category?->name,
                'image_color_id' => $item->image_color_id,
                'has_inventory' => $item->has_inventory,
                'type_name' => ItemType::find($item->type)?->name,
            ],
            'inventory' => $tree,
            'totals' => $inventory->summarise($tree),
            'elementCodes' => $this->elementCodes($item),
            'colour' => $colour,
            'colours' => $colours,
            'lots' => $lots->values(),
            // A part can also go straight into an assembly: bought for the
            // model being built, not for the drawer.
            'assemblies' => $item->type === 'P'
                ? Entry::whereNull('item_type')->orderBy('name')->get(['id', 'name'])
                : [],
            'parents' => $this->parents($request, $item, $parents),
            // Где этот предмет лежит у меня. Деталь — в цвете карточки: цвет
            // здесь уже решает, какая картинка, какие внешние ссылки и что
            // предложит окно добавления, и блок, живущий по своим правилам,
            // читался бы как ошибка.
            'owned' => (new OwnedPlaces(
                $item->type,
                $item->id,
                $item->type === 'P' ? $colour : 0,
            ))->groups(),
            // Уже в желаемом или нет — кнопка должна знать это сразу, не
            // спрашивая отдельно. У детали желаний может быть несколько: её
            // хотят в цвете, и цветов бывает больше одного.
            'wishes' => Wish::where('item_type', $item->type)
                ->where('item_id', $item->id)
                ->get(['id', 'color_id'])
                ->map(fn (Wish $wish) => ['id' => $wish->id, 'color_id' => $wish->color_id]),
        ]);
    }

    /**
     * Цвета, названные кодом элемента LEGO.
     *
     * Код вроде 4243815 — это не «Plate 4 x 4», а «Plate 4 x 4 синяя»: его
     * переписывают из инструкции, и цвет там половина ответа. Сам поиск
     * находит предмет — коды лежат в поисковом индексе наравне с артикулами, —
     * а цвет знает только эта таблица.
     *
     * Строка ищется как есть, точным совпадением, без догадок о том, похожа
     * ли она на код: обычное слово просто не найдётся, и стоит это сотые доли
     * миллисекунды по индексу.
     *
     * @return array<string, int> «тип/артикул» => цвет
     */
    private function elementCodeColours(?string $term): array
    {
        $code = trim((string) $term);

        if ($code === '') {
            return [];
        }

        $colours = [];

        $rows = DB::table('bl_element_codes')
            ->where('code', $code)
            ->get(['item_type', 'item_id', 'color_id']);

        foreach ($rows as $row) {
            $key = $row->item_type.'/'.$row->item_id;

            // Один предмет под одним кодом в нескольких цветах — восемь
            // случаев на весь справочник. Выбирать за человека не из чего,
            // поэтому такая строка показывается как обычно.
            $colours[$key] = array_key_exists($key, $colours) ? null : (int) $row->color_id;
        }

        return array_filter($colours, static fn (?int $colour) => $colour !== null);
    }

    /**
     * What this item is part of: the kinds it turns up in, and one page of
     * one kind.
     *
     * Null when it turns up nowhere and nothing is being filtered — there is
     * no block to draw. Under a colour filter the block stays even when empty,
     * or there would be no way back to another colour.
     *
     * @return array<string, mixed>|null
     */
    private function parents(Request $request, Item $item, ItemParents $parents): ?array
    {
        $colour = null;

        if ($item->type === 'P') {
            $asked = $request->query('in_color');

            $colour = $asked === null || $asked === '' || ! ctype_digit((string) $asked)
                ? null
                : (int) $asked;
        }

        $counts = $parents->kinds($item->type, $item->id, $colour);

        if ($counts === [] && $colour === null) {
            return null;
        }

        // The kind asked for, unless it holds nothing now — a colour filter
        // can empty the tab that was open.
        $kind = $request->query('in');
        $kind = isset($counts[$kind]) ? $kind : array_key_first($counts);

        $names = ItemType::whereIn('code', array_keys($counts))->pluck('name', 'code');

        return [
            'kind' => $kind,
            'kinds' => collect($counts)
                ->map(fn (int $count, string $code) => [
                    'code' => $code,
                    'name' => $names[$code] ?? $code,
                    'count' => $count,
                ])
                ->values(),
            'colour' => $colour,
            'colours' => $item->type === 'P' ? $parents->colours($item->id) : [],
            'rows' => $kind === null
                ? []
                : $parents
                    ->page($item->type, $item->id, $kind, $colour, $counts[$kind], (int) $request->query('in_page', 1))
                    ->appends($request->except('in_page')),
        ];
    }

    /**
     * Puts a catalog item into the collection and goes to where it now lives.
     */
    public function addToCollection(
        Request $request,
        string $type,
        string $id,
        AddToCollection $add,
        ResizeLot $resize,
    ): RedirectResponse {
        $item = Item::where('type', $type)->where('id', $id)->firstOrFail();

        $validated = $request->validate([
            'qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'color_id' => ['nullable', 'integer', 'exists:bl_colors,id'],
            'lot_id' => ['nullable', 'integer'],
            'assembly_id' => ['nullable', 'integer'],
        ]);

        $qty = $validated['qty'] ?? 1;

        if ($item->type === 'P') {
            return $this->addPart(
                $item,
                $qty,
                $validated['color_id'] ?? (int) $item->image_color_id,
                $validated['lot_id'] ?? null,
                $validated['assembly_id'] ?? null,
                $add,
                $resize,
            );
        }

        $entry = $add->handle($item, [
            'qty' => $qty,
            // У аксессуара цвет выбирают на карточке, как у детали.
            'color_id' => $validated['color_id'] ?? null,
        ]);

        $flash = ['message' => __('app.collection.added', ['name' => $item->name])];

        // Each kind of thing has a place it now lives; go there rather than
        // leaving the person on the catalog page wondering where it went.
        $destination = match (true) {
            in_array($item->type, SetsController::TYPES, true) => to_route('sets.show', $entry),
            $item->type === 'M' => to_route('minifigures.copy', $entry),
            // Своего раздела у такого типа нет, поэтому остаёмся на карточке
            // справочника. Названный адрес, а не back(): под панелью Home
            // Assistant «назад» оборачивается корнем, и человек решает, что
            // добавить не вышло, — хотя вышло.
            default => to_route('catalog.show', [$item->type, $item->id]),
        };

        return $destination->with('flash', $flash);
    }

    /**
     * A part goes either into a lot of its own or onto one already held.
     *
     * Topping up is for more of the same; a new lot is another purchase, with
     * its own date, price and place. Only the person knows which it is, so the
     * dialog asks rather than the server guessing.
     */
    private function addPart(
        Item $item,
        int $qty,
        int $colorId,
        ?int $lotId,
        ?int $assemblyId,
        AddToCollection $add,
        ResizeLot $resize,
    ): RedirectResponse {
        if ($assemblyId !== null) {
            return $this->addPartToAssembly($item, $qty, $colorId, $assemblyId, $add);
        }

        if ($lotId === null) {
            $entry = $add->handle($item, ['qty' => $qty, 'color_id' => $colorId]);

            return to_route('parts.copy', $entry)
                ->with('flash', ['message' => __('app.collection.added', ['name' => $item->name])]);
        }

        // Only a lot of this very part in this very colour: topping up a red
        // brick with blue ones would quietly change what the lot is.
        $lot = Entry::where('id', $lotId)
            ->where('item_type', 'P')
            ->where('item_id', $item->id)
            ->where('color_id', $colorId)
            ->first();

        if ($lot === null) {
            throw ValidationException::withMessages(['lot_id' => __('app.parts.lot_mismatch')]);
        }

        $resize->handle($lot, (int) $lot->roots()->value('qty') + $qty);

        return to_route('parts.copy', $lot)
            ->with('flash', ['message' => __('app.parts.topped_up', ['count' => $qty])]);
    }

    /**
     * A part bought for something being built goes into the assembly directly.
     *
     * It is still entered as a lot first and then moved, rather than written
     * into the assembly by hand: that is the one path parts take into an
     * assembly, and a second one would be a second set of rules about what
     * happens to the rows underneath.
     */
    private function addPartToAssembly(
        Item $item,
        int $qty,
        int $colorId,
        int $assemblyId,
        AddToCollection $add,
    ): RedirectResponse {
        $assembly = Entry::whereNull('item_type')->find($assemblyId);

        if ($assembly === null) {
            throw ValidationException::withMessages(['assembly_id' => __('app.assembly.gone')]);
        }

        $lot = $add->handle($item, ['qty' => $qty, 'color_id' => $colorId]);

        app(MoveParts::class)->fromLot($assembly, $lot, $qty);

        return to_route('assemblies.show', $assembly)
            ->with('flash', ['message' => __('app.assembly.added')]);
    }

    /**
     * Colours a part can be added in.
     *
     * The ones BrickLink has element codes for, plus any already held and the
     * one in the picture. A part with no element codes at all — old moulds,
     * mostly — gets the whole palette: offering only its picture colour would
     * leave every other one impossible to record.
     *
     * @param  Collection<int, array<string, mixed>>  $lots
     * @param  int|null  $asked  цвет, в котором пришли смотреть
     * @return array<int, array<string, mixed>>
     */
    private function colours(Item $item, Collection $lots, ?int $asked = null): array
    {
        // Цвет считался свойством детали, и это было верно ровно до тех пор,
        // пока в составе наборов не показались аксессуары: коробка-органайзер
        // из серии Throwbot в каждом наборе своего цвета, и таких предметов в
        // справочнике пять сотен. Кодов элементов у них нет ни одного, зато
        // есть описи наборов, где они лежат, — оттуда цвета и берутся.
        if ($item->type !== 'P') {
            return app(ItemParents::class)->colours($item->id, $item->type);
        }

        $known = DB::table('bl_element_codes')
            ->where('item_type', 'P')
            ->where('item_id', $item->id)
            ->distinct()
            ->pluck('color_id');

        $query = DB::table('bl_colors')->orderBy('name');

        if ($known->isNotEmpty()) {
            $query->whereIn('id', $known
                ->merge($lots->pluck('color_id'))
                ->push((int) $item->image_color_id)
                // Цвет, в котором пришли, предлагается даже когда кода элемента
                // на него нет: в наборе деталь в этом цвете есть, раз оттуда
                // пришли. Числа, которого справочник не знает, соединение не
                // вернёт, и оно отсеется само. Ноль — цвет, а не пустота,
                // поэтому отсеивается только null.
                ->push($asked)
                ->filter(fn ($id) => $id !== null)
                ->unique()
                ->values());
        }

        return $query->get(['id', 'name', 'rgb'])->map(fn ($row) => (array) $row)->all();
    }

    /**
     * LEGO element ids for a part, which is how a number printed in a set's
     * instructions can be looked up.
     *
     * @return array<int, array<string, mixed>>
     */
    private function elementCodes(Item $item): array
    {
        if ($item->type !== 'P') {
            return [];
        }

        return DB::table('bl_element_codes as e')
            ->leftJoin('bl_colors as c', 'c.id', '=', 'e.color_id')
            ->where('e.item_type', $item->type)
            ->where('e.item_id', $item->id)
            ->orderBy('c.name')
            ->limit(200)
            ->get(['e.code', 'e.color_id', 'c.name as color_name', 'c.rgb as color_rgb'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** @return int[] */
    private function years(): array
    {
        return range((int) date('Y') + 1, 1949);
    }
}
