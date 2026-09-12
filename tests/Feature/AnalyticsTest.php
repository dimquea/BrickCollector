<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use App\Collection\Queries\Analytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Сводка по коллекции.
 *
 * Считается из тех же таблиц и по тем же правилам, что и разделы: число,
 * по которому кликают, обязано совпасть с тем, что откроется.
 */
class AnalyticsTest extends TestCase
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
            ['id' => 0, 'name' => '(Not Applicable)', 'rgb' => '000000'],
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
        ]);

        DB::table('bl_themes')->insert([
            ['id' => 1, 'name' => 'Star Wars', 'path' => 'Star Wars', 'depth' => 0],
            ['id' => 2, 'name' => 'UCS', 'path' => 'Star Wars / UCS', 'depth' => 1],
            ['id' => 3, 'name' => 'City', 'path' => 'City', 'depth' => 0],
        ]);

        foreach ([
            ['S', 'set-a', 2, 2018], ['S', 'set-b', 3, 2019],
            ['M', 'fig-a', 2, 2018], ['M', 'fig-b', 1, 2018],
            ['P', 'brick', null, null],
        ] as [$type, $id, $theme, $year]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'theme_id' => $theme, 'year' => $year,
                'image_color_id' => 0, 'has_inventory' => 0,
            ]);
        }
    }

    private function lot(Entry $entry, array $attributes): int
    {
        return DB::table('collection_items')->insertGetId(array_merge([
            'entry_id' => $entry->id,
            'color_id' => 0,
            'qty' => 1,
            'lost_qty' => 0,
            'counts' => 1,
        ], $attributes));
    }

    /** Набор с фигуркой и деталями, отдельная фигурка, россыпь деталей. */
    private function collection(): void
    {
        $set = Entry::create([
            'item_type' => 'S', 'item_id' => 'set-a', 'price' => 100000,
            'flag_incomplete' => true,
        ]);
        $figureInSet = $this->lot($set, ['item_type' => 'M', 'item_id' => 'fig-a']);
        $this->lot($set, ['item_type' => 'P', 'item_id' => 'brick', 'color_id' => 11, 'qty' => 4,
            'parent_id' => $figureInSet, 'parent_item_type' => 'M', 'lost_qty' => 1]);
        $this->lot($set, ['item_type' => 'P', 'item_id' => 'brick', 'color_id' => 11, 'qty' => 10,
            'parent_item_type' => 'S']);

        $loose = Entry::create(['item_type' => 'M', 'item_id' => 'fig-b', 'price' => 500]);
        $this->lot($loose, ['item_type' => 'M', 'item_id' => 'fig-b', 'qty' => 2]);

        $spare = Entry::create(['item_type' => 'P', 'item_id' => 'brick', 'color_id' => 11]);
        $this->lot($spare, ['item_type' => 'P', 'item_id' => 'brick', 'color_id' => 11, 'qty' => 7]);
    }

    public function test_it_counts_what_the_sections_count(): void
    {
        $this->collection();

        $counts = app(Analytics::class)->counts();

        $this->assertSame(['total' => 1, 'unique' => 1, 'incomplete' => 1], $counts['sets']);

        $this->assertSame([
            'total' => 3, 'in_sets' => 1, 'loose' => 2, 'unique' => 2,
            // Внутри фигурки набора не хватает детали.
            'incomplete' => 1,
        ], $counts['minifigures']);

        $this->assertSame([
            // «В сборках» считается отдельно: деталь в сборке не куплена в
            // составе чего-то и не лежит россыпью.
            'total' => 21, 'in_sets' => 14, 'loose' => 7, 'assemblies' => 0, 'unique' => 1, 'lost' => 1,
        ], $counts['parts']);
    }

    /** Строки, не идущие в зачёт состава, не считаются нигде. */
    public function test_a_spare_is_left_out(): void
    {
        $set = Entry::create(['item_type' => 'S', 'item_id' => 'set-a']);
        $this->lot($set, ['item_type' => 'P', 'item_id' => 'brick', 'qty' => 5, 'counts' => 1]);
        $this->lot($set, ['item_type' => 'P', 'item_id' => 'brick', 'qty' => 3,
            'is_extra' => 1, 'counts' => 0]);

        $this->assertSame(5, app(Analytics::class)->counts()['parts']['total']);
    }

    /**
     * Средняя по коллекции, где половина без цены, — не средняя цена покупки,
     * а ничто.
     */
    public function test_money_is_counted_over_priced_copies_only(): void
    {
        $this->collection();
        Entry::create(['item_type' => 'S', 'item_id' => 'set-b']);

        $finance = app(Analytics::class)->finance();

        $this->assertSame(100500, $finance['total']);
        $this->assertSame(2, $finance['priced']);
        $this->assertSame(50250, $finance['average']);
        $this->assertSame('set-a', $finance['priciest']['item_id']);
    }

    public function test_money_says_so_when_nothing_has_a_price(): void
    {
        Entry::create(['item_type' => 'S', 'item_id' => 'set-a']);

        $finance = app(Analytics::class)->finance();

        $this->assertSame(0, $finance['total']);
        $this->assertNull($finance['average']);
        $this->assertNull($finance['priciest']);
    }

    /** Тема берётся корневая: лист повторял бы список наборов построчно. */
    public function test_themes_are_grouped_by_their_root(): void
    {
        $this->collection();

        $rows = app(Analytics::class)->byTheme();

        $this->assertCount(1, $rows);
        $this->assertSame('Star Wars', $rows[0]['label']);
        $this->assertSame(1, $rows[0]['key'], 'ссылка ведёт на корневую тему');
        $this->assertSame(1, $rows[0]['sets']);
        $this->assertSame(2, $rows[0]['figures'], 'обе фигурки из темы Star Wars');
        $this->assertSame(100000, $rows[0]['price']);
    }

    /**
     * Число кликабельно, поэтому считает артикулы: раздел минифигурок
     * показывает одну карточку на артикул, а не на экземпляр.
     */
    public function test_a_figure_owned_twice_counts_once_in_the_breakdown(): void
    {
        $this->collection();

        $rows = app(Analytics::class)->byYear();

        $this->assertSame(2018, $rows[0]['key']);
        $this->assertSame(2, $rows[0]['figures'], 'два артикула, хотя экземпляров три');
    }

    public function test_years_run_from_the_newest_down(): void
    {
        $this->collection();
        Entry::create(['item_type' => 'S', 'item_id' => 'set-b']);

        $this->assertSame([2019, 2018], array_column(app(Analytics::class)->byYear(), 'key'));
    }

    public function test_the_page_renders(): void
    {
        $this->collection();

        $this->get('/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Analytics/Index')
                ->where('counts.sets.total', 1)
                ->where('finance.priced', 2)
                ->has('themes', 1)
                ->has('years', 1));
    }

    public function test_an_empty_collection_does_not_break_it(): void
    {
        $this->get('/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('counts.parts.total', 0)
                ->has('themes', 0)
                ->has('years', 0));
    }
}
