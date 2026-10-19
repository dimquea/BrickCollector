<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Состояние экземпляра: новое или б/у.
 *
 * Для магазина это половина цены, поэтому состояние — поле, а не тег: тег
 * удаляют и переименовывают, расформирование его отбрасывает, импорт о нём не
 * знает. Поле переживает всё, что с экземпляром делают.
 */
class UsedConditionTest extends TestCase
{
    use RefreshDatabase;

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
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
        ]);

        foreach ([['S', 'set-a', 1], ['M', 'fig', 0], ['P', 'brick', 0], ['P', 'plate', 0]] as [$type, $id, $inventory]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id), 'image_color_id' => 11, 'has_inventory' => $inventory,
            ]);
        }

        DB::table('bl_inventory')->insert([
            'parent_type' => 'S', 'parent_id' => 'set-a', 'child_type' => 'P', 'child_id' => 'brick',
            'color_id' => 11, 'qty' => 2,
        ]);
    }

    private function lot(string $id, int $qty, bool $used = false): Entry
    {
        $entry = Entry::create(['item_type' => 'P', 'item_id' => $id, 'color_id' => 11, 'is_used' => $used]);

        DB::table('collection_items')->insert([
            'entry_id' => $entry->id, 'item_type' => 'P', 'item_id' => $id, 'color_id' => 11,
            'qty' => $qty, 'lost_qty' => 0, 'counts' => 1,
        ]);

        return $entry;
    }

    private function set(bool $used = false): Entry
    {
        $set = Entry::create(['item_type' => 'S', 'item_id' => 'set-a', 'is_used' => $used]);

        DB::table('collection_items')->insert([
            'entry_id' => $set->id, 'item_type' => 'P', 'item_id' => 'brick', 'color_id' => 11,
            'qty' => 2, 'lost_qty' => 0, 'counts' => 1,
        ]);

        return $set;
    }

    public function test_the_management_form_saves_it(): void
    {
        $set = $this->set();

        $this->patchJson('/sets/'.$set->id, ['is_used' => true, 'status_ids' => [], 'tag_ids' => []])->assertOk();
        $this->assertTrue($set->fresh()->is_used);

        $this->assertTrue($this->get('/sets/'.$set->id)->viewData('page')['props']['meta']['is_used']);
    }

    /** Сохранение без поля — у сборки его нет — не сбрасывает уже стоящее. */
    public function test_a_save_without_the_field_leaves_it_alone(): void
    {
        $set = $this->set(used: true);

        $this->patchJson('/sets/'.$set->id, ['note' => 'x', 'status_ids' => [], 'tag_ids' => []])->assertOk();

        $this->assertTrue($set->fresh()->is_used);
    }

    /** CONDITION из файла BrickLink — то самое состояние, и импорт его больше не теряет. */
    public function test_the_import_reads_the_condition(): void
    {
        $xml = "<INVENTORY>\n<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>brick</ITEMID><COLOR>11</COLOR><QTY>2</QTY>"
            ."<CONDITION>U</CONDITION></ITEM>\n</INVENTORY>";

        $rows = $this->post('/import/parse', [
            'file' => UploadedFile::fake()->createWithContent('order.xml', $xml),
        ], ['Accept' => 'application/json'])->assertOk()->json('rows');

        $this->assertTrue($rows[0]['used']);

        $this->postJson('/import', [
            'destination' => 'collection',
            'meta' => ['is_used' => false],
            'rows' => [['type' => 'P', 'id' => 'brick', 'color_id' => 11, 'qty' => 2, 'meta' => ['is_used' => true]]],
        ])->assertOk();

        $this->assertTrue(Entry::where('item_type', 'P')->firstOrFail()->is_used, 'строка перебивает общее');
    }

    public function test_parting_out_keeps_it(): void
    {
        $set = $this->set(used: true);

        $this->post('/sets/'.$set->id.'/partout')->assertRedirect();

        $this->assertTrue(Entry::where('item_type', 'P')->firstOrFail()->is_used, 'б/у набор — б/у детали');
    }

    /** Набор, в который ушла хоть одна б/у партия, — б/у: смесь у BrickLink и есть б/у. */
    public function test_a_set_built_from_a_used_lot_is_used(): void
    {
        $this->lot('brick', 1);
        $this->lot('brick', 1, used: true);

        $this->post('/catalog/S/set-a/pick/add')->assertRedirect();

        $this->assertTrue(Entry::where('item_type', 'S')->firstOrFail()->is_used);
    }

    public function test_a_set_built_from_new_lots_stays_new(): void
    {
        $this->lot('brick', 2);

        $this->post('/catalog/S/set-a/pick/add')->assertRedirect();

        $this->assertFalse(Entry::where('item_type', 'S')->firstOrFail()->is_used);
    }

    public function test_the_sets_filter(): void
    {
        $this->set(used: true);
        $this->set();

        $entries = $this->get('/sets?used=true')->viewData('page')['props']['entries']['data'];

        $this->assertCount(1, $entries);
    }

    /**
     * В разделе деталей «б/у» отбирает детали, что лежат в б/у экземплярах, —
     * хоть в партии, хоть в наборе, — а колонки строки остаются целыми.
     */
    public function test_the_parts_filter_keeps_the_row_whole(): void
    {
        $this->set(used: true);
        $this->lot('brick', 3);
        $this->lot('plate', 4);

        $parts = collect($this->get('/parts?used=true')->viewData('page')['props']['parts']['data']);

        $this->assertSame(['brick'], $parts->pluck('item_id')->all(), 'пластины в б/у нет');
        $this->assertSame(5, (int) $parts->first()->total, 'итог — по всей коллекции');
    }

    /** Опись под «б/у» — только то, что в б/у экземплярах. */
    public function test_the_inventory_under_the_filter_counts_the_used(): void
    {
        $this->set(used: true);
        $this->lot('brick', 3);

        $xml = $this->get('/parts/export?used=true&as=inventory')->assertOk()->getContent();

        $this->assertStringContainsString('<QTY>2</QTY>', $xml);
    }

    public function test_the_minifigures_filter_counts_a_figure_in_a_used_set(): void
    {
        $set = $this->set(used: true);
        DB::table('collection_items')->insert([
            'entry_id' => $set->id, 'item_type' => 'M', 'item_id' => 'fig', 'color_id' => 0,
            'qty' => 1, 'lost_qty' => 0, 'counts' => 1,
        ]);

        $figures = $this->get('/minifigures?used=true')->viewData('page')['props']['figures']['data'];

        $this->assertCount(1, $figures);
        $this->assertCount(0, $this->get('/minifigures?used=true&placement=loose')->viewData('page')['props']['figures']['data']);
    }

    public function test_the_card_badge_reads_it(): void
    {
        $lot = $this->lot('brick', 1, used: true);

        $this->assertTrue($this->get('/parts/copy/'.$lot->id)->viewData('page')['props']['meta']['is_used']);
    }
}
