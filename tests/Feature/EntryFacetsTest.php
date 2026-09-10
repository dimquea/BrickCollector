<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use App\Collection\Queries\EntryFacets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Filter options come from what is owned, never from the catalog.
 *
 * A filter offering a value that returns nothing looks like the collection
 * lost something.
 */
class EntryFacetsTest extends TestCase
{
    use RefreshDatabase;

    private const SECTION = ['S', 'G', 'B', 'C'];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'G', 'name' => 'Gear'],
            ['code' => 'M', 'name' => 'Minifigure'],
        ]);

        DB::table('bl_categories')->insert([['id' => 1, 'name' => 'Star Wars']]);

        DB::table('bl_themes')->insert([
            ['id' => 1, 'parent_id' => null, 'root_category_id' => 1, 'name' => 'Star Wars', 'path' => 'Star Wars', 'depth' => 0],
            ['id' => 2, 'parent_id' => 1, 'root_category_id' => 1, 'name' => 'UCS', 'path' => 'Star Wars / UCS', 'depth' => 1],
            ['id' => 3, 'parent_id' => null, 'root_category_id' => 1, 'name' => 'Town', 'path' => 'Town', 'depth' => 0],
        ]);

        $this->item('S', 'owned-ucs', 2, 2017);
        $this->item('S', 'owned-sw', 1, 2008);
        $this->item('S', 'not-owned', 3, 1999);
        $this->item('G', 'owned-gear', null, 2020);
        $this->item('M', 'owned-fig', 1, 2021);
    }

    private function item(string $type, string $id, ?int $themeId, ?int $year): void
    {
        DB::table('bl_items')->insert([
            'type' => $type, 'id' => $id, 'name' => $id,
            'theme_id' => $themeId, 'year' => $year, 'image_color_id' => 0, 'has_inventory' => 0,
        ]);
    }

    private function own(string $type, string $id): void
    {
        Entry::create(['item_type' => $type, 'item_id' => $id]);
    }

    public function test_it_offers_only_the_types_that_are_owned(): void
    {
        $this->own('S', 'owned-sw');

        $facets = (new EntryFacets(self::SECTION))->all();

        $this->assertSame(['S'], array_column($facets['types'], 'code'));

        $this->own('G', 'owned-gear');

        $this->assertEqualsCanonicalizing(
            ['S', 'G'],
            array_column((new EntryFacets(self::SECTION))->all()['types'], 'code'),
        );
    }

    /** A minifigure belongs to another section and must not leak in. */
    public function test_it_ignores_types_outside_the_section(): void
    {
        $this->own('M', 'owned-fig');

        $facets = (new EntryFacets(self::SECTION))->all();

        $this->assertSame([], $facets['types']);
        $this->assertSame([], $facets['years']);
    }

    /**
     * Choosing a theme matches its subtree, so a set filed under a sub-theme
     * is offered as its root — once, however many sub-themes are involved.
     */
    public function test_it_offers_the_root_of_a_sub_theme_once(): void
    {
        $this->own('S', 'owned-ucs');
        $this->own('S', 'owned-sw');

        $facets = (new EntryFacets(self::SECTION))->all();

        $this->assertSame(['Star Wars'], array_column($facets['themes'], 'path'));
    }

    public function test_it_offers_only_the_years_that_are_owned_newest_first(): void
    {
        $this->own('S', 'owned-ucs');
        $this->own('S', 'owned-sw');

        $this->assertSame([2017, 2008], (new EntryFacets(self::SECTION))->all()['years']);
    }

    public function test_an_empty_collection_offers_nothing(): void
    {
        $facets = (new EntryFacets(self::SECTION))->all();

        $this->assertSame([], $facets['types']);
        $this->assertSame([], $facets['themes']);
        $this->assertSame([], $facets['years']);
    }

    public function test_the_page_ships_the_facets(): void
    {
        $this->own('S', 'owned-ucs');

        $this->get('/sets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('themes.0.path', 'Star Wars')
                ->where('years.0', 2017));
    }
}
