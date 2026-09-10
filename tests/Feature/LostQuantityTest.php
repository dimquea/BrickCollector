<?php

namespace Tests\Feature;

use App\Catalog\Models\Item as CatalogItem;
use App\Collection\Actions\AddToCollection;
use App\Collection\Actions\SetLostQuantity;
use App\Collection\Models\Entry;
use App\Collection\Models\Item as CollectionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two derived statuses of an owned copy, and the rules behind them.
 */
class LostQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Entry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'P', 'name' => 'Part'],
            ['code' => 'M', 'name' => 'Minifigure'],
        ]);
        DB::table('bl_colors')->insert([['id' => 0, 'name' => '(Not Applicable)', 'rgb' => null]]);

        foreach ([['S', 'set-1'], ['M', 'fig-1'], ['P', 'brick'], ['P', 'spare'], ['P', 'arm']] as [$type, $id]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'image_color_id' => 0, 'has_inventory' => in_array($id, ['set-1', 'fig-1'], true),
            ]);
        }

        $this->lot('S', 'set-1', 'P', 'brick', 4);
        $this->lot('S', 'set-1', 'P', 'spare', 2, ['is_extra' => 1]);
        $this->lot('S', 'set-1', 'M', 'fig-1', 1);
        $this->lot('M', 'fig-1', 'P', 'arm', 2);

        $this->entry = app(AddToCollection::class)->handle(
            CatalogItem::where('type', 'S')->where('id', 'set-1')->first(),
        );
    }

    private function lot(string $pt, string $pi, string $ct, string $ci, int $qty, array $extra = []): void
    {
        DB::table('bl_inventory')->insert(array_merge([
            'parent_type' => $pt, 'parent_id' => $pi,
            'child_type' => $ct, 'child_id' => $ci,
            'color_id' => 0, 'qty' => $qty,
        ], $extra));
    }

    private function lotOf(string $itemId): CollectionItem
    {
        return CollectionItem::where('entry_id', $this->entry->id)->where('item_id', $itemId)->firstOrFail();
    }

    private function setLost(string $itemId, int $lost): void
    {
        app(SetLostQuantity::class)->handle($this->lotOf($itemId), $lost);
        $this->entry->refresh();
    }

    public function test_a_new_copy_is_complete(): void
    {
        $this->assertFalse($this->entry->flag_incomplete);
        $this->assertFalse($this->entry->flag_missing_figs);
    }

    public function test_losing_a_part_makes_it_incomplete(): void
    {
        $this->setLost('brick', 1);

        $this->assertTrue($this->entry->flag_incomplete);
        $this->assertFalse($this->entry->flag_missing_figs, 'no figure is missing');
    }

    /** A spare is included on top; losing one leaves the set complete. */
    public function test_losing_a_spare_does_not_make_it_incomplete(): void
    {
        $this->setLost('spare', 2);

        $this->assertFalse($this->entry->flag_incomplete);
    }

    public function test_losing_a_minifigure_raises_both_flags(): void
    {
        $this->setLost('fig-1', 1);

        $this->assertTrue($this->entry->flag_incomplete);
        $this->assertTrue($this->entry->flag_missing_figs);
    }

    /**
     * Losing the figure does not zero out its parts: they are still listed as
     * belonging to it, and the figure may yet turn up.
     */
    public function test_losing_a_minifigure_leaves_its_parts_alone(): void
    {
        $this->setLost('fig-1', 1);

        $this->assertSame(0, $this->lotOf('arm')->lost_qty);
    }

    public function test_putting_it_back_clears_the_flags(): void
    {
        $this->setLost('brick', 2);
        $this->assertTrue($this->entry->flag_incomplete);

        $this->setLost('brick', 0);
        $this->assertFalse($this->entry->flag_incomplete);
    }

    /** The field is a spinner; an impossible number is a slip, not an attack. */
    public function test_the_loss_is_clamped_to_what_there_was(): void
    {
        $this->setLost('brick', 99);
        $this->assertSame(4, $this->lotOf('brick')->lost_qty);

        $this->setLost('brick', -5);
        $this->assertSame(0, $this->lotOf('brick')->lost_qty);
    }

    public function test_the_route_records_a_loss(): void
    {
        $lot = $this->lotOf('brick');

        $this->patch("/collection/lot/{$lot->id}", ['lost_qty' => 3])->assertRedirect();

        $this->assertSame(3, $lot->refresh()->lost_qty);
        $this->assertTrue($this->entry->refresh()->flag_incomplete);
    }

    public function test_the_entry_page_renders(): void
    {
        $this->get("/collection/{$this->entry->id}")
            ->assertOk()
            ->assertSee('set-1');
    }
}
