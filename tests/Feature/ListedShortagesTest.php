<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Два отбора, которых не хватало спискам.
 *
 * Фигурку помечают потерянной внутри набора, а тег ставят партии деталей — и то
 * и другое было видно только на странице самого экземпляра. Число потерянных
 * фигурок при этом уже считалось и рисовалось бейджем: не хватало именно
 * отбора.
 */
class ListedShortagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'M', 'name' => 'Minifigure'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        DB::table('bl_colors')->insert([['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E']]);

        foreach ([['S', 'set-a'], ['M', 'fig-here'], ['M', 'fig-gone'], ['P', 'brick'], ['P', 'plate']] as [$type, $id]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'image_color_id' => 0, 'has_inventory' => 0,
            ]);
        }

        DB::table('ref_tags')->insert([
            ['id' => 1, 'name' => 'Б/У', 'color' => 'secondary', 'sort' => 1, 'show_in_list' => 0],
            ['id' => 2, 'name' => 'На продажу', 'color' => 'warning', 'sort' => 2, 'show_in_list' => 1],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function row(Entry $entry, array $attributes): void
    {
        DB::table('collection_items')->insert(array_merge([
            'entry_id' => $entry->id, 'color_id' => 11, 'qty' => 1, 'lost_qty' => 0, 'counts' => 1,
        ], $attributes));
    }

    private function lot(string $itemId, int $qty, ?int $tagId = null): Entry
    {
        $lot = Entry::create(['item_type' => 'P', 'item_id' => $itemId, 'color_id' => 11]);

        $this->row($lot, ['item_type' => 'P', 'item_id' => $itemId, 'qty' => $qty]);

        if ($tagId !== null) {
            DB::table('entry_tags')->insert(['entry_id' => $lot->id, 'tag_id' => $tagId]);
        }

        return $lot;
    }

    /** @return array<int, string> */
    private function ids(string $url, string $prop): array
    {
        $page = $this->get($url)->assertOk()->viewData('page');

        return array_column($page['props'][$prop]['data'], 'item_id');
    }

    public function test_minifigures_can_be_filtered_by_what_a_set_is_missing(): void
    {
        $set = Entry::create(['item_type' => 'S', 'item_id' => 'set-a']);

        $this->row($set, ['item_type' => 'M', 'item_id' => 'fig-here']);
        $this->row($set, ['item_type' => 'M', 'item_id' => 'fig-gone', 'lost_qty' => 1]);

        $this->assertSame(['fig-gone', 'fig-here'], $this->ids('/minifigures', 'figures'));
        $this->assertSame(['fig-gone'], $this->ids('/minifigures?lost=true', 'figures'));

        // Выключённый переключатель — отсутствие фильтра, а не «только целые».
        $this->assertSame(['fig-gone', 'fig-here'], $this->ids('/minifigures?lost=false', 'figures'));
    }

    /**
     * Тег принадлежит партии, а список деталей группирует строки со всей
     * коллекции. Отбор идёт по парам «артикул + цвет», поэтому счётчики строки
     * остаются прежними: деталь, которая лежит и в наборе, и в помеченной
     * партии, показывает всё, чем владеют, а не только партию.
     */
    public function test_parts_can_be_filtered_by_a_tag_on_a_loose_lot(): void
    {
        $set = Entry::create(['item_type' => 'S', 'item_id' => 'set-a']);

        $this->row($set, ['item_type' => 'P', 'item_id' => 'brick', 'qty' => 10]);

        $this->lot('brick', 2, 1);
        $this->lot('plate', 3, 2);

        $this->assertSame(['brick', 'plate'], $this->ids('/parts', 'parts'));
        $this->assertSame(['brick'], $this->ids('/parts?placement=loose&tag_id=1', 'parts'));
        $this->assertSame(['plate'], $this->ids('/parts?placement=loose&tag_id=2', 'parts'));

        // Строки списка приходят объектами: сводка по деталям отдаёт их как
        // есть, не превращая в массивы.
        $page = $this->get('/parts?placement=loose&tag_id=1')->viewData('page');
        $brick = $page['props']['parts']['data'][0];

        $this->assertSame(12, (int) $brick->total, 'счётчики считают всю коллекцию, а не только партию');
        $this->assertSame(10, (int) $brick->in_sets);
        $this->assertSame(2, (int) $brick->loose);
    }

    /** Предлагать тег, которого нет ни на одной партии, значит обещать пустой список. */
    public function test_only_tags_put_on_a_lot_are_offered(): void
    {
        $this->get('/parts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tags', []));

        $this->lot('brick', 2, 2);

        $this->get('/parts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tags', 1)
                ->where('tags.0.name', 'На продажу'));
    }
}
