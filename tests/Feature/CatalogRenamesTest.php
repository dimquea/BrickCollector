<?php

namespace Tests\Feature;

use App\Catalog\Models\Item as CatalogItem;
use App\Catalog\Queries\Renames;
use App\Collection\Actions\AddToCollection;
use App\Collection\Actions\ApplyRenames;
use App\Collection\Actions\RefreshInventories;
use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Переименования BrickLink — по журналу изменений.
 *
 * Коллекция хранит артикул, каким он был в день добавления. Когда справочник
 * предмет переименовывает, строка ссылается в пустоту, а «Обновить описи» не
 * узнаёт её в свежей описи и теряет отметку о недостаче. Журнал говорит, куда
 * предмет ушёл.
 */
class CatalogRenamesTest extends TestCase
{
    use RefreshDatabase;

    private int $log = 1000;

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

        foreach ([['S', 'set-a'], ['M', 'fig'], ['P', 'brick'], ['P', 'plate'], ['P', 'tile']] as [$type, $id]) {
            $this->item($type, $id);
        }

        $this->lot('S', 'set-a', 'P', 'brick', 4);
        $this->lot('S', 'set-a', 'P', 'plate', 2);
        $this->lot('S', 'set-a', 'M', 'fig', 1);
        $this->lot('M', 'fig', 'P', 'tile', 1);
    }

    private function item(string $type, string $id): void
    {
        DB::table('bl_items')->insert([
            'type' => $type, 'id' => $id, 'name' => ucfirst($id),
            'image_color_id' => 0, 'has_inventory' => in_array($id, ['set-a', 'fig'], true) ? 1 : 0,
        ]);
    }

    private function lot(string $pt, string $pi, string $ct, string $ci, int $qty): void
    {
        DB::table('bl_inventory')->insert([
            'parent_type' => $pt, 'parent_id' => $pi, 'child_type' => $ct, 'child_id' => $ci,
            'color_id' => $ct === 'P' ? 11 : 0, 'qty' => $qty,
        ]);
    }

    private function change(string $kind, string $fromType, string $from, string $toType, string $to, ?int $id = null): void
    {
        DB::table('bl_changelog')->insert([
            'id' => $id ?? ++$this->log, 'changed_at' => '2026-11-01', 'kind' => $kind,
            'from_type' => $fromType, 'from_id' => $from, 'to_type' => $toType, 'to_id' => $to,
        ]);
    }

    /**
     * Справочник обновился: предмет зовётся иначе — и в списке предметов, и
     * в описях, — а в журнале об этом запись.
     */
    private function rename(string $type, string $from, string $to, string $kind = 'I', ?string $toType = null): void
    {
        $toType ??= $type;

        DB::table('bl_items')->where('type', $type)->where('id', $from)->update(['type' => $toType, 'id' => $to]);
        DB::table('bl_inventory')->where('child_type', $type)->where('child_id', $from)->update(['child_type' => $toType, 'child_id' => $to]);
        DB::table('bl_inventory')->where('parent_type', $type)->where('parent_id', $from)->update(['parent_type' => $toType, 'parent_id' => $to]);

        $this->change($kind, $type, $from, $toType, $to);
    }

    private function add(string $type, string $id, array $meta = []): Entry
    {
        return app(AddToCollection::class)->handle(CatalogItem::where('type', $type)->where('id', $id)->firstOrFail(), $meta);
    }

    private function row(Entry $entry, string $itemId): ?object
    {
        return DB::table('collection_items')->where('entry_id', $entry->id)->where('item_id', $itemId)->first();
    }

    /** @return array<string, array{type: string, id: string}> */
    private function resolve(string $type, string $id): array
    {
        return app(Renames::class)->resolve([[$type, $id]]);
    }

    public function test_a_chain_is_followed_in_the_order_it_happened(): void
    {
        // Раньше всего tmp ушёл в plate — к нашей цепочке это не относится:
        // old стал tmp позже, и дальше tmp ведёт только то, что было после.
        $this->change('I', 'P', 'tmp', 'P', 'plate', 1);
        $this->change('I', 'P', 'old', 'P', 'tmp', 2);
        $this->change('M', 'P', 'tmp', 'P', 'brick', 3);

        $this->assertSame(['P/old' => ['type' => 'P', 'id' => 'brick']], $this->resolve('P', 'old'));
    }

    /** Левая и правая детали меняются номерами через промежуточный. */
    public function test_a_swap_does_not_loop(): void
    {
        $this->change('I', 'P', 'left', 'P', 'left-x', 1);
        $this->change('I', 'P', 'right', 'P', 'right-x', 2);
        $this->change('I', 'P', 'left-x', 'P', 'right', 3);
        $this->change('I', 'P', 'right-x', 'P', 'left', 4);

        $this->assertSame([], $this->resolve('P', 'left'), 'ни одной из двух в справочнике нет — переводить не на что');

        $this->item('P', 'left');
        $this->item('P', 'right');

        $this->assertSame([], $this->resolve('P', 'left'), 'существующий артикул верен, даже если когда-то значил другое');
        $this->assertSame(['P/right-x' => ['type' => 'P', 'id' => 'left']], $this->resolve('P', 'right-x'));
    }

    public function test_a_chain_that_leads_nowhere_changes_nothing(): void
    {
        $this->change('I', 'P', 'old', 'P', 'gone');

        $this->assertSame([], $this->resolve('P', 'old'));
    }

    public function test_marks_ride_along_with_the_new_number(): void
    {
        $set = $this->add('S', 'set-a');
        DB::table('collection_items')->where('id', $this->row($set, 'brick')->id)->update(['lost_qty' => 2, 'note' => 'под диваном']);
        $loose = $this->add('P', 'brick', ['color_id' => 11, 'qty' => 7]);
        DB::table('wishlist')->insert(['item_type' => 'P', 'item_id' => 'brick', 'color_id' => 11]);

        $this->rename('P', 'brick', 'brick-a');

        $this->assertSame(['renamed' => 1, 'skipped' => 0, 'left' => []], app(ApplyRenames::class)->handle());

        $row = $this->row($set, 'brick-a');
        $this->assertSame([4, 2, 'под диваном'], [(int) $row->qty, (int) $row->lost_qty, $row->note]);
        $this->assertSame('brick-a', $loose->fresh()->item_id, 'свободная партия — тоже');
        $this->assertSame(7, (int) $this->row($loose, 'brick-a')->qty);
        $this->assertSame('brick-a', DB::table('wishlist')->value('item_id'));

        $this->assertSame(0, app(RefreshInventories::class)->handle()['orphaned'], 'опись узнаёт строку по новому номеру');
        $this->assertSame(2, (int) $this->row($set, 'brick-a')->lost_qty);
    }

    /** Без переименования отметка терялась — ради этого всё и сделано. */
    public function test_the_refresh_renames_first_if_the_import_did_not(): void
    {
        $set = $this->add('S', 'set-a');
        DB::table('collection_items')->where('id', $this->row($set, 'brick')->id)->update(['lost_qty' => 1]);

        $this->rename('P', 'brick', 'brick-a');

        $summary = app(RefreshInventories::class)->handle();

        $this->assertSame(0, $summary['orphaned']);
        $this->assertSame(1, $summary['renamed']);
        $this->assertSame(1, (int) $this->row($set, 'brick-a')->lost_qty);
    }

    public function test_a_merge_folds_two_wishes_into_one(): void
    {
        DB::table('wishlist')->insert([
            ['item_type' => 'P', 'item_id' => 'plate-old', 'color_id' => 11],
            ['item_type' => 'P', 'item_id' => 'plate', 'color_id' => 11],
            ['item_type' => 'P', 'item_id' => 'plate-old', 'color_id' => 0],
        ]);
        $this->change('M', 'P', 'plate-old', 'P', 'plate');

        app(ApplyRenames::class)->handle();

        $this->assertSame(['plate/0', 'plate/11'], DB::table('wishlist')->orderBy('color_id')
            ->get()->map(fn ($w) => $w->item_id.'/'.$w->color_id)->all());
    }

    /**
     * Фигурка стала деталью: строку на месте не меняем — её детали внутри
     * посчитались бы дважды, — а «Обновить описи» пересобирает её и переносит
     * отметку.
     */
    public function test_a_type_change_is_left_to_the_refresh_which_keeps_the_mark(): void
    {
        $set = $this->add('S', 'set-a');
        DB::table('collection_items')->where('id', $this->row($set, 'fig')->id)->update(['lost_qty' => 1]);

        $this->rename('M', 'fig', 'fig', 'T', 'P');
        DB::table('bl_items')->where('id', 'fig')->update(['has_inventory' => 0]);
        DB::table('bl_inventory')->where('parent_id', 'fig')->delete();

        $applied = app(ApplyRenames::class)->handle();

        $this->assertSame(1, $applied['skipped']);
        $this->assertSame('M', $this->row($set, 'fig')->item_type, 'тип в описи не тронут');
        $this->assertFalse((bool) DB::table('collection_renames')->value('applied'));

        $summary = app(RefreshInventories::class)->handle();

        $this->assertSame(0, $summary['orphaned']);
        $fig = $this->row($set, 'fig');
        $this->assertSame(['P', 1], [$fig->item_type, (int) $fig->lost_qty]);
    }

    /** Экземпляр, сменивший тип, остаётся как был: у набора и вещи разное устройство строк. */
    public function test_an_entry_whose_type_changed_is_only_reported(): void
    {
        $figure = $this->add('M', 'fig');

        $this->rename('M', 'fig', 'fig', 'T', 'P');

        app(ApplyRenames::class)->handle();

        $this->assertSame('M', $figure->fresh()->item_type);
        $this->assertSame(1, (int) DB::table('collection_renames')->value('entries'));
    }

    public function test_settings_show_the_latest_run(): void
    {
        $set = $this->add('S', 'set-a');
        DB::table('collection_items')->where('id', $this->row($set, 'plate')->id)->update(['lost_qty' => 1]);

        $this->rename('P', 'plate', 'plate-b');
        app(ApplyRenames::class)->handle();

        $report = $this->get('/settings')->viewData('page')['props']['catalog']['renames'];

        $this->assertSame(1, $report['total']);
        $this->assertSame(1, $report['marks']);
        $this->assertSame(['plate', 'plate-b', 'Plate', 1], [
            $report['items'][0]['from_id'], $report['items'][0]['to_id'], $report['items'][0]['name'], $report['items'][0]['rows'],
        ]);

        // Прогон, в котором переименовывать нечего, отчёт не затирает.
        app(ApplyRenames::class)->handle();
        $this->assertSame(1, $this->get('/settings/catalog')->json('renames.total'));
    }

    public function test_nothing_to_rename_writes_nothing(): void
    {
        $this->add('S', 'set-a');

        $this->assertSame(['renamed' => 0, 'skipped' => 0, 'left' => []], app(ApplyRenames::class)->handle());
        $this->assertNull($this->get('/settings')->viewData('page')['props']['catalog']['renames']);
    }
}
