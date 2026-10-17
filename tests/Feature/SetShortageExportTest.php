<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Недостача одной группы экземпляра — со страницы набора.
 *
 * Выгружается всё поддерево группы: поднабор держит пакеты, пакет — фигурку,
 * фигурка — детали. Фигурка, потерянная целиком, уходит одной строкой, без
 * своих деталей; у которой не хватает чего-то одного — этими деталями.
 */
class SetShortageExportTest extends TestCase
{
    use RefreshDatabase;

    private Entry $set;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'M', 'name' => 'Minifigure'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        DB::table('bl_colors')->insert([['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E']]);

        $this->set = Entry::create(['item_type' => 'S', 'item_id' => '1000-1']);
    }

    /** @param array<string, mixed> $attributes */
    private function row(array $attributes): int
    {
        return DB::table('collection_items')->insertGetId(array_merge([
            'entry_id' => $this->set->id, 'color_id' => 11, 'qty' => 1, 'lost_qty' => 0, 'counts' => 1,
        ], $attributes));
    }

    private function xml(string $group, string $as = 'inventory'): string
    {
        return $this->get("/sets/{$this->set->id}/shortage/{$group}?as={$as}")->assertOk()->getContent();
    }

    public function test_the_parts_group_exports_what_is_missing(): void
    {
        $this->row(['item_type' => 'P', 'item_id' => 'brick', 'qty' => 4, 'lost_qty' => 3]);
        $this->row(['item_type' => 'P', 'item_id' => 'plate', 'qty' => 2]);

        $xml = $this->xml('P');

        $this->assertStringContainsString('<ITEMID>brick</ITEMID>', $xml);
        $this->assertStringContainsString('<QTY>3</QTY>', $xml);
        $this->assertStringNotContainsString('plate', $xml, 'целое в недостачу не идёт');
    }

    public function test_a_figure_lost_whole_goes_as_one_line_without_its_parts(): void
    {
        $figure = $this->row(['item_type' => 'M', 'item_id' => 'fig', 'color_id' => 0, 'qty' => 1, 'lost_qty' => 1]);
        $this->row([
            'item_type' => 'P', 'item_id' => 'head', 'parent_id' => $figure, 'parent_item_type' => 'M', 'lost_qty' => 1,
        ]);

        $xml = $this->xml('M');

        $this->assertStringContainsString('<ITEMTYPE>M</ITEMTYPE>', $xml);
        $this->assertStringNotContainsString('head', $xml, 'иначе фигурка уехала бы дважды');
    }

    public function test_a_figure_short_a_part_goes_as_that_part(): void
    {
        $figure = $this->row(['item_type' => 'M', 'item_id' => 'fig', 'color_id' => 0]);
        $this->row([
            'item_type' => 'P', 'item_id' => 'head', 'parent_id' => $figure, 'parent_item_type' => 'M', 'lost_qty' => 1,
        ]);

        $xml = $this->xml('M');

        $this->assertStringContainsString('<ITEMID>head</ITEMID>', $xml);
        $this->assertStringNotContainsString('<ITEMTYPE>M</ITEMTYPE>', $xml);
    }

    /** Поднабор — пакет — фигурка — деталь: сколь угодно глубоко. */
    public function test_a_subset_exports_its_whole_subtree(): void
    {
        $subset = $this->row(['item_type' => 'S', 'item_id' => 'pack', 'color_id' => 0, 'qty' => 2]);
        $this->row([
            'item_type' => 'P', 'item_id' => 'brick', 'parent_id' => $subset, 'parent_item_type' => 'S',
            'qty' => 4, 'lost_qty' => 1,
        ]);
        $figure = $this->row([
            'item_type' => 'M', 'item_id' => 'fig', 'color_id' => 0, 'parent_id' => $subset, 'parent_item_type' => 'S',
            'qty' => 2,
        ]);
        $this->row([
            'item_type' => 'P', 'item_id' => 'brick', 'parent_id' => $figure, 'parent_item_type' => 'M',
            'qty' => 2, 'lost_qty' => 2,
        ]);

        $xml = $this->xml('S');

        $this->assertSame(1, substr_count($xml, '<ITEMID>brick</ITEMID>'), 'одинаковое складывается');
        $this->assertStringContainsString('<QTY>3</QTY>', $xml);
    }

    /** Альтернатива в коробке не лежит — пропасть ей неоткуда. */
    public function test_an_alternate_is_not_missing(): void
    {
        $this->row(['item_type' => 'P', 'item_id' => 'brick', 'qty' => 1, 'lost_qty' => 1, 'counts' => 0, 'is_alternate' => 1]);

        $this->assertStringNotContainsString('brick', $this->xml('P'));
    }

    public function test_by_default_it_is_a_wanted_list_naming_the_set(): void
    {
        $this->row(['item_type' => 'P', 'item_id' => 'brick', 'qty' => 4, 'lost_qty' => 3]);

        $xml = $this->get("/sets/{$this->set->id}/shortage/P")->assertOk()->getContent();

        $this->assertStringContainsString('<MINQTY>3</MINQTY>', $xml);
        $this->assertStringContainsString('<REMARKS>1000-1</REMARKS>', $xml);
        $this->assertStringContainsString('<Qty>3</Qty>', $this->xml('P', 'bsx'));
    }

    public function test_a_loose_lot_has_no_such_export(): void
    {
        $lot = Entry::create(['item_type' => 'P', 'item_id' => 'brick', 'color_id' => 11]);

        $this->get("/sets/{$lot->id}/shortage/P")->assertNotFound();
    }
}
