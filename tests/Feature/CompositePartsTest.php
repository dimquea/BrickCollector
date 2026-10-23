<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Детали внутри составных — для справки, мимо итогов.
 *
 * Торс лежит в коллекции целиком, одной строкой: его руки и кисти — та же
 * пластмасса, и считать их отдельно значило бы считать деталь дважды. Но на
 * вопрос «есть ли у меня такая кисть» ответ есть — из справочного состава
 * торсов, что лежат в коллекции.
 */
class CompositePartsTest extends TestCase
{
    use RefreshDatabase;

    private const GRAY = 86;

    private const DARK = 85;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        DB::table('bl_colors')->insert([
            ['id' => self::GRAY, 'name' => 'Light Bluish Gray', 'rgb' => 'A0A5A9'],
            ['id' => self::DARK, 'name' => 'Dark Bluish Gray', 'rgb' => '6C6E68'],
        ]);

        foreach ([['torso', 1], ['arm', 0], ['hand', 0], ['other-hand', 0], ['brick', 0]] as [$id, $inventory]) {
            DB::table('bl_items')->insert([
                'type' => 'P', 'id' => $id, 'name' => ucfirst($id), 'image_color_id' => self::GRAY, 'has_inventory' => $inventory,
            ]);
        }

        DB::table('bl_items')->insert(['type' => 'S', 'id' => 'set-a', 'name' => 'Set A', 'image_color_id' => 0, 'has_inventory' => 1]);

        // Торс: руки и две кисти; альтернативная кисть в состав не входит.
        foreach ([
            ['arm', self::GRAY, 1, 0], ['hand', self::DARK, 2, 0], ['other-hand', self::DARK, 2, 1],
        ] as [$child, $colour, $qty, $alternate]) {
            DB::table('bl_inventory')->insert([
                'parent_type' => 'P', 'parent_id' => 'torso', 'child_type' => 'P', 'child_id' => $child,
                'color_id' => $colour, 'qty' => $qty, 'is_alternate' => $alternate,
            ]);
        }

        // В наборе три торса, один потерян; ещё четыре кисти лежат отдельно.
        $set = Entry::create(['item_type' => 'S', 'item_id' => 'set-a']);
        $this->row($set, 'torso', self::GRAY, 3, lost: 1);
        $this->row($set, 'brick', self::GRAY, 5);

        $lot = Entry::create(['item_type' => 'P', 'item_id' => 'hand', 'color_id' => self::DARK]);
        $this->row($lot, 'hand', self::DARK, 4);
    }

    private function row(Entry $entry, string $id, int $colour, int $qty, int $lost = 0): void
    {
        DB::table('collection_items')->insert([
            'entry_id' => $entry->id, 'item_type' => 'P', 'item_id' => $id, 'color_id' => $colour,
            'qty' => $qty, 'lost_qty' => $lost, 'counts' => 1,
            'parent_item_type' => $entry->item_type === 'S' ? 'S' : null,
        ]);
    }

    /** @return array<string, array{total: int, inside: int}> */
    private function listed(string $query): array
    {
        return collect($this->get('/parts?'.$query)->assertOk()->viewData('page')['props']['parts']['data'])
            ->mapWithKeys(fn ($row) => [$row->item_id.'/'.$row->color_id => ['total' => (int) $row->total, 'inside' => (int) ($row->inside ?? 0)]])
            ->all();
    }

    public function test_the_usual_list_does_not_count_what_is_inside(): void
    {
        $rows = $this->listed('');

        $this->assertSame(['brick/86', 'hand/85', 'torso/86'], collect(array_keys($rows))->sort()->values()->all(), 'рук нет: они только внутри торсов');
        $this->assertSame(4, $rows['hand/85']['total'], 'только отдельно лежащие кисти');
    }

    /** Под «внутри составных» — ровно они, с обычным итогом рядом. */
    public function test_the_composite_placement_lists_what_is_inside(): void
    {
        $rows = $this->listed('placement=composite');

        $this->assertSame(['arm/86', 'hand/85'], collect(array_keys($rows))->sort()->values()->all(), 'альтернативная кисть не в счёт');
        $this->assertSame(['total' => 4, 'inside' => 4], $rows['hand/85'], 'два целых торса по две кисти и четыре отдельно');
        $this->assertSame(['total' => 0, 'inside' => 2], $rows['arm/86'], 'потерянный торс — без рук');
    }

    public function test_the_composite_placement_takes_the_search_and_colour(): void
    {
        $this->assertSame(['hand/85'], array_keys($this->listed('placement=composite&q=hand')));
        $this->assertSame(['arm/86'], array_keys($this->listed('placement=composite&color_id='.self::GRAY)));
    }

    public function test_the_export_under_it_counts_what_is_inside(): void
    {
        $xml = $this->get('/parts/export?placement=composite&as=inventory')->assertOk()->getContent();

        $this->assertStringContainsString("<ITEMID>hand</ITEMID>\n    <COLOR>85</COLOR>\n    <QTY>4</QTY>", $xml);
        $this->assertStringContainsString("<ITEMID>arm</ITEMID>\n    <COLOR>86</COLOR>\n    <QTY>2</QTY>", $xml);
    }

    /** Под этим местом «недостача» не отбирает — и не ломает список. */
    public function test_the_lost_filter_does_not_apply_inside(): void
    {
        $this->assertCount(2, $this->listed('placement=composite&lost=true'));
    }

    /** Деталь, которая есть только внутри торсов, открывается — о ней и спрашивают. */
    public function test_a_part_held_only_inside_opens_its_page(): void
    {
        $props = $this->get('/parts/arm/'.self::GRAY)->assertOk()->viewData('page')['props'];

        $this->assertSame(0, (int) $props['part']->total);
        $this->assertSame(2, $props['inside']);
        $this->assertSame('torso', $props['composites'][0]->item_id);
        $this->assertSame(2, $props['composites'][0]->held, 'два торса из трёх на руках');
        $this->assertSame(1, $props['composites'][0]->per);

        $this->get('/parts/other-hand/'.self::DARK)->assertNotFound();
    }

    public function test_a_composite_part_shows_what_it_is_made_of(): void
    {
        $composition = $this->get('/parts/torso/'.self::GRAY)->assertOk()->viewData('page')['props']['composition'];

        $this->assertSame(['arm', 'hand', 'other-hand'], collect($composition)->pluck('id')->sort()->values()->all());
        $this->assertTrue(collect($composition)->firstWhere('id', 'other-hand')['is_alternate'], 'альтернативу страница прячет сама');
    }

    public function test_a_plain_part_has_no_composition(): void
    {
        $props = $this->get('/parts/brick/'.self::GRAY)->assertOk()->viewData('page')['props'];

        $this->assertSame([], $props['composition']);
        $this->assertSame(0, $props['inside']);
    }
}
