<?php

namespace Tests\Feature;

use App\Catalog\Queries\ItemInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItemInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'P', 'name' => 'Part'],
            ['code' => 'M', 'name' => 'Minifigure'],
        ]);

        DB::table('bl_colors')->insert([
            ['id' => 0, 'name' => '(Not Applicable)', 'rgb' => null],
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
        ]);

        // A boxed series: set -> packet -> minifigure -> parts, plus an
        // assembled part that must stay unexpanded.
        foreach ([
            ['S', 'box-1', 'Series Box'],
            ['S', 'packet-1', 'Packet'],
            ['M', 'fig-1', 'Figure'],
            ['P', 'torso', 'Torso'],
            ['P', 'arm', 'Arm'],
            ['P', 'stand', 'Stand'],
            ['P', 'spare', 'Spare tile'],
        ] as [$type, $id, $name]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => $name,
                'image_color_id' => 0, 'has_inventory' => 1,
            ]);
        }

        $this->lot('S', 'box-1', 'S', 'packet-1', 1);
        $this->lot('S', 'packet-1', 'P', 'stand', 2);
        $this->lot('S', 'packet-1', 'M', 'fig-1', 1);
        $this->lot('S', 'packet-1', 'P', 'spare', 3, ['is_extra' => 1]);
        $this->lot('M', 'fig-1', 'P', 'torso', 1, ['color_id' => 11]);
        // The catalog breaks a torso down into arms. Physically it is one part.
        $this->lot('P', 'torso', 'P', 'arm', 2);
    }

    private function lot(string $pt, string $pi, string $ct, string $ci, int $qty, array $extra = []): void
    {
        DB::table('bl_inventory')->insert(array_merge([
            'parent_type' => $pt, 'parent_id' => $pi,
            'child_type' => $ct, 'child_id' => $ci,
            'color_id' => 0, 'qty' => $qty,
        ], $extra));
    }

    public function test_it_expands_subsets_and_minifigures(): void
    {
        $tree = app(ItemInventory::class)->tree('S', 'box-1');

        $this->assertCount(1, $tree);
        $this->assertSame('packet-1', $tree[0]['id']);

        $packet = $tree[0]['children'];
        $this->assertCount(3, $packet);

        $figure = collect($packet)->firstWhere('id', 'fig-1');
        $this->assertCount(1, $figure['children'], 'the minifigure is expanded');
        $this->assertSame('torso', $figure['children'][0]['id']);
    }

    /**
     * The rule that keeps part counts meaningful: a torso is one part, not the
     * pair of arms the catalog lists it as.
     */
    public function test_it_does_not_expand_assembled_parts(): void
    {
        $tree = app(ItemInventory::class)->tree('S', 'box-1');


        $figure = collect($tree[0]['children'])->firstWhere('id', 'fig-1');

        $this->assertSame([], $figure['children'][0]['children'], 'the torso keeps no children');
    }

    public function test_it_carries_colour_and_flags(): void
    {
        $tree = app(ItemInventory::class)->tree('S', 'box-1');
        $packet = $tree[0]['children'];

        $spare = collect($packet)->firstWhere('id', 'spare');
        $this->assertTrue($spare['is_extra']);

        $figure = collect($packet)->firstWhere('id', 'fig-1');
        $this->assertSame('Black', $figure['children'][0]['color_name']);
        $this->assertSame('2E2E2E', $figure['children'][0]['color_rgb']);
    }

    /** Spares are attached to the item but must not inflate the part count. */
    public function test_totals_exclude_spares(): void
    {
        $query = app(ItemInventory::class);
        $totals = $query->summarise($query->tree('S', 'box-1'));

        $this->assertSame(3, $totals['parts'], '2 stands + 1 torso, not the 3 spares');
        $this->assertSame(3, $totals['extras']);
        $this->assertSame(1, $totals['minifigures']);
        $this->assertSame(1, $totals['subsets']);
    }

    /**
     * The catalog is regenerated automatically; a release that made an item
     * contain itself must not hang the page.
     */
    public function test_it_survives_a_cycle(): void
    {
        $this->lot('S', 'packet-1', 'S', 'box-1', 1);

        $tree = app(ItemInventory::class)->tree('S', 'box-1');

        $loop = collect($tree[0]['children'])->firstWhere('id', 'box-1');
        $this->assertSame([], $loop['children'], 'the repeat of an ancestor is left unexpanded');
    }
}
