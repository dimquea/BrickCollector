<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Склад: то, что выставляют на продажу, — по экземплярам, а не итогами.
 *
 * Строка — свободная партия, отдельная фигурка или набор, со своими ценой за
 * штуку, состоянием и заметкой. Деталь внутри набора на склад не идёт.
 */
class StockExportTest extends TestCase
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

        DB::table('bl_categories')->insert([['id' => 5, 'name' => 'Brick']]);

        DB::table('bl_colors')->insert([
            ['id' => 0, 'name' => '(Not Applicable)', 'rgb' => null],
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
        ]);

        foreach ([['S', 'set-a', null], ['M', 'fig', null], ['P', 'brick', 5], ['P', 'plate', 5]] as [$type, $id, $category]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id), 'category_id' => $category,
                'image_color_id' => 11, 'has_inventory' => 0,
            ]);
        }

        DB::table('settings')->insert(['key' => 'currency', 'value' => 'RUB']);
    }

    /** @param array<string, mixed> $attributes */
    private function entry(string $type, string $id, int $qty = 1, int $lost = 0, array $attributes = []): Entry
    {
        $entry = Entry::create(array_merge([
            'item_type' => $type, 'item_id' => $id, 'color_id' => $type === 'P' ? 11 : null,
        ], $attributes));

        DB::table('collection_items')->insert([
            'entry_id' => $entry->id, 'item_type' => $type === 'S' ? 'P' : $type,
            'item_id' => $type === 'S' ? 'brick' : $id,
            'color_id' => $type === 'M' ? 0 : 11, 'qty' => $qty, 'lost_qty' => $lost, 'counts' => 1,
        ]);

        return $entry;
    }

    private function file(string $url): string
    {
        return $this->get($url)->assertOk()->getContent();
    }

    public function test_a_loose_lot_goes_with_its_unit_price_condition_and_note(): void
    {
        // 500 рублей за десять штук, две потеряны: на продажу восемь по 50.
        $this->entry('P', 'brick', qty: 10, lost: 2, attributes: [
            'price' => 50000, 'is_used' => true, 'note' => 'без царапин',
        ]);

        $xml = $this->file('/parts/export?as=stock-xml');

        $this->assertStringContainsString(implode("\n    ", [
            '<ITEMID>brick</ITEMID>',
            '<ITEMTYPE>P</ITEMTYPE>',
            '<COLOR>11</COLOR>',
            '<CATEGORY>5</CATEGORY>',
            '<QTY>8</QTY>',
            '<PRICE>50.000</PRICE>',
            '<CONDITION>U</CONDITION>',
            '<REMARKS>без царапин</REMARKS>',
        ]), $xml);
        $this->assertStringNotContainsString('<?xml', $xml, 'BrickLink ждёт документ сразу с INVENTORY');
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    /** Две партии одной детали — два лота: у них разные цена и состояние. */
    public function test_lots_are_not_folded_together(): void
    {
        $this->entry('P', 'brick', qty: 3);
        $this->entry('P', 'brick', qty: 4, attributes: ['is_used' => true]);

        $xml = $this->file('/parts/export?as=stock-xml');

        $this->assertSame(2, substr_count($xml, '<ITEMID>brick</ITEMID>'));
    }

    /** Деталь внутри набора продают с набором — отдельным лотом её нет. */
    public function test_a_part_inside_a_set_is_not_stock(): void
    {
        $this->entry('S', 'set-a', qty: 2);

        $this->assertStringNotContainsString('brick', $this->file('/parts/export?as=stock-xml'));
    }

    public function test_the_tag_and_the_used_filters_pick_lots(): void
    {
        $tag = DB::table('ref_tags')->insertGetId(['name' => 'For sale', 'color' => 'success', 'sort' => 1, 'show_in_list' => 0]);

        $tagged = $this->entry('P', 'brick', qty: 3);
        DB::table('entry_tags')->insert(['entry_id' => $tagged->id, 'tag_id' => $tag]);
        $this->entry('P', 'brick', qty: 4, attributes: ['is_used' => true]);

        $this->assertStringContainsString('<QTY>3</QTY>', $this->file('/parts/export?as=stock-xml&tag_id='.$tag));
        $this->assertStringNotContainsString('<QTY>4</QTY>', $this->file('/parts/export?as=stock-xml&tag_id='.$tag));

        $used = $this->file('/parts/export?as=stock-xml&used=true');

        $this->assertStringContainsString('<QTY>4</QTY>', $used);
        $this->assertStringNotContainsString('<QTY>3</QTY>', $used);
    }

    public function test_the_brickstore_stock_carries_currency_marker_and_names(): void
    {
        $lot = $this->entry('P', 'brick', qty: 2, attributes: ['price' => 1000]);

        $bsx = $this->file('/parts/export?as=stock-bsx');

        $this->assertStringContainsString('<Inventory Currency="RUB">', $bsx);
        $this->assertStringContainsString('<Price>5.000</Price>', $bsx);
        $this->assertStringContainsString('<Condition>N</Condition>', $bsx);
        $this->assertStringContainsString('<MarkerText>#'.$lot->id.'</MarkerText>', $bsx, 'наш номер — меткой, а не LotID');
        $this->assertStringNotContainsString('<LotID>', $bsx);
        $this->assertStringContainsString('<ItemName>Brick</ItemName>', $bsx);
        $this->assertStringContainsString('<ColorName>Black</ColorName>', $bsx);
        $this->assertStringNotContainsString('<ItemTypeName>', $bsx);
        $this->assertNotFalse(simplexml_load_string($bsx));
    }

    /** Набор — лот на один экземпляр, с полнотой. */
    public function test_a_set_is_one_lot_per_copy_with_its_completeness(): void
    {
        $this->entry('S', 'set-a', attributes: ['price' => 250000]);
        $this->entry('S', 'set-a', attributes: ['flag_incomplete' => true, 'is_used' => true]);

        $xml = $this->file('/sets/export?as=stock-xml');

        $this->assertSame(2, substr_count($xml, '<ITEMID>set-a</ITEMID>'));
        $this->assertStringContainsString('<PRICE>2500.000</PRICE>', $xml);
        $this->assertStringContainsString('<SUBCONDITION>C</SUBCONDITION>', $xml);
        $this->assertStringContainsString('<SUBCONDITION>I</SUBCONDITION>', $xml);
        $this->assertStringContainsString('<SubCondition>I</SubCondition>', $this->file('/sets/export?as=stock-bsx'));
    }

    /** Фигурку в наборе продают с набором; на склад идут отдельные экземпляры. */
    public function test_minifigure_stock_is_the_loose_copies(): void
    {
        $this->entry('M', 'fig', qty: 2, attributes: ['price' => 30000]);

        $set = $this->entry('S', 'set-a');
        DB::table('collection_items')->insert([
            'entry_id' => $set->id, 'item_type' => 'M', 'item_id' => 'fig', 'color_id' => 0,
            'qty' => 1, 'lost_qty' => 0, 'counts' => 1,
        ]);

        $xml = $this->file('/minifigures/export?as=stock-xml');

        $this->assertSame(1, substr_count($xml, '<ITEMID>fig</ITEMID>'));
        $this->assertStringContainsString('<QTY>2</QTY>', $xml);
        $this->assertStringContainsString('<PRICE>150.000</PRICE>', $xml);
    }

    /** Без цены — ноль: цену продажи расставят в BrickStore. */
    public function test_a_lot_without_a_price_goes_at_zero(): void
    {
        $this->entry('P', 'brick', qty: 2);

        $this->assertStringContainsString('<PRICE>0.000</PRICE>', $this->file('/parts/export?as=stock-xml'));
    }
}
