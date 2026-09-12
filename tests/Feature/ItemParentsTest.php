<?php

namespace Tests\Feature;

use App\Catalog\Queries\ItemParents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Part of" — the catalogue inventory read backwards.
 */
class ItemParentsTest extends TestCase
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
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
            ['id' => 5, 'name' => 'Red', 'rgb' => 'B30006'],
            ['id' => 1, 'name' => 'White', 'rgb' => 'FFFFFF'],
        ]);

        foreach ([['S', 'set-a', 2009], ['S', 'set-b', 1998], ['M', 'fig', null], ['P', 'brick', null], ['P', 'lonely', null]] as [$type, $id, $year]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id), 'year' => $year,
                'image_color_id' => 0, 'has_inventory' => 0,
            ]);
        }

        foreach ([
            ['S', 'set-a', 'P', 'brick', 11, 4],
            ['S', 'set-a', 'P', 'brick', 5, 1],
            ['S', 'set-b', 'P', 'brick', 11, 2],
            ['M', 'fig', 'P', 'brick', 5, 1],
            ['S', 'set-a', 'M', 'fig', 0, 1],
        ] as [$pt, $pi, $ct, $ci, $colour, $qty]) {
            DB::table('bl_inventory')->insert([
                'parent_type' => $pt, 'parent_id' => $pi,
                'child_type' => $ct, 'child_id' => $ci,
                'color_id' => $colour, 'qty' => $qty,
            ]);
        }
    }

    public function test_it_counts_parents_by_kind(): void
    {
        $this->assertSame(
            ['S' => 2, 'M' => 1],
            app(ItemParents::class)->kinds('P', 'brick'),
        );
    }

    /** The colour is the third column of the index, so filtering by it is free. */
    public function test_a_colour_narrows_the_count(): void
    {
        $parents = app(ItemParents::class);

        $this->assertSame(['S' => 2], $parents->kinds('P', 'brick', 11));
        $this->assertSame(['S' => 1, 'M' => 1], $parents->kinds('P', 'brick', 5));
        $this->assertSame([], $parents->kinds('P', 'brick', 1));
    }

    public function test_a_page_carries_names_and_quantities(): void
    {
        $rows = app(ItemParents::class)->page('P', 'brick', 'S', null, 2, 1)->items();

        $this->assertSame(['set-a', 'set-b'], array_column($rows, 'id'));
        $this->assertSame('Set-a', $rows[0]['name']);
        $this->assertSame(2009, $rows[0]['year']);
        $this->assertSame(5, $rows[0]['qty'], 'both colours of the brick in that set');

        $filtered = app(ItemParents::class)->page('P', 'brick', 'S', 11, 2, 1)->items();

        $this->assertSame(4, $filtered[0]['qty'], 'only the black ones');
    }

    public function test_the_colour_filter_offers_only_colours_it_is_used_in(): void
    {
        $colours = app(ItemParents::class)->colours('brick');

        $this->assertSame([11, 5], array_column($colours, 'id'), 'Black and Red, by name');
        $this->assertSame(['Black', 'Red'], array_column($colours, 'name'));
    }

    public function test_the_part_page_shows_what_it_is_part_of(): void
    {
        $this->get('/catalog/P/brick')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('parents.kind', 'S')
                ->where('parents.kinds.0.code', 'S')
                ->where('parents.kinds.0.count', 2)
                ->where('parents.kinds.1.code', 'M')
                ->has('parents.rows.data', 2)
                ->has('parents.colours', 2));
    }

    public function test_a_minifigure_page_shows_the_sets_it_is_in(): void
    {
        $this->get('/catalog/M/fig')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('parents.kinds.0.count', 1)
                ->where('parents.rows.data.0.id', 'set-a')
                // A figure is in a set as itself, not in a colour.
                ->where('parents.colours', []));
    }

    public function test_the_block_is_absent_when_nothing_lists_the_item(): void
    {
        $this->get('/catalog/P/lonely')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('parents', null));
    }

    /**
     * Under a colour it is not used in, the block stays: without it there
     * would be no way back to another colour.
     */
    public function test_an_empty_colour_keeps_the_block(): void
    {
        $this->get('/catalog/P/brick?in_color=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('parents.colour', 1)
                ->where('parents.kinds', [])
                ->where('parents.rows', []));
    }

    /** A colour filter can empty the tab that was open; the block opens another. */
    public function test_a_tab_that_the_colour_emptied_gives_way(): void
    {
        $this->get('/catalog/P/brick?in=M&in_color=11')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('parents.kind', 'S'));
    }

    public function test_it_pages_and_keeps_the_filter_in_the_links(): void
    {
        for ($i = 0; $i < ItemParents::PER_PAGE; $i++) {
            $id = sprintf('bulk-%03d', $i);

            DB::table('bl_items')->insert([
                'type' => 'S', 'id' => $id, 'name' => $id, 'image_color_id' => 0, 'has_inventory' => 0,
            ]);
            DB::table('bl_inventory')->insert([
                'parent_type' => 'S', 'parent_id' => $id,
                'child_type' => 'P', 'child_id' => 'brick',
                'color_id' => 11, 'qty' => 1,
            ]);
        }

        $this->get('/catalog/P/brick?in_color=11')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('parents.rows.total', ItemParents::PER_PAGE + 2)
                ->has('parents.rows.data', ItemParents::PER_PAGE)
                ->where('parents.rows.data.0.id', 'bulk-000')
                ->where('parents.rows.links.1.url', fn ($url) => str_contains($url, 'in_color=11')));

        $this->get('/catalog/P/brick?in_color=11&in_page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('parents.rows.data', 2)
                ->where('parents.rows.data.0.id', 'set-a'));
    }
}
