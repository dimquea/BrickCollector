<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use App\Collection\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «Подобрать из коллекции»: что из нужного набору уже есть и как добавить его,
 * взяв недостающее у свободных деталей.
 *
 * Набор в справочнике: 4 кирпича и 2 пластины, запасная пластина, фигурка с
 * головой и поднабор — два пакета по кирпичу. Нужно, значит, 6 кирпичей,
 * 2 пластины и фигурка; запасная в нужное не входит.
 */
class PickFromCollectionTest extends TestCase
{
    use RefreshDatabase;

    private const BLACK = 11;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'M', 'name' => 'Minifigure'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        DB::table('bl_colors')->insert([
            ['id' => 0, 'name' => '(Not Applicable)', 'rgb' => null],
            ['id' => self::BLACK, 'name' => 'Black', 'rgb' => '2E2E2E'],
        ]);

        foreach ([
            ['S', 'set-a', 1], ['S', 'pack', 1], ['S', 'other', 1], ['M', 'fig', 1],
            ['P', 'brick', 0], ['P', 'plate', 0], ['P', 'head', 0],
        ] as [$type, $id, $inventory]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id), 'image_color_id' => 0, 'has_inventory' => $inventory,
            ]);
        }

        $this->lot('S', 'set-a', 'P', 'brick', 4);
        $this->lot('S', 'set-a', 'P', 'plate', 2);
        $this->lot('S', 'set-a', 'P', 'plate', 1, ['is_extra' => 1]);
        $this->lot('S', 'set-a', 'M', 'fig', 1, ['color_id' => 0]);
        $this->lot('S', 'set-a', 'S', 'pack', 2, ['color_id' => 0]);
        $this->lot('S', 'pack', 'P', 'brick', 1);
        $this->lot('M', 'fig', 'P', 'head', 1);
    }

    private function lot(string $pt, string $pi, string $ct, string $ci, int $qty, array $extra = []): void
    {
        DB::table('bl_inventory')->insert(array_merge([
            'parent_type' => $pt, 'parent_id' => $pi, 'child_type' => $ct, 'child_id' => $ci,
            'color_id' => self::BLACK, 'qty' => $qty,
        ], $extra));
    }

    /** Свободная партия детали. */
    private function loose(string $id, int $qty, int $lost = 0, ?string $acquired = null, ?int $storage = null): Entry
    {
        $entry = Entry::create([
            'item_type' => 'P', 'item_id' => $id, 'color_id' => self::BLACK,
            'acquired_at' => $acquired, 'storage_id' => $storage,
        ]);

        DB::table('collection_items')->insert([
            'entry_id' => $entry->id, 'item_type' => 'P', 'item_id' => $id, 'color_id' => self::BLACK,
            'qty' => $qty, 'lost_qty' => $lost, 'counts' => 1,
        ]);

        return $entry;
    }

    /** Отдельная фигурка, с головой, которая может быть потеряна. */
    private function figure(int $qty = 1, int $headLost = 0): Entry
    {
        $entry = Entry::create(['item_type' => 'M', 'item_id' => 'fig']);

        $root = DB::table('collection_items')->insertGetId([
            'entry_id' => $entry->id, 'item_type' => 'M', 'item_id' => 'fig', 'color_id' => 0,
            'qty' => $qty, 'lost_qty' => 0, 'counts' => 1,
        ]);

        DB::table('collection_items')->insert([
            'entry_id' => $entry->id, 'parent_id' => $root, 'parent_item_type' => 'M',
            'item_type' => 'P', 'item_id' => 'head', 'color_id' => self::BLACK,
            'qty' => $qty, 'lost_qty' => $headLost, 'counts' => 1,
        ]);

        return $entry;
    }

    /** @return array<string, mixed> */
    private function pick(): array
    {
        return $this->getJson('/catalog/S/set-a/pick')->assertOk()->json();
    }

    private function added(): Entry
    {
        return Entry::where('item_type', 'S')->where('item_id', 'set-a')->latest('id')->firstOrFail();
    }

    /** @return array<string, int> «деталь/цвет» => недостача, по строкам набора вне фигурок */
    private function lost(Entry $entry): array
    {
        return Item::where('entry_id', $entry->id)->where('item_type', 'P')->where('counts', 1)
            ->where(fn ($q) => $q->whereNull('parent_item_type')->orWhere('parent_item_type', '!=', 'M'))
            ->get()
            ->groupBy(fn (Item $row) => $row->item_id)
            ->map(fn ($rows) => (int) $rows->sum('lost_qty'))
            ->all();
    }

    public function test_the_need_spans_subsets_and_leaves_out_spares_and_figure_parts(): void
    {
        $data = $this->pick();

        $this->assertSame(
            ['brick/11' => 6, 'plate/11' => 2],
            collect($data['parts'])->pluck('qty', 'key')->all(),
            'два пакета по кирпичу — ещё два; запасная пластина не нужна; голова едет с фигуркой',
        );
        $this->assertSame(['fig' => 1], collect($data['figures'])->pluck('qty', 'id')->all());
    }

    public function test_availability_is_told_apart_by_place_and_excludes_what_is_lost(): void
    {
        $this->loose('brick', 3, lost: 1);

        $other = Entry::create(['item_type' => 'S', 'item_id' => 'other']);
        DB::table('collection_items')->insert([
            'entry_id' => $other->id, 'item_type' => 'P', 'item_id' => 'brick', 'color_id' => self::BLACK,
            'qty' => 5, 'lost_qty' => 0, 'counts' => 1,
        ]);

        $this->figure();

        $data = $this->pick();
        $brick = collect($data['parts'])->firstWhere('key', 'brick/11');

        $this->assertSame(['all' => 7, 'sets' => 5, 'loose' => 2], $brick['available'], 'потерянное — не наличие');
        $this->assertSame(['all' => 1, 'sets' => 0, 'loose' => 1], $data['figures'][0]['available']);
        $this->assertSame(1, $data['lots']);
    }

    public function test_adding_spends_loose_lots_oldest_first_and_marks_the_rest_lost(): void
    {
        $older = $this->loose('brick', 2, acquired: '2020-01-01');
        $newer = $this->loose('brick', 10, acquired: '2024-01-01');
        $plate = $this->loose('plate', 1);

        $this->post('/catalog/S/set-a/pick/add')->assertRedirect();

        $set = $this->added();

        $this->assertNull(Entry::find($older->id), 'израсходованная до конца партия удаляется');
        $this->assertSame(6, Item::where('entry_id', $newer->id)->value('qty'), 'из новой взяли четыре');
        $this->assertNull(Entry::find($plate->id));

        $this->assertSame(['brick' => 0, 'plate' => 1], $this->lost($set), 'одной пластины не нашлось');
        $this->assertTrue($set->fresh()->flag_incomplete);
    }

    public function test_an_unticked_figure_stays_loose_and_is_lost_in_the_set(): void
    {
        $loose = $this->figure();

        $this->post('/catalog/S/set-a/pick/add')->assertRedirect();

        $this->assertNotNull(Entry::find($loose->id), 'без отметки фигурка остаётся свободной');

        $figure = Item::where('entry_id', $this->added()->id)->where('item_type', 'M')->first();

        $this->assertSame(1, $figure->lost_qty);
        $this->assertTrue($this->added()->flag_missing_figs);
    }

    /** Голова, потерянная у свободной фигурки, не находится оттого, что её положили в коробку. */
    public function test_a_ticked_figure_moves_in_with_its_losses(): void
    {
        $loose = $this->figure(headLost: 1);

        $this->post('/catalog/S/set-a/pick/add', ['figures' => ['fig']])->assertRedirect();

        $this->assertNull(Entry::find($loose->id), 'фигурка переехала в набор');

        $set = $this->added();
        $figure = Item::where('entry_id', $set->id)->where('item_type', 'M')->first();
        $head = Item::where('parent_id', $figure->id)->first();

        $this->assertSame(0, $figure->lost_qty);
        $this->assertSame(1, $head->lost_qty);
        $this->assertFalse($set->flag_missing_figs);
    }

    /** Из двух одинаковых свободных фигурок уходит одна; вторая остаётся целой. */
    public function test_a_figure_is_taken_out_of_a_lot_of_two(): void
    {
        $loose = $this->figure(qty: 2);

        $this->post('/catalog/S/set-a/pick/add', ['figures' => ['fig']])->assertRedirect();

        $rows = Item::where('entry_id', $loose->id)->orderBy('id')->get();

        $this->assertSame([1, 1], $rows->pluck('qty')->all(), 'фигурка и её голова — по одной');
    }

    /** Отчёт обещает ровно то, что потом сделает добавление. */
    public function test_the_plan_lists_the_lots_that_will_be_spent(): void
    {
        $storage = DB::table('ref_storages')->insertGetId(['name' => 'Box 7', 'sort' => 1, 'is_active' => 1]);
        $lot = $this->loose('brick', 10, storage: $storage);
        $this->figure();

        $csv = $this->get('/catalog/S/set-a/pick/plan?figures[]=fig')->assertOk()->getContent();

        $this->assertStringStartsWith("\u{FEFF}", $csv, 'метка UTF-8 — для Excel');
        $this->assertStringContainsString('"Box 7";', $csv, 'пробел — повод для кавычек');
        $this->assertStringContainsString(';brick;Brick;Black;6;#'.$lot->id.';10;', $csv);
        $this->assertStringContainsString(';fig;Fig;;1;', $csv);
    }

    public function test_the_export_asks_for_what_the_chosen_place_lacks(): void
    {
        $this->loose('brick', 5);
        $this->figure();

        $loose = $this->get('/catalog/S/set-a/pick/export?scope=loose&as=inventory&figures[]=fig')->getContent();

        $this->assertStringContainsString("<ITEMID>brick</ITEMID>\n    <COLOR>11</COLOR>\n    <QTY>1</QTY>", $loose);
        $this->assertStringContainsString("<ITEMID>plate</ITEMID>\n    <COLOR>11</COLOR>\n    <QTY>2</QTY>", $loose);
        $this->assertStringNotContainsString('<ITEMID>fig</ITEMID>', $loose, 'отмеченная фигурка нашлась');

        $unticked = $this->get('/catalog/S/set-a/pick/export?scope=loose&as=inventory')->getContent();

        $this->assertStringContainsString('<ITEMID>fig</ITEMID>', $unticked, 'на неотмеченную не рассчитывают');
    }

    /** Отметка — только о свободных: фигурка в другом наборе есть и без неё. */
    public function test_a_figure_in_another_set_counts_everywhere_without_a_tick(): void
    {
        $other = Entry::create(['item_type' => 'S', 'item_id' => 'other']);
        DB::table('collection_items')->insert([
            'entry_id' => $other->id, 'item_type' => 'M', 'item_id' => 'fig', 'color_id' => 0,
            'qty' => 1, 'lost_qty' => 0, 'counts' => 1,
        ]);

        $all = $this->get('/catalog/S/set-a/pick/export?scope=all&as=inventory')->getContent();
        $loose = $this->get('/catalog/S/set-a/pick/export?scope=loose&as=inventory')->getContent();

        $this->assertStringNotContainsString('<ITEMID>fig</ITEMID>', $all);
        $this->assertStringContainsString('<ITEMID>fig</ITEMID>', $loose);
    }

    /**
     * Запасная берётся после нужного, из того, что осталось; не нашлась —
     * отмечена потерянной, но набор от этого некомплектным не становится.
     */
    public function test_a_spare_is_taken_after_what_is_needed(): void
    {
        $this->loose('brick', 6);
        $this->loose('plate', 2);
        $this->figure();

        $this->post('/catalog/S/set-a/pick/add', ['figures' => ['fig']])->assertRedirect();

        $set = $this->added();
        $spare = Item::where('entry_id', $set->id)->where('is_extra', 1)->firstOrFail();

        $this->assertSame(1, $spare->lost_qty, 'обе пластины ушли на нужное');
        $this->assertSame(['brick' => 0, 'plate' => 0], $this->lost($set));
        $this->assertFalse($set->fresh()->flag_incomplete, 'без запасной набор полон');

        $this->loose('plate', 3);
        $this->post('/catalog/S/set-a/pick/add')->assertRedirect();

        $spare = Item::where('entry_id', $this->added()->id)->where('is_extra', 1)->firstOrFail();

        $this->assertSame(0, $spare->lost_qty, 'третья пластина — запасной');
        $this->assertSame(0, Entry::where('item_type', 'P')->count());
    }

    /** Добавили из свободных и тут же расформировали — в ящиках ровно то, что было. */
    public function test_adding_and_parting_out_neither_creates_nor_loses_a_brick(): void
    {
        $this->loose('brick', 10);
        $this->loose('plate', 1);
        $this->figure();

        $before = (int) DB::table('collection_items')->sum('qty');

        $this->post('/catalog/S/set-a/pick/add', ['figures' => ['fig']])->assertRedirect();
        $this->post('/sets/'.$this->added()->id.'/partout')->assertRedirect();

        $loose = Item::join('collection_entries as e', 'e.id', '=', 'collection_items.entry_id')
            ->where('e.item_type', 'P')
            ->groupBy('collection_items.item_id')
            ->selectRaw('collection_items.item_id, SUM(collection_items.qty) as qty')
            ->pluck('qty', 'item_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();

        $this->assertSame(['brick' => 10, 'plate' => 1], $loose);
        $this->assertSame(1, Entry::where('item_type', 'M')->count(), 'фигурка снова отдельная');
        $this->assertSame($before, (int) DB::table('collection_items')->sum('qty'));
    }

    /** У фигурки из справочника подбирают её собственные детали. */
    public function test_a_catalogue_figure_can_be_picked_too(): void
    {
        $this->loose('head', 1);

        $data = $this->getJson('/catalog/M/fig/pick')->assertOk()->json();

        $this->assertSame(['head/11' => 1], collect($data['parts'])->pluck('qty', 'key')->all());

        $this->post('/catalog/M/fig/pick/add')->assertRedirect();

        $this->assertSame(0, Entry::where('item_type', 'P')->count(), 'голова ушла в фигурку');
    }

    public function test_a_part_has_nothing_to_pick(): void
    {
        $this->getJson('/catalog/P/brick/pick')->assertNotFound();
    }
}
