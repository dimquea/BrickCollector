<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use App\Collection\Queries\PartPlaces;
use App\Collection\Queries\PartTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Parts are counted across the collection rather than listed per copy.
 */
class PartTotalsTest extends TestCase
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
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
            ['id' => 5, 'name' => 'Red', 'rgb' => 'B30006'],
        ]);

        foreach ([['S', 'set-a'], ['S', 'set-b'], ['M', 'fig'], ['P', 'brick'], ['P', 'other']] as [$type, $id]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'image_color_id' => 0, 'has_inventory' => 0,
            ]);
        }
    }

    private function entry(string $type, string $id): Entry
    {
        return Entry::create(['item_type' => $type, 'item_id' => $id]);
    }

    private function lot(Entry $entry, array $attributes): int
    {
        return DB::table('collection_items')->insertGetId(array_merge([
            'entry_id' => $entry->id,
            'item_type' => 'P',
            'color_id' => 11,
            'qty' => 1,
            'lost_qty' => 0,
            'counts' => 1,
            'parent_item_type' => 'S',
        ], $attributes));
    }

    public function test_it_sums_one_part_across_several_copies(): void
    {
        $a = $this->entry('S', 'set-a');
        $b = $this->entry('S', 'set-b');

        $this->lot($a, ['item_id' => 'brick', 'qty' => 4]);
        $this->lot($b, ['item_id' => 'brick', 'qty' => 3]);

        $part = app(PartTotals::class)->one('brick', 11);

        $this->assertSame(7, (int) $part->total);
        $this->assertSame(7, (int) $part->in_sets);
    }

    public function test_it_keeps_colours_apart(): void
    {
        $a = $this->entry('S', 'set-a');

        $this->lot($a, ['item_id' => 'brick', 'color_id' => 11, 'qty' => 4]);
        $this->lot($a, ['item_id' => 'brick', 'color_id' => 5, 'qty' => 2]);

        $this->assertSame(4, (int) app(PartTotals::class)->one('brick', 11)->total);
        $this->assertSame(2, (int) app(PartTotals::class)->one('brick', 5)->total);
    }

    public function test_it_splits_the_count_by_where_the_part_sits(): void
    {
        $set = $this->entry('S', 'set-a');
        $loose = $this->entry('P', 'brick');

        $this->lot($set, ['item_id' => 'brick', 'qty' => 4]);
        $this->lot($set, ['item_id' => 'brick', 'qty' => 2, 'parent_item_type' => 'M']);
        $this->lot($loose, ['item_id' => 'brick', 'qty' => 50, 'parent_item_type' => null]);

        $part = app(PartTotals::class)->one('brick', 11);

        $this->assertSame(56, (int) $part->total);
        $this->assertSame(4, (int) $part->in_sets);
        $this->assertSame(2, (int) $part->in_minifigures);
        $this->assertSame(50, (int) $part->loose);
    }

    /**
     * A spare brick is in the box, so it keeps a row, but it stays out of the
     * quantity that a set's completeness is judged by.
     */
    public function test_spares_are_counted_apart_from_the_total(): void
    {
        $set = $this->entry('S', 'set-a');

        $this->lot($set, ['item_id' => 'brick', 'qty' => 4]);
        $this->lot($set, ['item_id' => 'brick', 'qty' => 2, 'is_extra' => 1, 'counts' => 0]);

        $part = app(PartTotals::class)->one('brick', 11);

        $this->assertSame(4, (int) $part->total);
        $this->assertSame(2, (int) $part->spares);
        $this->assertSame(1, (int) $part->has_extra);
    }

    /**
     * An alternate is the version that was not used, so a part that only ever
     * appears as one is not held and must not be listed.
     */
    public function test_a_part_that_is_only_an_alternate_is_not_listed(): void
    {
        $set = $this->entry('S', 'set-a');

        $this->lot($set, ['item_id' => 'other', 'qty' => 1, 'is_alternate' => 1, 'counts' => 0]);

        $this->assertNull(app(PartTotals::class)->one('other', 11));
    }

    public function test_it_filters_by_where_the_part_sits(): void
    {
        $set = $this->entry('S', 'set-a');
        $loose = $this->entry('P', 'other');

        $this->lot($set, ['item_id' => 'brick', 'qty' => 4]);
        $this->lot($loose, ['item_id' => 'other', 'qty' => 9, 'parent_item_type' => null]);

        $ids = fn (string $placement) => collect(
            app(PartTotals::class)->filters(['placement' => $placement])->paginate()->items()
        )->pluck('item_id')->all();

        $this->assertSame(['brick'], $ids('set'));
        $this->assertSame(['other'], $ids('loose'));
    }

    /**
     * Недостача — не место, а свойство места.
     *
     * Из набора деталь пропала, а в сборке её может не хватать, чтобы модель
     * была закончена: одно и то же поле значит разное, и отличает их только то,
     * где деталь лежит. Поэтому переключатель сужает выбранное место, а не
     * заменяет его собой.
     */
    public function test_the_shortage_filter_scopes_to_where_the_part_is(): void
    {
        $set = $this->entry('S', 'set-a');
        $assembly = Entry::create(['name' => 'Moon base']);

        $this->lot($set, ['item_id' => 'brick', 'qty' => 4, 'lost_qty' => 1]);
        $this->lot($assembly, [
            'item_id' => 'other', 'qty' => 2, 'lost_qty' => 2, 'parent_item_type' => null,
        ]);

        $ids = fn (array $filters) => collect(
            app(PartTotals::class)->filters($filters)->paginate()->items()
        )->pluck('item_id')->all();

        $this->assertSame(['brick', 'other'], $ids(['lost' => true]), 'без места — вся недостача');
        $this->assertSame(['brick'], $ids(['lost' => true, 'placement' => 'set']));
        $this->assertSame(['other'], $ids(['lost' => true, 'placement' => 'assembly']));
        $this->assertSame([], $ids(['lost' => true, 'placement' => 'loose']));

        // Место без недостачи по-прежнему отбирает всё, что там лежит.
        $this->assertSame(['other'], $ids(['placement' => 'assembly']));
    }

    /**
     * The tab counting which copies hold a part must count that part, not
     * every part in the collection. Chaining orWhere without grouping it
     * escapes the constraints and reported 71 of a brick there were two of.
     */
    public function test_the_places_tab_counts_only_this_part(): void
    {
        $a = $this->entry('S', 'set-a');
        $b = $this->entry('S', 'set-b');

        $this->lot($a, ['item_id' => 'brick', 'qty' => 2]);
        $this->lot($a, ['item_id' => 'other', 'qty' => 40]);
        $this->lot($b, ['item_id' => 'other', 'qty' => 30]);

        $places = (new PartPlaces('brick', 11))->inEntries();

        $this->assertCount(1, $places);
        $this->assertSame(2, $places[0]['qty']);
    }

    public function test_it_lists_the_minifigures_a_part_is_built_into(): void
    {
        $set = $this->entry('S', 'set-a');
        $figId = DB::table('collection_items')->insertGetId([
            'entry_id' => $set->id, 'item_type' => 'M', 'item_id' => 'fig',
            'color_id' => 0, 'qty' => 1, 'lost_qty' => 0, 'counts' => 1,
        ]);

        $this->lot($set, [
            'item_id' => 'brick', 'qty' => 3,
            'parent_id' => $figId, 'parent_item_type' => 'M',
        ]);

        $figures = (new PartPlaces('brick', 11))->inMinifigures();

        $this->assertCount(1, $figures);
        $this->assertSame('fig', $figures[0]->item_id);
        $this->assertSame(3, (int) $figures[0]->qty);
    }

    public function test_it_lists_where_a_part_is_missing(): void
    {
        $a = $this->entry('S', 'set-a');
        $b = $this->entry('S', 'set-b');

        $this->lot($a, ['item_id' => 'brick', 'qty' => 4, 'lost_qty' => 1]);
        $this->lot($b, ['item_id' => 'brick', 'qty' => 2]);

        $missing = (new PartPlaces('brick', 11))->missingIn();

        $this->assertCount(1, $missing);
        $this->assertSame('set-a', $missing[0]['item_id']);
        $this->assertSame(1, $missing[0]['lost']);
    }

    public function test_the_pages_render(): void
    {
        $set = $this->entry('S', 'set-a');
        $this->lot($set, ['item_id' => 'brick', 'qty' => 4]);

        $this->get('/parts')->assertOk();
        $this->get('/parts/brick/11')->assertOk();
        $this->get('/parts/brick/5')->assertNotFound();
    }
}
