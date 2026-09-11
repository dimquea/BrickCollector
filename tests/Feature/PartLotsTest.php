<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Loose lots of a part: added in a colour, topped up or started anew, resized,
 * and given a page of their own rather than borrowing the one for sets.
 */
class PartLotsTest extends TestCase
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
            ['id' => 10, 'name' => 'Dark Gray', 'rgb' => '6B5A5A'],
            ['id' => 85, 'name' => 'Dark Bluish Gray', 'rgb' => '595D60'],
            ['id' => 5, 'name' => 'Red', 'rgb' => 'B30006'],
        ]);

        foreach ([
            ['S', 'set-a', 0],
            // A length of track: a part with an inventory of its own.
            ['P', 'track', 1],
            ['P', 'rail', 0],
            ['P', 'sleeper', 0],
            // An old mould BrickLink has no element codes for.
            ['P', 'old', 0],
        ] as [$type, $id, $inventory]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'image_color_id' => $type === 'P' ? 85 : 0, 'has_inventory' => $inventory,
            ]);
        }

        DB::table('bl_inventory')->insert([
            ['parent_type' => 'P', 'parent_id' => 'track', 'child_type' => 'P', 'child_id' => 'rail', 'color_id' => 85, 'qty' => 2],
            ['parent_type' => 'P', 'parent_id' => 'track', 'child_type' => 'P', 'child_id' => 'sleeper', 'color_id' => 85, 'qty' => 4],
        ]);

        DB::table('bl_element_codes')->insert([
            ['item_type' => 'P', 'item_id' => 'track', 'color_id' => 10, 'code' => '4190054'],
            ['item_type' => 'P', 'item_id' => 'track', 'color_id' => 85, 'code' => '4235339'],
        ]);
    }

    private function add(array $data, string $id = 'track'): \Illuminate\Testing\TestResponse
    {
        return $this->post("/catalog/P/{$id}/add", $data);
    }

    private function qty(Entry $entry, string $itemId, string $column = 'qty'): int
    {
        return (int) DB::table('collection_items')
            ->where('entry_id', $entry->id)
            ->where('item_id', $itemId)
            ->value($column);
    }

    public function test_a_part_is_added_as_a_new_lot_in_the_chosen_colour(): void
    {
        $response = $this->add(['qty' => 3, 'color_id' => 10]);
        $lot = Entry::first();

        $response->assertRedirect('/parts/copy/'.$lot->id)->assertSessionHas('flash');

        $this->assertSame('P', $lot->item_type);
        $this->assertSame(10, (int) $lot->color_id);
        $this->assertSame(3, $this->qty($lot, 'track'));
        $this->assertSame(6, $this->qty($lot, 'rail'), 'what the part is made of is multiplied by the lot');
    }

    public function test_without_a_colour_the_part_takes_its_picture_colour(): void
    {
        $this->add([])->assertRedirect();

        $lot = Entry::first();

        $this->assertSame(85, (int) $lot->color_id);
        $this->assertSame(1, $this->qty($lot, 'track'));
    }

    public function test_a_chosen_lot_is_topped_up_and_no_choice_makes_a_new_one(): void
    {
        $this->add(['qty' => 2, 'color_id' => 10]);
        $lot = Entry::first();

        $this->add(['qty' => 3, 'color_id' => 10, 'lot_id' => $lot->id])
            ->assertRedirect('/parts/copy/'.$lot->id)
            ->assertSessionHas('flash');

        $this->assertSame(1, Entry::count(), 'topped up, not duplicated');
        $this->assertSame(5, $this->qty($lot, 'track'));
        $this->assertSame(10, $this->qty($lot, 'rail'));

        $this->add(['qty' => 1, 'color_id' => 10]);

        $this->assertSame(2, Entry::count(), 'no lot chosen: another purchase');
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function otherLots(): array
    {
        return [
            'the same part in another colour' => ['track', 85],
            'another part in the same colour' => ['rail', 10],
        ];
    }

    /**
     * Topping up a red brick with blue ones would quietly change what the lot
     * is, so a lot of anything else is refused, not topped up.
     *
     * @dataProvider otherLots
     */
    public function test_a_lot_of_something_else_is_not_topped_up(string $part, int $colour): void
    {
        $this->add(['qty' => 2, 'color_id' => 10]);
        $lot = Entry::first();

        $this->from('/catalog/P/'.$part)
            ->add(['qty' => 3, 'color_id' => $colour, 'lot_id' => $lot->id], $part)
            ->assertRedirect('/catalog/P/'.$part)
            ->assertSessionHasErrors('lot_id');

        $this->assertSame(1, Entry::count());
        $this->assertSame(2, $this->qty($lot, 'track'));
    }

    public function test_resizing_scales_the_contents_and_trims_losses(): void
    {
        $this->add(['qty' => 4, 'color_id' => 10]);
        $lot = Entry::first();

        DB::table('collection_items')
            ->where('entry_id', $lot->id)
            ->where('item_id', 'sleeper')
            ->update(['lost_qty' => 10]);

        $this->patchJson("/parts/copy/{$lot->id}/qty", ['qty' => 2])
            ->assertOk()
            ->assertJson(['qty' => 2]);

        $this->assertSame(2, $this->qty($lot, 'track'));
        $this->assertSame(4, $this->qty($lot, 'rail'));
        $this->assertSame(8, $this->qty($lot, 'sleeper'));
        $this->assertSame(8, $this->qty($lot, 'sleeper', 'lost_qty'), 'a loss can not outnumber the lot');
    }

    public function test_a_lot_can_not_shrink_below_what_is_missing_from_it(): void
    {
        $this->add(['qty' => 5, 'color_id' => 10]);
        $lot = Entry::first();

        DB::table('collection_items')
            ->where('entry_id', $lot->id)
            ->whereNull('parent_id')
            ->update(['lost_qty' => 3]);

        $this->patchJson("/parts/copy/{$lot->id}/qty", ['qty' => 2])->assertUnprocessable();

        $this->assertSame(5, $this->qty($lot, 'track'));
    }

    public function test_a_lot_has_a_page_and_an_old_link_finds_it(): void
    {
        $this->add(['qty' => 3, 'color_id' => 10]);
        $lot = Entry::first();

        $this->get("/parts/copy/{$lot->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Parts/Copy')
                ->where('entry.qty', 3)
                ->where('entry.color_id', 10)
                ->has('parts', 2));

        // The part page used to link every owned copy to the set page.
        $this->get("/sets/{$lot->id}")->assertRedirect("/parts/copy/{$lot->id}");
    }

    public function test_a_set_is_not_a_lot(): void
    {
        $set = Entry::create(['item_type' => 'S', 'item_id' => 'set-a']);

        $this->get("/parts/copy/{$set->id}")->assertNotFound();
        $this->patchJson("/parts/copy/{$set->id}/qty", ['qty' => 2])->assertNotFound();
    }

    public function test_the_part_page_lists_loose_lots_apart_from_sets(): void
    {
        $set = Entry::create(['item_type' => 'S', 'item_id' => 'set-a']);

        DB::table('collection_items')->insert([
            'entry_id' => $set->id, 'item_type' => 'P', 'item_id' => 'track', 'color_id' => 10,
            'qty' => 1, 'lost_qty' => 0, 'counts' => 1,
        ]);

        $this->add(['qty' => 2, 'color_id' => 10]);

        $this->get('/parts/track/10')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('inEntries', 1)
                ->where('inEntries.0.item_id', 'set-a')
                ->has('loose', 1)
                ->where('loose.0.qty', 2));
    }

    public function test_the_catalogue_offers_the_known_colours_and_the_lots_held(): void
    {
        $this->add(['qty' => 2, 'color_id' => 10]);

        $this->get('/catalog/P/track')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('colours', fn ($colours) => collect($colours)->pluck('id')->sort()->values()->all() === [10, 85])
                ->has('lots', 1)
                ->where('lots.0.color_id', 10)
                ->where('lots.0.qty', 2));
    }

    /** Offering only the picture colour would leave every other one unrecordable. */
    public function test_a_part_without_element_codes_offers_the_whole_palette(): void
    {
        $this->get('/catalog/P/old')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('colours', 3));
    }

    public function test_removing_a_lot_goes_back_to_the_part_or_to_the_list(): void
    {
        $this->add(['qty' => 1, 'color_id' => 10]);
        $this->add(['qty' => 1, 'color_id' => 10]);

        [$first, $second] = Entry::orderBy('id')->get()->all();

        $this->delete("/parts/copy/{$first->id}")->assertRedirect('/parts/track/10');
        $this->delete("/parts/copy/{$second->id}")->assertRedirect('/parts');
    }
}
