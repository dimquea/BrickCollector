<?php

namespace Tests\Feature;

use App\Catalog\Models\Item as CatalogItem;
use App\Collection\Actions\AddToCollection;
use App\Collection\Actions\RefreshInventories;
use App\Collection\Models\Entry;
use App\Collection\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Комментарий у фигурки и поднабора в составе экземпляра.
 *
 * Особая отметка — «трещина на ноге» — ставится руками, и, как отметка
 * недостачи, должна пережить всё, что с фигуркой делают: обновление описи,
 * расформирование набора, переезд свободной фигурки в коробку.
 */
class ItemNoteTest extends TestCase
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

        foreach ([['S', 'set-a'], ['S', 'pack'], ['M', 'fig'], ['P', 'brick'], ['P', 'tile']] as [$type, $id]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'image_color_id' => 0, 'has_inventory' => in_array($type, ['S', 'M'], true) ? 1 : 0,
            ]);
        }

        $this->lot('S', 'set-a', 'P', 'brick', 4);
        $this->lot('S', 'set-a', 'M', 'fig', 1);
        $this->lot('S', 'set-a', 'S', 'pack', 1);
        $this->lot('S', 'pack', 'P', 'brick', 1);
        $this->lot('M', 'fig', 'P', 'tile', 1);
    }

    private function lot(string $pt, string $pi, string $ct, string $ci, int $qty): void
    {
        DB::table('bl_inventory')->insert([
            'parent_type' => $pt, 'parent_id' => $pi, 'child_type' => $ct, 'child_id' => $ci,
            'color_id' => $ct === 'P' ? 11 : 0, 'qty' => $qty,
        ]);
    }

    private function set(): Entry
    {
        return app(AddToCollection::class)->handle(CatalogItem::where('type', 'S')->where('id', 'set-a')->first());
    }

    private function row(Entry $entry, string $type): Item
    {
        return Item::where('entry_id', $entry->id)->where('item_type', $type)->firstOrFail();
    }

    public function test_a_figure_and_a_subset_take_a_note(): void
    {
        $set = $this->set();

        $this->patchJson('/lots/'.$this->row($set, 'M')->id.'/note', ['note' => '  трещина на ноге  '])
            ->assertOk()
            ->assertJsonPath('note', 'трещина на ноге');

        $this->patchJson('/lots/'.$this->row($set, 'S')->id.'/note', ['note' => 'пакет вскрыт'])->assertOk();

        $this->assertSame('пакет вскрыт', $this->row($set, 'S')->note);

        // Стёрли — значит, отметки нет, а не пустая строка.
        $this->patchJson('/lots/'.$this->row($set, 'M')->id.'/note', ['note' => ''])->assertOk();
        $this->assertNull($this->row($set, 'M')->note);
    }

    /** У детали в таблице для комментария нет места — принимать его некуда. */
    public function test_a_part_and_a_standalone_figure_do_not(): void
    {
        $set = $this->set();

        $this->patchJson('/lots/'.$this->row($set, 'P')->id.'/note', ['note' => 'x'])->assertNotFound();

        $figure = app(AddToCollection::class)->handle(CatalogItem::where('type', 'M')->where('id', 'fig')->first());
        $root = Item::where('entry_id', $figure->id)->whereNull('parent_id')->firstOrFail();

        $this->patchJson('/lots/'.$root->id.'/note', ['note' => 'x'])->assertNotFound();
    }

    public function test_the_set_page_shows_the_note(): void
    {
        $set = $this->set();
        $this->row($set, 'M')->update(['note' => 'чужая голова']);

        $contents = $this->get('/sets/'.$set->id)->assertOk()->viewData('page')['props']['contents'];

        $this->assertSame('чужая голова', collect($contents)->firstWhere('type', 'M')['note']);
    }

    public function test_a_refresh_carries_the_note(): void
    {
        $set = $this->set();
        $this->row($set, 'M')->update(['note' => 'трещина']);

        DB::table('bl_inventory')->where('parent_id', 'set-a')->where('child_id', 'brick')->update(['qty' => 6]);

        app(RefreshInventories::class)->handle();

        $this->assertSame('трещина', $this->row($set, 'M')->note);
    }

    /** Фигурка ушла из описи — её комментарий называют, а не теряют молча. */
    public function test_a_note_with_nowhere_to_go_is_reported(): void
    {
        $set = $this->set();
        $this->row($set, 'M')->update(['note' => 'трещина']);

        DB::table('bl_inventory')->where('parent_id', 'set-a')->where('child_id', 'fig')->delete();

        $summary = app(RefreshInventories::class)->handle();

        $this->assertSame(1, $summary['orphaned']);
        $this->assertSame('трещина', $summary['orphans'][0]['note']);
        $this->assertSame(0, $summary['orphans'][0]['lost']);
    }

    public function test_parting_out_makes_the_note_the_copy_note(): void
    {
        $set = $this->set();
        $this->row($set, 'M')->update(['note' => 'трещина']);

        $this->post('/sets/'.$set->id.'/partout')->assertRedirect();

        $this->assertSame('трещина', Entry::where('item_type', 'M')->value('note'));
    }

    public function test_a_loose_figure_brings_its_note_into_the_set(): void
    {
        $loose = app(AddToCollection::class)->handle(CatalogItem::where('type', 'M')->where('id', 'fig')->first());
        $loose->update(['note' => 'без руки']);

        $this->post('/catalog/S/set-a/pick/add', ['figures' => ['fig']])->assertRedirect();

        $set = Entry::where('item_type', 'S')->firstOrFail();

        $this->assertSame('без руки', $this->row($set, 'M')->note);
        $this->assertNull(Entry::find($loose->id));
    }

    /** Какая из моих фигурок с трещиной — спрашивают на странице фигурки. */
    public function test_the_figure_page_shows_the_note_where_it_lies(): void
    {
        $set = $this->set();
        $this->row($set, 'M')->update(['note' => 'трещина']);

        $places = $this->get('/minifigures/fig')->assertOk()->viewData('page')['props']['inEntries'];

        $this->assertSame('трещина', $places[0]->note ?? $places[0]['note']);
    }
}
