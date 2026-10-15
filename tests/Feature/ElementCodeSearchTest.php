<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Поиск по точному идентификатору: коду элемента и альтернативному артикулу.
 *
 * Номер вроде 4243815 переписывают из инструкции, и называет он не деталь
 * вообще, а деталь в цвете. Альтернативный артикул — второе имя того же
 * предмета. Оба лежали в базе и не искались ничем.
 *
 * В поисковый индекс они не кладутся намеренно: bm25 делит вес на длину
 * строки целиком, а кодов у ходовой детали под сотню, и деталь проваливалась
 * ниже наборов, чей номер просто начинается с тех же цифр.
 */
class ElementCodeSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'P', 'name' => 'Part'],
            ['code' => 'S', 'name' => 'Set'],
        ]);

        DB::table('bl_colors')->insert([
            ['id' => 5, 'name' => 'Red', 'rgb' => 'B30006'],
            ['id' => 7, 'name' => 'Blue', 'rgb' => '0055BF'],
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
        ]);

        // Картинка детали в справочнике чёрная; коды называют красную и синюю.
        $this->item('P', '3031', 'Plate 4 x 4', 11);
        $this->item('P', '3005', 'Brick 1 x 1', 11);
        // Набор, чей номер начинается с тех же цифр, что артикул детали.
        $this->item('S', '30310-1', 'Arctic Scout polybag', 0);

        DB::table('bl_element_codes')->insert([
            ['item_type' => 'P', 'item_id' => '3031', 'color_id' => 5, 'code' => '4243814'],
            ['item_type' => 'P', 'item_id' => '3031', 'color_id' => 7, 'code' => '4243815'],
        ]);

        DB::table('bl_item_alt_ids')->insert([
            ['type' => 'P', 'id' => '3005', 'alt_id' => '3005old'],
        ]);
    }

    private function item(string $type, string $id, string $name, int $imageColour): void
    {
        DB::table('bl_items')->insert([
            'type' => $type, 'id' => $id, 'name' => $name,
            'image_color_id' => $imageColour, 'has_inventory' => 0,
        ]);

        DB::table('bl_items_fts')->insert([
            'name' => $name, 'ident' => $id, 'type' => $type, 'item_id' => $id,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function results(string $query): array
    {
        return $this->get('/catalog?q='.$query)->assertOk()->viewData('page')['props']['results']['data'];
    }

    public function test_a_code_finds_the_part_in_the_colour_it_names(): void
    {
        $rows = $this->results('4243814');

        $this->assertCount(1, $rows);
        $this->assertSame('3031', $rows[0]['id']);
        $this->assertSame(5, $rows[0]['image_color_id'], 'показана красная, а не та, в какой нарисована');
        $this->assertSame(5, $rows[0]['code_color'], 'ссылка ведёт в этот же цвет');
    }

    /** Тот же предмет, другой код — другой цвет. */
    public function test_another_code_names_another_colour(): void
    {
        $this->assertSame(7, $this->results('4243815')[0]['image_color_id']);
    }

    public function test_an_alternate_item_number_finds_the_item_too(): void
    {
        $rows = $this->results('3005old');

        $this->assertCount(1, $rows);
        $this->assertSame('3005', $rows[0]['id']);
        $this->assertNull($rows[0]['code_color'], 'второе имя предмета цвета не знает');
    }

    /**
     * Поиск по артикулу не должен пострадать.
     *
     * Коды и вторые имена подмешиваются к найденному, а не ложатся в индекс:
     * в индексе они утяжеляли строку, и деталь по собственному номеру
     * оказывалась седьмой.
     */
    public function test_a_part_still_leads_on_its_own_number(): void
    {
        $rows = $this->results('3031');

        $this->assertSame('3031', $rows[0]['id'], 'деталь впереди набора 30310-1');
        $this->assertSame(11, $rows[0]['image_color_id'], 'цвет картинки из справочника');
        $this->assertNull($rows[0]['code_color'], 'цвета в ссылке нет, адрес остаётся коротким');
    }

    /**
     * Один предмет под одним кодом в нескольких цветах — восемь случаев на
     * весь справочник. Выбирать за человека не из чего: показываем как обычно.
     */
    public function test_a_code_that_names_two_colours_of_one_part_changes_nothing(): void
    {
        DB::table('bl_element_codes')->insert([
            ['item_type' => 'P', 'item_id' => '3005', 'color_id' => 5, 'code' => '606666'],
            ['item_type' => 'P', 'item_id' => '3005', 'color_id' => 7, 'code' => '606666'],
        ]);

        $rows = $this->results('606666');

        $this->assertSame('3005', $rows[0]['id']);
        $this->assertSame(11, $rows[0]['image_color_id']);
        $this->assertNull($rows[0]['code_color']);
    }

    /** Один код на несколько разных предметов — 402 случая, обычно листы наклеек. */
    public function test_a_code_shared_by_two_items_finds_both(): void
    {
        DB::table('bl_element_codes')->insert([
            ['item_type' => 'P', 'item_id' => '3005', 'color_id' => 5, 'code' => '190145'],
            ['item_type' => 'P', 'item_id' => '3031', 'color_id' => 7, 'code' => '190145'],
        ]);

        $rows = $this->results('190145');

        $this->assertSame(['3005', '3031'], collect($rows)->pluck('id')->sort()->values()->all());
    }

    /** Предмет, который нашёлся и по названию, и по коду, в списке один. */
    public function test_an_item_found_both_ways_appears_once(): void
    {
        DB::table('bl_element_codes')->insert([
            ['item_type' => 'P', 'item_id' => '3031', 'color_id' => 5, 'code' => 'plate'],
        ]);

        $rows = $this->results('plate');

        $this->assertSame(['3031'], collect($rows)->pluck('id')->all());
    }
}
