<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use App\Collection\Queries\PartTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Assemblies: a group of loose parts with a name of its own.
 *
 * The rule every test here circles: moving parts in and out changes where they
 * are, never how many there are.
 */
class AssembliesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        DB::table('bl_colors')->insert([
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
            ['id' => 5, 'name' => 'Red', 'rgb' => 'B30006'],
        ]);

        foreach ([['S', 'set-a'], ['P', 'brick'], ['P', 'other']] as [$type, $id]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'image_color_id' => 11, 'has_inventory' => 0,
            ]);
        }
    }

    private function assembly(string $name = 'Moon base'): Entry
    {
        return Entry::create(['name' => $name, 'flag_incomplete' => false, 'flag_missing_figs' => false]);
    }

    private function lot(int $qty, int $lost = 0, ?string $acquired = null, string $itemId = 'brick'): Entry
    {
        $lot = Entry::create([
            'item_type' => 'P', 'item_id' => $itemId, 'color_id' => 11, 'acquired_at' => $acquired,
        ]);

        DB::table('collection_items')->insert([
            'entry_id' => $lot->id, 'item_type' => 'P', 'item_id' => $itemId, 'color_id' => 11,
            'qty' => $qty, 'lost_qty' => $lost, 'counts' => 1,
        ]);

        return $lot;
    }

    private function rows(Entry $entry): \Illuminate\Support\Collection
    {
        return DB::table('collection_items')->where('entry_id', $entry->id)->get();
    }

    public function test_an_assembly_is_created_with_a_name_and_opens_its_page(): void
    {
        $this->post('/assemblies', ['name' => 'Moon base'])
            ->assertRedirect('/assemblies/'.Entry::first()->id);

        $entry = Entry::first();

        $this->assertNull($entry->item_type, 'an assembly has no catalogue counterpart');
        $this->assertSame('Moon base', $entry->name);

        $this->get('/assemblies/'.$entry->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Assemblies/Show')->where('entry.name', 'Moon base'));

        $this->post('/assemblies', ['name' => ''])->assertSessionHasErrors('name');
    }

    public function test_taking_parts_moves_them_out_of_the_loose_pile(): void
    {
        $assembly = $this->assembly();
        $lot = $this->lot(5);

        $this->postJson("/assemblies/{$assembly->id}/parts", [
            'item_id' => 'brick', 'color_id' => 11, 'qty' => 2,
        ])->assertOk();

        $this->assertSame(2, (int) $this->rows($assembly)->first()->qty);
        $this->assertSame(3, (int) $this->rows($lot)->first()->qty, 'the lot keeps the rest');

        $part = app(PartTotals::class)->one('brick', 11);

        $this->assertSame(5, (int) $part->total, 'the collection holds the same bricks');
        $this->assertSame(2, (int) $part->in_assemblies);
        $this->assertSame(3, (int) $part->loose);
    }

    /** A lot spent to the last brick has nothing left to describe. */
    public function test_a_lot_spent_whole_is_gone(): void
    {
        $assembly = $this->assembly();
        $this->lot(4);

        $this->postJson("/assemblies/{$assembly->id}/parts", [
            'item_id' => 'brick', 'color_id' => 11, 'qty' => 4,
        ])->assertOk();

        $this->assertSame(1, Entry::count(), 'only the assembly is left');
        $this->assertSame(4, (int) $this->rows($assembly)->first()->qty);
    }

    public function test_the_oldest_lot_is_spent_first(): void
    {
        $assembly = $this->assembly();
        $old = $this->lot(2, 0, '2020-01-01');
        $new = $this->lot(5, 0, '2024-01-01');

        $this->postJson("/assemblies/{$assembly->id}/parts", [
            'item_id' => 'brick', 'color_id' => 11, 'qty' => 3,
        ])->assertOk();

        $this->assertNull(Entry::find($old->id), 'the older lot went entirely');
        $this->assertSame(4, (int) $this->rows($new)->first()->qty, 'one brick came out of the newer one');
    }

    /** A brick marked missing is not there to build with. */
    public function test_missing_parts_are_not_available(): void
    {
        $assembly = $this->assembly();
        $lot = $this->lot(5, 2);

        $this->postJson("/assemblies/{$assembly->id}/parts", [
            'item_id' => 'brick', 'color_id' => 11, 'qty' => 4,
        ])->assertUnprocessable();

        $this->assertSame(5, (int) $this->rows($lot)->first()->qty, 'nothing moved');
        $this->assertCount(0, $this->rows($assembly));
    }

    public function test_lowering_the_quantity_gives_parts_back_as_a_new_lot(): void
    {
        $assembly = $this->assembly();
        $this->lot(5);

        $this->postJson("/assemblies/{$assembly->id}/parts", [
            'item_id' => 'brick', 'color_id' => 11, 'qty' => 5,
        ])->assertOk();

        $row = $this->rows($assembly)->first();

        $this->patchJson("/assemblies/{$assembly->id}/parts/{$row->id}", ['qty' => 2])->assertOk();

        $lots = Entry::where('item_type', 'P')->get();

        $this->assertCount(1, $lots, 'what came out is a lot of its own');
        $this->assertSame(3, (int) $this->rows($lots->first())->first()->qty);
        $this->assertSame(2, (int) $this->rows($assembly)->first()->qty);
        $this->assertNull($lots->first()->acquired_at, 'a returned brick has no purchase to point at');
    }

    public function test_removing_a_part_gives_all_of_it_back(): void
    {
        $assembly = $this->assembly();
        $this->lot(3);

        $this->postJson("/assemblies/{$assembly->id}/parts", [
            'item_id' => 'brick', 'color_id' => 11, 'qty' => 3,
        ])->assertOk();

        $row = $this->rows($assembly)->first();

        $this->deleteJson("/assemblies/{$assembly->id}/parts/{$row->id}")->assertOk();

        $this->assertCount(0, $this->rows($assembly));
        $this->assertSame(3, (int) app(PartTotals::class)->one('brick', 11)->loose);
    }

    /**
     * A set deleted takes its contents with it: they were the set. An assembly
     * is the other way round — the bricks are still on the table.
     */
    public function test_deleting_an_assembly_returns_its_parts(): void
    {
        $assembly = $this->assembly();
        $this->lot(4);

        $this->postJson("/assemblies/{$assembly->id}/parts", [
            'item_id' => 'brick', 'color_id' => 11, 'qty' => 4,
        ])->assertOk();

        $this->delete('/assemblies/'.$assembly->id)->assertRedirect('/assemblies');

        $this->assertNull(Entry::find($assembly->id));

        $part = app(PartTotals::class)->one('brick', 11);

        $this->assertSame(4, (int) $part->total);
        $this->assertSame(4, (int) $part->loose);
    }

    public function test_the_name_is_edited_on_the_assembly(): void
    {
        $assembly = $this->assembly();

        $this->patchJson('/assemblies/'.$assembly->id, ['name' => 'Sand crawler'])->assertOk();

        $this->assertSame('Sand crawler', $assembly->fresh()->name);

        $this->patchJson('/assemblies/'.$assembly->id, ['name' => ''])->assertUnprocessable();
    }

    public function test_a_part_bought_for_an_assembly_goes_straight_into_it(): void
    {
        $assembly = $this->assembly();

        $this->post('/catalog/P/brick/add', [
            'qty' => 2, 'color_id' => 11, 'assembly_id' => $assembly->id,
        ])->assertRedirect('/assemblies/'.$assembly->id);

        $this->assertSame(2, (int) $this->rows($assembly)->first()->qty);
        $this->assertSame(1, Entry::count(), 'the lot it arrived as was moved whole, not left behind');
    }

    public function test_the_loose_list_offers_what_can_be_built_with(): void
    {
        $assembly = $this->assembly();
        $this->lot(5, 2);
        $this->lot(3, 0, null, 'other');

        $this->getJson("/assemblies/{$assembly->id}/loose")
            ->assertOk()
            ->assertJsonPath('parts.0.item_id', 'brick')
            ->assertJsonPath('parts.0.available', 3)
            ->assertJsonPath('parts.1.item_id', 'other')
            ->assertJsonCount(2, 'parts')
            ->assertJsonCount(1, 'colours');

        $this->getJson("/assemblies/{$assembly->id}/loose?q=other")
            ->assertOk()
            ->assertJsonCount(1, 'parts');
    }

    public function test_the_part_page_and_the_filter_know_about_assemblies(): void
    {
        $assembly = $this->assembly('Moon base');
        $this->lot(4);

        $this->postJson("/assemblies/{$assembly->id}/parts", [
            'item_id' => 'brick', 'color_id' => 11, 'qty' => 3,
        ])->assertOk();

        $this->get('/parts/brick/11')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('assemblies', 1)
                ->where('assemblies.0.name', 'Moon base')
                ->where('assemblies.0.qty', 3)
                ->has('loose', 1));

        $this->get('/parts?placement=assembly')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('parts.data', 1));

        $this->get('/parts?placement=minifigure')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('parts.data', 0));
    }

    /** Parts in an assembly used to be counted as if they were in a set. */
    public function test_analytics_counts_assembled_parts_apart(): void
    {
        $assembly = $this->assembly();
        $this->lot(5);

        $this->postJson("/assemblies/{$assembly->id}/parts", [
            'item_id' => 'brick', 'color_id' => 11, 'qty' => 2,
        ])->assertOk();

        $this->get('/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('counts.parts.total', 5)
                ->where('counts.parts.assemblies', 2)
                ->where('counts.parts.loose', 3)
                ->where('counts.parts.in_sets', 0));
    }

    public function test_an_assembly_keeps_out_of_the_sets_list_and_old_links_find_it(): void
    {
        $assembly = $this->assembly();

        $this->get('/sets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('entries.total', 0));

        $this->get('/sets/'.$assembly->id)->assertRedirect('/assemblies/'.$assembly->id);
    }

    public function test_an_assembly_can_be_given_a_picture(): void
    {
        Storage::fake('images');

        $assembly = $this->assembly();

        $this->get('/images/assembly/'.$assembly->id)->assertNotFound();

        $this->post("/assemblies/{$assembly->id}/image", [
            'image' => UploadedFile::fake()->image('shelf.jpg'),
        ])->assertRedirect();

        $this->get('/assemblies/'.$assembly->id)
            ->assertInertia(fn ($page) => $page->where('entry.has_image', true));

        $this->get('/images/assembly/'.$assembly->id)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->delete("/assemblies/{$assembly->id}/image")->assertRedirect();

        $this->get('/images/assembly/'.$assembly->id)->assertNotFound();
    }
}
