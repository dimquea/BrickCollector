<?php

namespace Tests\Feature;

use App\Catalog\Queries\SearchItems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchItemsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        DB::table('bl_categories')->insert(['id' => 1, 'name' => 'Star Wars']);

        DB::table('bl_themes')->insert([
            ['id' => 1, 'parent_id' => null, 'root_category_id' => 1, 'name' => 'Star Wars', 'path' => 'Star Wars', 'depth' => 0],
            ['id' => 2, 'parent_id' => 1, 'root_category_id' => 1, 'name' => 'Ultimate Collector Series', 'path' => 'Star Wars / Ultimate Collector Series', 'depth' => 1],
            ['id' => 3, 'parent_id' => null, 'root_category_id' => 1, 'name' => 'Town', 'path' => 'Town', 'depth' => 0],
        ]);

        $this->item('S', '75192-1', 'Millennium Falcon - UCS', 2, 2017);
        $this->item('S', '7190-1', 'Millennium Falcon', 1, 2000);
        $this->item('S', '10270-1', 'Bookshop', 3, 2020);
        $this->item('P', '3001', 'Brick 2 x 4', null, null);
    }

    private function item(string $type, string $id, string $name, ?int $themeId, ?int $year): void
    {
        DB::table('bl_items')->insert([
            'type' => $type, 'id' => $id, 'name' => $name, 'category_id' => 1,
            'theme_id' => $themeId, 'year' => $year, 'image_color_id' => 0, 'has_inventory' => 1,
        ]);

        DB::table('bl_items_fts')->insert([
            'name' => $name, 'ident' => $id, 'type' => $type, 'item_id' => $id,
        ]);
    }

    /** @return string[] "type:id" of every result */
    private function search(array $filters): array
    {
        return collect((new SearchItems)->filters($filters)->paginate()->items())
            ->map(fn ($item) => $item->type.':'.$item->id)
            ->all();
    }

    public function test_it_finds_items_by_name(): void
    {
        // Order between two equally good name matches is bm25's business, not
        // a contract: it prefers the shorter text, which is reasonable.
        $this->assertEqualsCanonicalizing(
            ['S:75192-1', 'S:7190-1'],
            $this->search(['q' => 'millennium falcon']),
        );
    }

    /** People type item numbers at least as often as names. */
    public function test_it_finds_items_by_number(): void
    {
        $this->assertSame(['S:10270-1'], $this->search(['q' => '10270']));
        $this->assertSame(['P:3001'], $this->search(['q' => '3001']));
    }

    /** The number is weighted above the name, so an exact number wins. */
    public function test_the_matching_number_outranks_a_name_match(): void
    {
        $results = $this->search(['q' => '75192-1']);

        $this->assertSame('S:75192-1', $results[0]);
    }

    public function test_a_partial_word_matches_while_typing(): void
    {
        $this->assertNotEmpty($this->search(['q' => 'millen']));
    }

    /**
     * FTS5 has its own query syntax. Anything a person types must be treated
     * as text, never executed and never allowed to raise a syntax error.
     */
    public function test_it_treats_fts_syntax_as_plain_text(): void
    {
        foreach (['OR', '-x*', 'brick"', '"', '*', 'a AND b', 'NEAR(a b)', ''] as $term) {
            $this->assertIsArray($this->search(['q' => $term]), "term [{$term}] broke the query");
        }
    }

    /** Choosing a root theme must include everything beneath it. */
    public function test_a_theme_filter_covers_the_whole_subtree(): void
    {
        $results = $this->search(['theme_id' => 1]);

        $this->assertContains('S:7190-1', $results, 'the item sitting at the root itself');
        $this->assertContains('S:75192-1', $results, 'the item in a child theme');
        $this->assertNotContains('S:10270-1', $results, 'an item from another theme');
    }

    public function test_filters_combine(): void
    {
        $this->assertSame(['S:7190-1'], $this->search(['q' => 'millennium', 'year' => 2000]));
        $this->assertSame([], $this->search(['q' => 'millennium', 'type' => 'P']));
    }

    public function test_it_lists_everything_without_a_query(): void
    {
        $this->assertCount(4, $this->search([]));
        $this->assertCount(3, $this->search(['type' => 'S']));
    }
}
