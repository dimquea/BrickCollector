<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use App\Collection\Models\Wish;
use App\Collection\Queries\MinifigureTotals;
use App\Collection\Queries\PartTotals;
use App\Collection\Queries\SearchEntries;
use App\Collection\Queries\WishedItems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Поиск по номеру из инструкции — в разделах коллекции.
 *
 * Вопрос «есть ли у меня вот этот кирпичик» задают не справочнику, а своей
 * коллекции, и номер в инструкции написан один: код элемента LEGO. В списке
 * деталей он попадает точнее всего — строка там и есть пара «артикул + цвет»,
 * которую код называет.
 *
 * Альтернативный артикул BrickLink цвета не знает и находит деталь во всех.
 */
class CollectionCodeSearchTest extends TestCase
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

        DB::table('bl_colors')->insert([
            ['id' => 5, 'name' => 'Red', 'rgb' => 'B30006'],
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
        ]);

        foreach ([['S', 'set-a'], ['M', 'fig'], ['P', '3031']] as [$type, $id]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'image_color_id' => 0, 'has_inventory' => 0,
            ]);
        }

        DB::table('bl_element_codes')->insert([
            ['item_type' => 'P', 'item_id' => '3031', 'color_id' => 5, 'code' => '4243814'],
            ['item_type' => 'P', 'item_id' => '3031', 'color_id' => 11, 'code' => '4243819'],
        ]);

        DB::table('bl_item_alt_ids')->insert([
            ['type' => 'P', 'id' => '3031', 'alt_id' => '3031old'],
            ['type' => 'M', 'id' => 'fig', 'alt_id' => 'figold'],
            ['type' => 'S', 'id' => 'set-a', 'alt_id' => 'set-old'],
        ]);

        // Набор, в котором лежит та же деталь в двух цветах и фигурка.
        $set = Entry::create(['item_type' => 'S', 'item_id' => 'set-a']);

        foreach ([[5, 4], [11, 2]] as [$colour, $qty]) {
            DB::table('collection_items')->insert([
                'entry_id' => $set->id, 'item_type' => 'P', 'item_id' => '3031',
                'color_id' => $colour, 'qty' => $qty, 'lost_qty' => 0, 'counts' => 1,
                'parent_item_type' => 'S',
            ]);
        }

        DB::table('collection_items')->insert([
            'entry_id' => $set->id, 'item_type' => 'M', 'item_id' => 'fig',
            'color_id' => 0, 'qty' => 1, 'lost_qty' => 0, 'counts' => 1,
        ]);
    }

    /** @return array<int, string> «артикул/цвет» каждой строки */
    private function parts(string $term): array
    {
        return app(PartTotals::class)->filters(['q' => $term])->all()
            ->map(fn (object $row) => $row->item_id.'/'.$row->color_id)
            ->all();
    }

    public function test_a_code_finds_the_part_in_exactly_one_colour(): void
    {
        $this->assertSame(['3031/5'], $this->parts('4243814'), 'красная');
        $this->assertSame(['3031/11'], $this->parts('4243819'), 'чёрная');
    }

    /** Второе имя предмета цвета не называет, поэтому находит деталь во всех. */
    public function test_an_alternate_number_finds_the_part_in_every_colour(): void
    {
        $this->assertSame(['3031/11', '3031/5'], $this->parts('3031old'), 'порядок — обычный для списка');
    }

    public function test_the_ordinary_search_still_works(): void
    {
        $this->assertSame(['3031/11', '3031/5'], $this->parts('3031'));
        $this->assertSame([], $this->parts('4243815'), 'кода этого цвета в коллекции нет');
    }

    public function test_a_minifigure_is_found_by_its_alternate_number(): void
    {
        $found = (new MinifigureTotals())->filters(['q' => 'figold'])->all();

        $this->assertSame(['fig'], $found->pluck('item_id')->all());
    }

    public function test_a_set_is_found_by_its_alternate_number(): void
    {
        $found = (new SearchEntries())->filters(['q' => 'set-old'])->ofTypes(['S', 'G', 'B', 'C'])->all();

        $this->assertSame(['set-a'], $found->pluck('item_id')->all());
    }

    public function test_a_wish_is_found_by_the_element_code(): void
    {
        Wish::create(['item_type' => 'P', 'item_id' => '3031', 'color_id' => 5]);
        Wish::create(['item_type' => 'P', 'item_id' => '3031', 'color_id' => 11]);

        $found = (new WishedItems())->filters(['q' => '4243814'])->all();

        $this->assertCount(1, $found, 'код назвал один цвет');
        $this->assertSame(5, (int) $found->first()->color_id);
    }
}
