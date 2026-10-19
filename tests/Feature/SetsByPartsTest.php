<?php

namespace Tests\Feature;

use App\Catalog\Queries\SetsHoldingParts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Поиск набора по горсти деталей.
 *
 * Набор подходит, если в нём есть хотя бы одна штука каждой детали в этом
 * цвете — где угодно в дереве: россыпью, в фигурке, в поднаборе. Альтернативы
 * и парной строки в коробке нет; запасная есть.
 */
class SetsByPartsTest extends TestCase
{
    use RefreshDatabase;

    private const BLACK = 11;

    private const RED = 5;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'M', 'name' => 'Minifigure'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        DB::table('bl_colors')->insert([
            ['id' => self::BLACK, 'name' => 'Black', 'rgb' => '2E2E2E'],
            ['id' => self::RED, 'name' => 'Red', 'rgb' => 'B30006'],
        ]);

        DB::table('bl_categories')->insert([['id' => 1, 'name' => 'Town']]);
        DB::table('bl_themes')->insert([
            ['id' => 1, 'parent_id' => null, 'root_category_id' => 1, 'name' => 'Town', 'path' => 'Town', 'depth' => 0],
            ['id' => 2, 'parent_id' => null, 'root_category_id' => 1, 'name' => 'Space', 'path' => 'Space', 'depth' => 0],
        ]);

        foreach ([
            ['S', 'direct', 1], ['S', 'in-figure', 1], ['S', 'in-subset', 2], ['S', 'alternate', 1],
            ['S', 'counterpart', 1], ['S', 'spare', 1], ['S', 'red', 1], ['S', 'both', 1],
            ['S', 'pack', 1], ['M', 'fig', null], ['P', 'brick', null], ['P', 'plate', null],
        ] as [$type, $id, $theme]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id), 'theme_id' => $theme,
                'image_color_id' => self::BLACK, 'has_inventory' => $type === 'P' ? 0 : 1,
            ]);
        }

        $this->lot('direct', 'P', 'brick', self::BLACK);
        $this->lot('in-figure', 'M', 'fig', 0);
        $this->lot('fig', 'P', 'brick', self::BLACK, parentType: 'M');
        $this->lot('in-subset', 'S', 'pack', 0);
        $this->lot('pack', 'P', 'brick', self::BLACK);
        $this->lot('alternate', 'P', 'brick', self::BLACK, ['is_alternate' => 1]);
        $this->lot('counterpart', 'P', 'brick', self::BLACK, ['is_counterpart' => 1]);
        $this->lot('spare', 'P', 'brick', self::BLACK, ['is_extra' => 1]);
        $this->lot('red', 'P', 'brick', self::RED);
        $this->lot('both', 'P', 'brick', self::BLACK);
        $this->lot('both', 'P', 'plate', self::RED);
    }

    /** @param array<string, int> $flags */
    private function lot(string $parent, string $type, string $id, int $colour, array $flags = [], string $parentType = 'S'): void
    {
        DB::table('bl_inventory')->insert(array_merge([
            'parent_type' => $parentType, 'parent_id' => $parent, 'child_type' => $type, 'child_id' => $id,
            'color_id' => $colour, 'qty' => 1,
        ], $flags));
    }

    /** @return array<int, string> */
    private function found(string $query): array
    {
        return collect($this->get('/catalog?'.$query)->assertOk()->viewData('page')['props']['results']['data'])
            ->pluck('id')->sort()->values()->all();
    }

    public function test_a_part_is_found_however_deep_it_sits(): void
    {
        $this->assertSame(
            ['both', 'direct', 'in-figure', 'in-subset', 'pack', 'spare'],
            $this->found('parts=brick:'.self::BLACK),
            'россыпью, в фигурке, в поднаборе и сам поднабор; запасная — в коробке; альтернатива и парная — нет',
        );
    }

    public function test_the_colour_counts_and_an_empty_one_means_any(): void
    {
        $this->assertSame(['red'], $this->found('parts=brick:'.self::RED));
        $this->assertContains('red', $this->found('parts=brick:'));
        $this->assertContains('direct', $this->found('parts=brick:'));
    }

    /** Каждая из деталей — в одном и том же наборе. */
    public function test_several_parts_must_all_be_there(): void
    {
        $this->assertSame(['both'], $this->found('parts=brick:'.self::BLACK.',plate:'.self::RED));
        $this->assertSame([], $this->found('parts=brick:'.self::BLACK.',plate:'.self::BLACK));
    }

    /** Отбор складывается с остальными: тема сужает найденное. */
    public function test_it_combines_with_the_other_filters(): void
    {
        $this->assertSame(['in-subset'], $this->found('parts=brick:'.self::BLACK.'&theme_id=2'));
    }

    /** Находятся только наборы: фигурка, где лежит деталь, — путь к набору, а не ответ. */
    public function test_only_sets_come_back(): void
    {
        $this->assertNotContains('fig', $this->found('parts=brick:'.self::BLACK));
    }

    public function test_the_address_is_read_forgivingly(): void
    {
        $this->assertSame(
            [['id' => 'brick', 'color' => 11], ['id' => 'plate', 'color' => null]],
            SetsHoldingParts::parse('brick:11, plate: ,:5,bad:x,brick:11'),
            'пустой артикул и цвет не числом отброшены, повтор — тоже',
        );

        $many = implode(',', array_map(fn ($i) => "p{$i}:", range(1, 15)));

        $this->assertCount(SetsHoldingParts::LIMIT, SetsHoldingParts::parse($many));
    }

    public function test_the_lookup_knows_an_item_number(): void
    {
        $this->getJson('/catalog/parts/lookup?q=brick')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('id', 'brick')
            ->assertJsonPath('color_id', null)
            ->assertJsonCount(2, 'colours');
    }

    /** Номер из инструкции называет деталь вместе с цветом. */
    public function test_the_lookup_knows_an_element_number(): void
    {
        DB::table('bl_element_codes')->insert(['item_type' => 'P', 'item_id' => 'brick', 'color_id' => self::RED, 'code' => '4243815']);

        $this->getJson('/catalog/parts/lookup?q=4243815')
            ->assertJsonPath('id', 'brick')
            ->assertJsonPath('color_id', self::RED);
    }

    public function test_the_lookup_says_when_there_is_no_such_part(): void
    {
        $this->getJson('/catalog/parts/lookup?q=nope')->assertJsonPath('found', false);
        $this->getJson('/catalog/parts/lookup?q=fig')->assertJsonPath('found', false, 'фигурка — не деталь');
    }
}
