<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use App\Collection\Models\Tag;
use App\Collection\Queries\MinifigurePlaces;
use App\Collection\Queries\MinifigureTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Minifigures are counted across the collection, one row per figure.
 *
 * The figure inside a set and the figure bought on its own are the same
 * figure, so they share a card; metadata still belongs to a copy.
 */
class MinifigureTotalsTest extends TestCase
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
            ['id' => 0, 'name' => '(Not Applicable)', 'rgb' => '000000'],
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
        ]);

        DB::table('bl_themes')->insert([
            ['id' => 1, 'name' => 'Star Wars', 'path' => 'Star Wars', 'depth' => 0],
            ['id' => 2, 'name' => 'Ultimate Collector Series', 'path' => 'Star Wars / Ultimate Collector Series', 'depth' => 1],
            ['id' => 3, 'name' => 'City', 'path' => 'City', 'depth' => 0],
        ]);

        foreach ([
            ['S', 'set-a', 1, 2020],
            ['S', 'set-b', 3, 2021],
            ['M', 'fig-a', 2, 2020],
            ['M', 'fig-b', 3, 2021],
            ['P', 'torso', null, null],
            ['P', 'legs', null, null],
        ] as [$type, $id, $theme, $year]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'theme_id' => $theme, 'year' => $year,
                'image_color_id' => 0, 'has_inventory' => 0,
            ]);
        }
    }

    private function entry(string $type, string $id): Entry
    {
        return Entry::create(['item_type' => $type, 'item_id' => $id]);
    }

    /** A figure sitting inside an owned copy — a set's figure has no parent. */
    private function figure(Entry $entry, string $id, array $attributes = []): int
    {
        return DB::table('collection_items')->insertGetId(array_merge([
            'entry_id' => $entry->id,
            'item_type' => 'M',
            'item_id' => $id,
            'color_id' => 0,
            'qty' => 1,
            'lost_qty' => 0,
            'counts' => 1,
            'parent_item_type' => null,
        ], $attributes));
    }

    private function part(Entry $entry, string $id, int $parentId, array $attributes = []): void
    {
        DB::table('collection_items')->insert(array_merge([
            'entry_id' => $entry->id,
            'item_type' => 'P',
            'item_id' => $id,
            'color_id' => 11,
            'qty' => 1,
            'lost_qty' => 0,
            'counts' => 1,
            'parent_id' => $parentId,
            'parent_item_type' => 'M',
        ], $attributes));
    }

    /**
     * The figures of a set are root rows of that set's tree, so "built into
     * something" cannot be read off the presence of a parent. Testing for one
     * counted every set's figures as loose.
     */
    public function test_a_set_figure_counts_as_in_a_set_although_it_has_no_parent(): void
    {
        $set = $this->entry('S', 'set-a');
        $this->figure($set, 'fig-a');

        $figure = app(MinifigureTotals::class)->one('fig-a');

        $this->assertSame(1, (int) $figure->total);
        $this->assertSame(1, (int) $figure->in_sets);
        $this->assertSame(0, (int) $figure->loose);
    }

    public function test_the_same_figure_from_a_set_and_from_a_shelf_share_one_row(): void
    {
        $set = $this->entry('S', 'set-a');
        $this->figure($set, 'fig-a', ['qty' => 2]);

        $own = $this->entry('M', 'fig-a');
        $this->figure($own, 'fig-a');

        $figure = app(MinifigureTotals::class)->one('fig-a');

        $this->assertSame(3, (int) $figure->total);
        $this->assertSame(2, (int) $figure->in_sets);
        $this->assertSame(1, (int) $figure->loose);
        $this->assertSame(1, (int) $figure->entries, 'one set holds it');
    }

    public function test_it_counts_the_copies_a_figure_is_built_into(): void
    {
        foreach (['set-a', 'set-b'] as $id) {
            $this->figure($this->entry('S', $id), 'fig-a');
        }

        $this->assertSame(2, (int) app(MinifigureTotals::class)->one('fig-a')->entries);
    }

    public function test_it_filters_by_where_the_figure_sits(): void
    {
        $this->figure($this->entry('S', 'set-a'), 'fig-a');
        $this->figure($this->entry('M', 'fig-b'), 'fig-b');

        $ids = fn (string $placement) => collect(
            app(MinifigureTotals::class)->filters(['placement' => $placement])->paginate()->items()
        )->pluck('item_id')->all();

        $this->assertSame(['fig-a'], $ids('set'));
        $this->assertSame(['fig-b'], $ids('loose'));
    }

    /**
     * A figure that is only a spare or an alternate is not held, and its row
     * must not reach the list. A second havingRaw is joined with AND, so the
     * first one has to be parenthesised or the placement filter lets
     * everything through.
     */
    public function test_a_figure_that_does_not_count_is_not_listed(): void
    {
        $set = $this->entry('S', 'set-a');
        $this->figure($set, 'fig-a', ['is_alternate' => 1, 'counts' => 0]);

        $this->assertNull(app(MinifigureTotals::class)->one('fig-a'));
        $this->assertCount(0, app(MinifigureTotals::class)->filters(['placement' => 'set'])->paginate()->items());
    }

    /**
     * A set tagged "for sale" says nothing about the figure inside it, so the
     * tag filter looks at standalone copies only.
     */
    public function test_the_tag_filter_looks_only_at_standalone_copies(): void
    {
        $tag = Tag::create(['name' => 'For sale', 'color' => 'primary', 'sort' => 1]);

        $set = $this->entry('S', 'set-a');
        $set->tags()->attach($tag->id);
        $this->figure($set, 'fig-a');

        $own = $this->entry('M', 'fig-b');
        $own->tags()->attach($tag->id);
        $this->figure($own, 'fig-b');

        $listed = collect(
            app(MinifigureTotals::class)->filters(['tag_id' => $tag->id])->paginate()->items()
        )->pluck('item_id')->all();

        $this->assertSame(['fig-b'], $listed);
    }

    /**
     * Filtering rows before the grouping would drop the in-set rows of a
     * tagged figure and quietly change every count on its card.
     */
    public function test_a_tagged_figure_keeps_the_count_of_its_copies_in_sets(): void
    {
        $tag = Tag::create(['name' => 'For sale', 'color' => 'primary', 'sort' => 1]);

        $this->figure($this->entry('S', 'set-a'), 'fig-a', ['qty' => 3]);

        $own = $this->entry('M', 'fig-a');
        $own->tags()->attach($tag->id);
        $this->figure($own, 'fig-a');

        $figure = collect(
            app(MinifigureTotals::class)->filters(['tag_id' => $tag->id])->paginate()->items()
        )->first();

        $this->assertSame(4, (int) $figure->total);
        $this->assertSame(3, (int) $figure->in_sets);
    }

    public function test_the_theme_filter_reaches_a_subtheme(): void
    {
        $this->figure($this->entry('S', 'set-a'), 'fig-a');
        $this->figure($this->entry('S', 'set-b'), 'fig-b');

        $listed = collect(
            app(MinifigureTotals::class)->filters(['theme_id' => 1])->paginate()->items()
        )->pluck('item_id')->all();

        $this->assertSame(['fig-a'], $listed, 'fig-a sits in a subtheme of Star Wars');
    }

    public function test_the_facets_hold_only_what_the_collection_has(): void
    {
        $this->figure($this->entry('S', 'set-a'), 'fig-a');

        $facets = app(MinifigureTotals::class)->facets();

        $this->assertSame(['Star Wars'], array_column($facets['themes'], 'path'));
        $this->assertSame([2020], $facets['years']);
    }

    public function test_it_lists_what_a_figure_is_made_of(): void
    {
        $set = $this->entry('S', 'set-a');
        $figId = $this->figure($set, 'fig-a');
        $this->part($set, 'torso', $figId);
        $this->part($set, 'legs', $figId);

        $parts = (new MinifigurePlaces('fig-a'))->parts();

        $this->assertSame(['legs', 'torso'], $parts->pluck('item_id')->sort()->values()->all());
        $this->assertSame(1, (int) $parts->firstWhere('item_id', 'torso')->qty);
    }

    /**
     * A boxed series holds thirty-six of the same packet, and its rows carry
     * that multiplier. "What is one figure made of" divides it back out.
     */
    public function test_the_parts_of_a_figure_are_per_figure_not_per_box(): void
    {
        $box = $this->entry('S', 'set-a');
        $figId = $this->figure($box, 'fig-a', ['qty' => 6]);
        $this->part($box, 'torso', $figId, ['qty' => 6]);

        $parts = (new MinifigurePlaces('fig-a'))->parts();

        $this->assertSame(1, (int) $parts->firstWhere('item_id', 'torso')->qty);
    }

    public function test_it_lists_the_copies_a_figure_is_built_into(): void
    {
        $this->figure($this->entry('S', 'set-a'), 'fig-a', ['qty' => 2]);
        $this->figure($this->entry('M', 'fig-a'), 'fig-a');

        $places = (new MinifigurePlaces('fig-a'))->inEntries();

        $this->assertCount(1, $places, 'the standalone copy is not a place a figure sits in');
        $this->assertSame('set-a', $places[0]->item_id);
        $this->assertSame(2, (int) $places[0]->qty);
    }

    public function test_it_lists_where_a_figure_is_missing(): void
    {
        $this->figure($this->entry('S', 'set-a'), 'fig-a', ['qty' => 2, 'lost_qty' => 1]);
        $this->figure($this->entry('S', 'set-b'), 'fig-a');

        $missing = (new MinifigurePlaces('fig-a'))->missingIn();

        $this->assertCount(1, $missing);
        $this->assertSame('set-a', $missing[0]->item_id);
        $this->assertSame(1, (int) $missing[0]->lost);
    }

    public function test_the_pages_render(): void
    {
        $set = $this->entry('S', 'set-a');
        $this->figure($set, 'fig-a');

        $own = $this->entry('M', 'fig-b');
        $figId = $this->figure($own, 'fig-b');
        $this->part($own, 'torso', $figId);

        $this->get('/minifigures')->assertOk();
        $this->get('/minifigures/fig-a')->assertOk();
        $this->get('/minifigures/nothing')->assertNotFound();

        $this->get("/minifigures/copy/{$own->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Minifigures/Copy')
                ->where('entry.item_id', 'fig-b')
                // The figure's own row is the root of the tree; showing it as
                // a group would have the figure contain itself.
                ->has('parts', 1)
                ->where('parts.0.item_id', 'torso'));
    }

    /** A set is not a minifigure copy, whatever id is put in the path. */
    public function test_a_set_cannot_be_opened_as_a_minifigure_copy(): void
    {
        $set = $this->entry('S', 'set-a');

        $this->get("/minifigures/copy/{$set->id}")->assertNotFound();
        $this->patchJson("/minifigures/copy/{$set->id}", ['price' => 100])->assertNotFound();
        $this->delete("/minifigures/copy/{$set->id}")->assertNotFound();
    }

    public function test_a_copy_keeps_its_own_metadata(): void
    {
        $tag = Tag::create(['name' => 'For sale', 'color' => 'primary', 'sort' => 1]);
        $own = $this->entry('M', 'fig-b');
        $this->figure($own, 'fig-b');

        $this->patchJson("/minifigures/copy/{$own->id}", [
            'acquired_at' => '2024-03-01',
            'price' => 129900,
            'note' => 'from a flea market',
            'tag_ids' => [$tag->id],
        ])->assertOk();

        $own->refresh();

        $this->assertSame('2024-03-01', $own->acquired_at->format('Y-m-d'));
        $this->assertSame(129900, $own->price);
        $this->assertSame([$tag->id], $own->tags()->pluck('ref_tags.id')->all());
    }

    public function test_removing_a_copy_returns_to_the_figure(): void
    {
        $own = $this->entry('M', 'fig-b');
        $this->figure($own, 'fig-b');

        $this->delete("/minifigures/copy/{$own->id}")
            ->assertRedirect('/minifigures/fig-b');

        $this->assertDatabaseMissing('collection_entries', ['id' => $own->id]);
    }
}
