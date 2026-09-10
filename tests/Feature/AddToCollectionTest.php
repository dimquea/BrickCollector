<?php

namespace Tests\Feature;

use App\Catalog\Models\Item as CatalogItem;
use App\Collection\Actions\AddToCollection;
use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddToCollectionTest extends TestCase
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

        DB::table('bl_colors')->insert([['id' => 0, 'name' => '(Not Applicable)', 'rgb' => null]]);

        foreach ([
            ['S', 'box', 'Box of 36'],
            ['S', 'random', 'Random packet'],
            ['S', 'packet-a', 'Packet A'],
            ['S', 'packet-b', 'Packet B'],
            ['M', 'fig-a', 'Figure A'],
            ['M', 'fig-b', 'Figure B'],
            ['P', 'part-a', 'Part A'],
            ['P', 'part-b', 'Part B'],
            ['P', 'spare', 'Spare'],
            ['P', 'loose', 'Loose part'],
        ] as [$type, $id, $name]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => $name,
                'image_color_id' => 0, 'has_inventory' => $id === 'loose' ? 0 : 1,
            ]);
        }

        // A box of 36 random packets, exactly how BrickLink models one: the
        // packet set lists every possibility, all but the first flagged as
        // alternates of the same match group.
        $this->lot('S', 'box', 'S', 'random', 36);
        $this->lot('S', 'random', 'S', 'packet-a', 1, ['match_id' => 1]);
        $this->lot('S', 'random', 'S', 'packet-b', 1, ['is_alternate' => 1, 'match_id' => 1]);
        $this->lot('S', 'packet-a', 'M', 'fig-a', 1);
        $this->lot('S', 'packet-a', 'P', 'spare', 2, ['is_extra' => 1]);
        $this->lot('S', 'packet-b', 'M', 'fig-b', 1);
        $this->lot('M', 'fig-a', 'P', 'part-a', 3);
        $this->lot('M', 'fig-b', 'P', 'part-b', 3);
    }

    private function lot(string $pt, string $pi, string $ct, string $ci, int $qty, array $extra = []): void
    {
        DB::table('bl_inventory')->insert(array_merge([
            'parent_type' => $pt, 'parent_id' => $pi,
            'child_type' => $ct, 'child_id' => $ci,
            'color_id' => 0, 'qty' => $qty,
        ], $extra));
    }

    private function add(string $type, string $id, array $meta = []): Entry
    {
        return app(AddToCollection::class)->handle(
            CatalogItem::where('type', $type)->where('id', $id)->first(),
            $meta,
        );
    }

    private function counted(Entry $entry, string $type): int
    {
        return (int) DB::table('collection_items')
            ->where('entry_id', $entry->id)
            ->where('item_type', $type)
            ->where('counts', 1)
            ->sum('qty');
    }

    /**
     * The quantity of a container multiplies everything inside it: 36 packets
     * of one figure are 36 figures, and part counting is a plain SUM over
     * these rows.
     */
    public function test_quantities_multiply_down_the_tree(): void
    {
        $entry = $this->add('S', 'box');

        $this->assertSame(36, $this->counted($entry, 'M'), '36 packets, one figure each');
        $this->assertSame(108, $this->counted($entry, 'P'), '36 x 3 parts of that figure');
    }

    /**
     * The rule this test exists for: a lot that does not count takes its whole
     * subtree with it. Without it the box above reports 72 minifigures,
     * because the eleven alternates each contribute one.
     */
    public function test_an_excluded_lot_excludes_its_contents(): void
    {
        $entry = $this->add('S', 'box');

        $excluded = DB::table('collection_items')
            ->where('entry_id', $entry->id)
            ->where('item_id', 'fig-b')
            ->first();

        $this->assertNotNull($excluded, 'the alternate is still recorded');
        $this->assertSame(0, (int) $excluded->counts, 'but it does not count');

        $partOfExcluded = DB::table('collection_items')
            ->where('entry_id', $entry->id)
            ->where('item_id', 'part-b')
            ->first();

        $this->assertSame(0, (int) $partOfExcluded->counts, 'nor do its parts');
    }

    public function test_spares_are_kept_but_not_counted(): void
    {
        $entry = $this->add('S', 'box');

        $spare = DB::table('collection_items')
            ->where('entry_id', $entry->id)
            ->where('item_id', 'spare')
            ->first();

        $this->assertSame(72, (int) $spare->qty, 'still multiplied by the 36 packets');
        $this->assertSame(0, (int) $spare->counts);
    }

    /** A set is the entry itself, so its lots are the top of the tree. */
    public function test_a_set_has_no_row_of_its_own(): void
    {
        $entry = $this->add('S', 'packet-a');

        $roots = DB::table('collection_items')->where('entry_id', $entry->id)->whereNull('parent_id')->get();

        $this->assertCount(2, $roots);
        $this->assertEqualsCanonicalizing(['fig-a', 'spare'], $roots->pluck('item_id')->all());
    }

    /** Anything else is a countable thing in its own right. */
    public function test_a_minifigure_gets_a_row_of_its_own(): void
    {
        $entry = $this->add('M', 'fig-a');

        $root = DB::table('collection_items')->where('entry_id', $entry->id)->whereNull('parent_id')->first();

        $this->assertSame('fig-a', $root->item_id);
        $this->assertSame(1, $this->counted($entry, 'M'));
        $this->assertSame(3, $this->counted($entry, 'P'));
    }

    public function test_a_loose_part_is_a_single_row(): void
    {
        $entry = $this->add('P', 'loose', ['qty' => 50]);

        $rows = DB::table('collection_items')->where('entry_id', $entry->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(50, (int) $rows[0]->qty);
    }

    /** Each copy is its own entry with its own contents. */
    public function test_adding_the_same_set_twice_makes_two_entries(): void
    {
        $first = $this->add('S', 'packet-a');
        $second = $this->add('S', 'packet-a');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Entry::count());
        $this->assertSame(
            DB::table('collection_items')->where('entry_id', $first->id)->count(),
            DB::table('collection_items')->where('entry_id', $second->id)->count(),
        );
    }

    public function test_deleting_an_entry_takes_its_contents(): void
    {
        $entry = $this->add('S', 'box');

        $this->assertGreaterThan(0, DB::table('collection_items')->count());

        $entry->delete();

        $this->assertSame(0, DB::table('collection_items')->count());
    }

    /** Adding lands on the copy just created, not back in the list. */
    public function test_the_route_adds_and_redirects_to_the_new_entry(): void
    {
        $this->post('/collection', ['type' => 'S', 'id' => 'packet-a'])
            ->assertRedirect('/collection/'.Entry::first()->id)
            ->assertSessionHas('flash');

        $this->assertSame(1, Entry::count());
    }

    public function test_the_route_rejects_an_unknown_item(): void
    {
        $this->post('/collection', ['type' => 'S', 'id' => 'nope'])->assertNotFound();
        $this->post('/collection', ['type' => 'Z', 'id' => 'packet-a'])->assertSessionHasErrors('type');

        $this->assertSame(0, Entry::count());
    }
}
