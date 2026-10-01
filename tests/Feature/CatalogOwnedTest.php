<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «В коллекции» — блок на карточке справочника.
 *
 * Карточка говорит о предмете вообще, и до сих пор вопрос «а он у меня есть и
 * где именно» задавался только из разделов коллекции: ответ надо было знать
 * заранее, чтобы его получить.
 */
class CatalogOwnedTest extends TestCase
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
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
            ['id' => 5, 'name' => 'Red', 'rgb' => 'B30006'],
        ]);

        foreach ([['S', 'set-a'], ['M', 'fig'], ['P', 'brick']] as [$type, $id]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'image_color_id' => 0, 'has_inventory' => 0,
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function row(Entry $entry, array $attributes): int
    {
        return DB::table('collection_items')->insertGetId(array_merge([
            'entry_id' => $entry->id, 'item_type' => 'P', 'color_id' => 11,
            'qty' => 1, 'lost_qty' => 0, 'counts' => 1,
        ], $attributes));
    }

    /** @return array<string, array<int, array<string, mixed>>> ключ раздела => строки */
    private function owned(string $url): array
    {
        $page = $this->get($url)->assertOk()->viewData('page');

        return collect($page['props']['owned'] ?? [])
            ->mapWithKeys(fn (array $group) => [$group['key'] => $group['rows']])
            ->all();
    }

    public function test_a_part_is_shown_where_it_lies(): void
    {
        $set = Entry::create(['item_type' => 'S', 'item_id' => 'set-a']);
        $this->row($set, ['item_id' => 'brick', 'qty' => 4, 'parent_item_type' => 'S']);

        $figId = $this->row($set, ['item_type' => 'M', 'item_id' => 'fig', 'color_id' => 0]);
        $this->row($set, ['item_id' => 'brick', 'qty' => 2, 'parent_id' => $figId, 'parent_item_type' => 'M']);

        $lot = Entry::create(['item_type' => 'P', 'item_id' => 'brick', 'color_id' => 11]);
        $this->row($lot, ['item_id' => 'brick', 'qty' => 7]);

        $assembly = Entry::create(['name' => 'Домик']);
        $this->row($assembly, ['item_id' => 'brick', 'qty' => 3]);

        $owned = $this->owned('/catalog/P/brick?color=11');

        $this->assertSame(['loose', 'entries', 'minifigures', 'assemblies'], array_keys($owned));
        $this->assertSame(7, $owned['loose'][0]['qty']);
        $this->assertSame('/parts/copy/'.$lot->id, '/parts/copy/'.$owned['loose'][0]['entry_id']);
        $this->assertSame('Set-a', $owned['entries'][0]['title']);
        $this->assertSame(4, $owned['entries'][0]['qty'], 'деталь набора, но не та, что внутри фигурки');
        $this->assertSame('fig', $owned['minifigures'][0]['item_id']);
        $this->assertTrue($owned['minifigures'][0]['counts']);
        $this->assertSame('Домик', $owned['assemblies'][0]['title']);
    }

    /** Блок живёт в цвете карточки — как картинка, внешние ссылки и окно добавления. */
    public function test_a_part_in_another_colour_is_not_counted(): void
    {
        $lot = Entry::create(['item_type' => 'P', 'item_id' => 'brick', 'color_id' => 11]);
        $this->row($lot, ['item_id' => 'brick', 'qty' => 7]);

        $this->assertArrayHasKey('loose', $this->owned('/catalog/P/brick?color=11'));
        $this->assertSame([], $this->owned('/catalog/P/brick?color=5'));
    }

    public function test_a_set_shows_its_copies_and_what_holds_it(): void
    {
        $first = Entry::create(['item_type' => 'S', 'item_id' => 'set-a', 'acquired_at' => '2024-03-01']);
        $this->row($first, ['item_type' => 'S', 'item_id' => 'set-a', 'color_id' => 0]);

        $second = Entry::create(['item_type' => 'S', 'item_id' => 'set-a']);
        $this->row($second, ['item_type' => 'S', 'item_id' => 'set-a', 'color_id' => 0]);

        $owned = $this->owned('/catalog/S/set-a');

        $this->assertSame(['copies'], array_keys($owned));
        $this->assertCount(2, $owned['copies']);
        $this->assertSame('2024-03-01', $owned['copies'][0]['subtitle'], 'экземпляры различают по дню');
        $this->assertSame($first->id, $owned['copies'][0]['entry_id']);
    }

    /**
     * Фигурка чаще всего достаётся внутри набора.
     *
     * Её собственная строка в наборе стоит в корне, как и у отдельного
     * экземпляра, поэтому «внутри чего-то» отличается не родителем, а тем,
     * что экземпляр — это и есть сама фигурка.
     */
    public function test_a_minifigure_tells_its_own_copies_from_the_sets(): void
    {
        $set = Entry::create(['item_type' => 'S', 'item_id' => 'set-a']);
        $this->row($set, ['item_type' => 'M', 'item_id' => 'fig', 'color_id' => 0, 'qty' => 2]);

        $copy = Entry::create(['item_type' => 'M', 'item_id' => 'fig']);
        $this->row($copy, ['item_type' => 'M', 'item_id' => 'fig', 'color_id' => 0]);

        $owned = $this->owned('/catalog/M/fig');

        $this->assertSame(['copies', 'entries'], array_keys($owned));
        $this->assertSame($copy->id, $owned['copies'][0]['entry_id']);
        $this->assertSame($set->id, $owned['entries'][0]['entry_id']);
        $this->assertSame(2, $owned['entries'][0]['qty']);
    }

    /** Чего в коллекции нет, о том и блока нет: пустые вкладки обещают больше, чем показывают. */
    public function test_nothing_owned_means_no_block(): void
    {
        $this->get('/catalog/P/brick')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('owned', null));
    }
}
