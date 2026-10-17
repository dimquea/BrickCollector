<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use App\Collection\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Расформирование набора: всё, что было в коробке, — в свободные.
 *
 * Детали не создаются и не исчезают: что было на руках, то и ложится в
 * ящики; потерянного нет ни в коробке, ни после неё.
 */
class PartOutTest extends TestCase
{
    use RefreshDatabase;

    private const BLACK = 11;

    private Entry $set;

    private int $storage;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'M', 'name' => 'Minifigure'],
            ['code' => 'P', 'name' => 'Part'],
            ['code' => 'G', 'name' => 'Gear'],
        ]);

        DB::table('bl_colors')->insert([
            ['id' => 0, 'name' => '(Not Applicable)', 'rgb' => null],
            ['id' => self::BLACK, 'name' => 'Black', 'rgb' => '2E2E2E'],
            ['id' => 5, 'name' => 'Red', 'rgb' => 'B30006'],
        ]);

        $this->storage = DB::table('ref_storages')->insertGetId(['name' => 'Shelf', 'sort' => 1, 'is_active' => 1]);
        $tag = DB::table('ref_tags')->insertGetId(['name' => 'For sale', 'sort' => 1]);

        $this->set = Entry::create([
            'item_type' => 'S', 'item_id' => 'set-a', 'storage_id' => $this->storage,
            'acquired_at' => '2021-05-01', 'price' => 5000,
        ]);
        DB::table('entry_tags')->insert(['entry_id' => $this->set->id, 'tag_id' => $tag]);

        // Четыре кирпича, один потерян.
        $this->row(['item_type' => 'P', 'item_id' => 'brick', 'qty' => 4, 'lost_qty' => 1]);
        // Запасная — в коробке; альтернатива — нет.
        $this->row(['item_type' => 'P', 'item_id' => 'plate', 'qty' => 1, 'counts' => 0, 'is_extra' => 1]);
        $this->row(['item_type' => 'P', 'item_id' => 'tile', 'qty' => 1, 'counts' => 0, 'is_alternate' => 1]);

        // Фигурка, у которой нет головы.
        $figure = $this->row(['item_type' => 'M', 'item_id' => 'fig', 'color_id' => 0]);
        $this->row([
            'item_type' => 'P', 'item_id' => 'head', 'parent_id' => $figure, 'parent_item_type' => 'M', 'lost_qty' => 1,
        ]);

        // Два пакета по кирпичу, один пакет потерян целиком.
        $pack = $this->row(['item_type' => 'S', 'item_id' => 'pack', 'color_id' => 0, 'qty' => 2, 'lost_qty' => 1]);
        $this->row([
            'item_type' => 'P', 'item_id' => 'brick', 'parent_id' => $pack, 'parent_item_type' => 'S', 'qty' => 2,
        ]);

        // Кейс красного цвета.
        $this->row(['item_type' => 'G', 'item_id' => 'case', 'color_id' => 5]);
    }

    /** @param array<string, mixed> $attributes */
    private function row(array $attributes): int
    {
        return DB::table('collection_items')->insertGetId(array_merge([
            'entry_id' => $this->set->id, 'color_id' => self::BLACK, 'qty' => 1, 'lost_qty' => 0, 'counts' => 1,
        ], $attributes));
    }

    private function partOut(): void
    {
        $this->post("/sets/{$this->set->id}/partout")->assertRedirect('/sets');
    }

    /** @return array<string, int> */
    private function looseParts(): array
    {
        return Item::join('collection_entries as e', 'e.id', '=', 'collection_items.entry_id')
            ->where('e.item_type', 'P')
            ->whereNull('collection_items.parent_id')
            ->pluck('collection_items.qty', 'collection_items.item_id')
            ->map(fn ($qty) => (int) $qty)
            ->sort()
            ->all();
    }

    public function test_parts_on_hand_go_to_the_loose_pile_as_one_lot_each(): void
    {
        $this->partOut();

        $this->assertSame(
            ['plate' => 1, 'brick' => 4],
            $this->looseParts(),
            'три из коробки и один из уцелевшего пакета; запасная тоже; альтернативы не было',
        );
        $this->assertNull(Entry::find($this->set->id), 'набора больше нет');
    }

    public function test_a_figure_becomes_a_copy_of_its_own_with_its_losses(): void
    {
        $this->partOut();

        $figure = Entry::where('item_type', 'M')->firstOrFail();
        $head = Item::where('entry_id', $figure->id)->where('item_id', 'head')->firstOrFail();

        $this->assertSame(1, $head->lost_qty, 'голова не нашлась оттого, что набор разобрали');
        $this->assertTrue($figure->flag_incomplete);
    }

    public function test_gear_becomes_a_copy_in_its_colour(): void
    {
        $this->partOut();

        $case = Entry::where('item_type', 'G')->firstOrFail();

        $this->assertSame(5, (int) $case->color_id);
    }

    /** Хранилище и дата — переходят; цена и теги — нет. */
    public function test_storage_and_date_carry_over_while_price_and_tags_do_not(): void
    {
        $this->partOut();

        $lot = Entry::where('item_type', 'P')->where('item_id', 'brick')->firstOrFail();

        $this->assertSame($this->storage, (int) $lot->storage_id);
        $this->assertSame('2021-05-01', $lot->acquired_at->format('Y-m-d'));
        $this->assertNull($lot->price);
        $this->assertSame(0, $lot->tags()->count());
    }

    /** Подтверждение показывает то, что произойдёт, и ничего не трогает. */
    public function test_the_preview_tells_what_will_happen_and_changes_nothing(): void
    {
        $data = $this->getJson("/sets/{$this->set->id}/partout")->assertOk()->json();

        $this->assertSame(5, $data['parts']);
        $this->assertSame(2, $data['lots']);
        $this->assertSame(1, $data['figures']);
        $this->assertSame(1, $data['others']);
        $this->assertSame(1, $data['lost'], 'потерянный кирпич');
        $this->assertNotEmpty($data['message']);

        $this->assertNotNull(Entry::find($this->set->id));
        $this->assertSame(0, Entry::where('item_type', 'P')->count());
    }

    /** Новые партии не сливаются с теми, что уже лежат: партия — это покупка. */
    public function test_existing_loose_lots_are_left_alone(): void
    {
        $old = Entry::create(['item_type' => 'P', 'item_id' => 'brick', 'color_id' => self::BLACK]);
        DB::table('collection_items')->insert([
            'entry_id' => $old->id, 'item_type' => 'P', 'item_id' => 'brick', 'color_id' => self::BLACK,
            'qty' => 7, 'lost_qty' => 0, 'counts' => 1,
        ]);

        $this->partOut();

        $this->assertSame(7, Item::where('entry_id', $old->id)->value('qty'));
        $this->assertSame(2, Entry::where('item_type', 'P')->where('item_id', 'brick')->count());
    }

    public function test_only_a_set_can_be_parted_out(): void
    {
        $gear = Entry::create(['item_type' => 'G', 'item_id' => 'case']);

        $this->post("/sets/{$gear->id}/partout")->assertNotFound();
    }
}
