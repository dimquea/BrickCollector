<?php

namespace Tests\Feature;

use App\Catalog\Models\Item as CatalogItem;
use App\Collection\Actions\AddToCollection;
use App\Collection\Actions\RefreshInventories;
use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Пересборка описей по свежему справочнику.
 *
 * Опись копируется в коллекцию один раз и дальше живёт сама по себе. Когда
 * справочник уточняют, экземпляр хочется привести к новому описанию — не
 * потеряв при этом единственное, что в дереве ввёл человек: отметки о том,
 * чего не хватает.
 */
class RefreshInventoriesTest extends TestCase
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

        foreach ([['S', 'set-a'], ['M', 'fig'], ['P', 'brick'], ['P', 'plate'], ['P', 'tile'], ['P', 'loose']] as [$type, $id]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'image_color_id' => 0, 'has_inventory' => in_array($id, ['set-a', 'fig'], true) ? 1 : 0,
            ]);
        }

        $this->lot('S', 'set-a', 'P', 'brick', 4);
        $this->lot('S', 'set-a', 'P', 'plate', 2);
        $this->lot('S', 'set-a', 'M', 'fig', 1);
        $this->lot('M', 'fig', 'P', 'tile', 1);
    }

    /** @param array<string, mixed> $extra */
    private function lot(string $pt, string $pi, string $ct, string $ci, int $qty, array $extra = []): void
    {
        DB::table('bl_inventory')->insert(array_merge([
            'parent_type' => $pt, 'parent_id' => $pi,
            'child_type' => $ct, 'child_id' => $ci,
            'color_id' => $ct === 'P' ? 11 : 0, 'qty' => $qty,
        ], $extra));
    }

    private function add(string $type, string $id, array $meta = []): Entry
    {
        return app(AddToCollection::class)->handle(
            CatalogItem::where('type', $type)->where('id', $id)->first(),
            $meta,
        );
    }

    private function row(Entry $entry, string $itemId): ?object
    {
        return DB::table('collection_items')
            ->where('entry_id', $entry->id)
            ->where('item_id', $itemId)
            ->first();
    }

    public function test_a_changed_quantity_reaches_the_copy(): void
    {
        $entry = $this->add('S', 'set-a');

        $this->assertSame(4, (int) $this->row($entry, 'brick')->qty);

        DB::table('bl_inventory')
            ->where('parent_id', 'set-a')->where('child_id', 'brick')
            ->update(['qty' => 6]);

        $summary = app(RefreshInventories::class)->handle();

        $this->assertSame(6, (int) $this->row($entry, 'brick')->qty);
        $this->assertSame(1, $summary['changed']);
        $this->assertSame(0, $summary['added'], 'позиция та же, изменилось только количество');
    }

    public function test_a_lot_that_appeared_is_added_and_one_that_went_is_removed(): void
    {
        $entry = $this->add('S', 'set-a');

        DB::table('bl_inventory')->where('parent_id', 'set-a')->where('child_id', 'plate')->delete();
        $this->lot('S', 'set-a', 'P', 'tile', 3);

        $summary = app(RefreshInventories::class)->handle();

        $this->assertNull($this->row($entry, 'plate'));
        $this->assertSame(3, (int) DB::table('collection_items')
            ->where('entry_id', $entry->id)->where('item_id', 'tile')->whereNull('parent_item_type')
            ->value('qty'));
        $this->assertSame(1, $summary['added']);
        $this->assertSame(1, $summary['removed']);
    }

    /**
     * Отметка о недостаче — единственное, что в дереве ввёл человек.
     *
     * Она привязана к месту: та же деталь лежит и в наборе, и в фигурке, и
     * «не хватает одной» сказано про что-то одно из двух.
     */
    public function test_a_loss_stays_where_it_was_put(): void
    {
        $entry = $this->add('S', 'set-a');

        DB::table('collection_items')->where('id', $this->row($entry, 'brick')->id)->update(['lost_qty' => 2]);
        DB::table('collection_items')->where('id', $this->row($entry, 'tile')->id)->update(['lost_qty' => 1]);

        $summary = app(RefreshInventories::class)->handle();

        $this->assertSame(2, (int) $this->row($entry, 'brick')->lost_qty);
        $this->assertSame(1, (int) $this->row($entry, 'tile')->lost_qty, 'деталь внутри фигурки');
        $this->assertSame(2, $summary['losses']);
        $this->assertSame(0, $summary['orphaned']);
        $this->assertTrue((bool) $entry->fresh()->flag_incomplete, 'флаг некомплекта пересчитан');
    }

    public function test_a_loss_is_clamped_to_what_is_left(): void
    {
        $entry = $this->add('S', 'set-a');

        DB::table('collection_items')->where('id', $this->row($entry, 'brick')->id)->update(['lost_qty' => 4]);

        DB::table('bl_inventory')
            ->where('parent_id', 'set-a')->where('child_id', 'brick')
            ->update(['qty' => 1]);

        app(RefreshInventories::class)->handle();

        $this->assertSame(1, (int) $this->row($entry, 'brick')->lost_qty);
    }

    /** Потерянной детали больше нет в описи: отметку деть некуда, и о ней говорят вслух. */
    public function test_a_loss_with_nowhere_to_go_is_reported(): void
    {
        $entry = $this->add('S', 'set-a');

        DB::table('collection_items')->where('id', $this->row($entry, 'plate')->id)->update(['lost_qty' => 1]);

        DB::table('bl_inventory')->where('parent_id', 'set-a')->where('child_id', 'plate')->delete();

        $summary = app(RefreshInventories::class)->handle();

        $this->assertSame(0, $summary['losses']);
        $this->assertSame(1, $summary['orphaned']);
    }

    /**
     * Пустая опись не опустошает экземпляр.
     *
     * Справочник пересобирают целиком, и предмет, которого в свежем выпуске не
     * оказалось, выглядел бы здесь как набор без единой детали.
     */
    public function test_a_copy_whose_item_lost_its_inventory_is_left_alone(): void
    {
        $entry = $this->add('S', 'set-a');

        DB::table('bl_inventory')->where('parent_id', 'set-a')->delete();

        $summary = app(RefreshInventories::class)->handle();

        $this->assertSame(4, (int) $this->row($entry, 'brick')->qty);
        $this->assertSame(0, $summary['changed']);
    }

    /** У экземпляра фигурки количество и цвет корневой строки выбрал человек. */
    public function test_a_figure_copy_keeps_its_own_root(): void
    {
        $entry = $this->add('M', 'fig', ['qty' => 3]);

        app(RefreshInventories::class)->handle();

        $root = DB::table('collection_items')->where('entry_id', $entry->id)->whereNull('parent_id')->first();

        $this->assertSame(3, (int) $root->qty);
        $this->assertSame('fig', $root->item_id);
        $this->assertSame(3, (int) $this->row($entry, 'tile')->qty, 'детали фигурки умножены на число копий');
    }

    /** Сборку и свободную партию описывал человек, а не справочник. */
    public function test_assemblies_and_loose_lots_are_not_touched(): void
    {
        $lot = $this->add('P', 'loose', ['qty' => 7]);
        $assembly = Entry::create(['name' => 'Домик']);

        DB::table('collection_items')->insert([
            'entry_id' => $assembly->id, 'item_type' => 'P', 'item_id' => 'brick',
            'color_id' => 11, 'qty' => 5, 'lost_qty' => 0, 'counts' => 1,
        ]);

        $summary = app(RefreshInventories::class)->handle();

        $this->assertSame(7, (int) $this->row($lot, 'loose')->qty);
        $this->assertSame(5, (int) $this->row($assembly, 'brick')->qty);
        $this->assertSame(0, $summary['entries'], 'ни один из них не считается описью');
    }
}
