<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use App\Collection\Models\Wish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Импорт из файла BrickLink XML.
 *
 * Главное здесь — что разбор ничего не создаёт: человек сначала видит, на что
 * соглашается. Остальное про то, во что превращается строка файла: набор с
 * количеством три — это три экземпляра, а деталь — одна партия на всё
 * количество.
 */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'M', 'name' => 'Minifigure'],
            ['code' => 'P', 'name' => 'Part'],
            // Инструкция — тип, который справочник знает, а коллекция не
            // держит: ровно то, что проверяется ниже.
            ['code' => 'I', 'name' => 'Instructions'],
        ]);

        DB::table('bl_colors')->insert([
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
            ['id' => 5, 'name' => 'Red', 'rgb' => 'B30006'],
        ]);

        foreach ([['S', 'set-a'], ['M', 'fig-a'], ['P', 'brick'], ['I', 'inst-a']] as [$type, $id]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'image_color_id' => 11, 'has_inventory' => 0,
            ]);
        }

        DB::table('ref_sources')->insert(['name' => 'Avito', 'sort' => 1, 'is_active' => 1]);
        DB::table('ref_storages')->insert(['name' => 'Полка', 'sort' => 1, 'is_active' => 1]);
        DB::table('ref_tags')->insert(['name' => 'Б/У', 'color' => 'secondary', 'sort' => 1, 'show_in_list' => 0]);
    }

    /** @param array<int, string> $items */
    private function parse(array $items): TestResponse
    {
        $xml = "<INVENTORY>\n".implode("\n", $items)."\n</INVENTORY>";

        return $this->post('/import/parse', [
            'file' => UploadedFile::fake()->createWithContent('order.xml', $xml),
        ], ['Accept' => 'application/json']);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function store(array $rows, string $destination = 'collection', ?array $meta = null): TestResponse
    {
        return $this->postJson('/import', [
            'destination' => $destination,
            'meta' => $meta,
            'rows' => $rows,
        ]);
    }

    public function test_the_page_opens(): void
    {
        $this->get('/import')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Import/Index')
                ->has('dictionaries.sources', 1)
                // Статусов при импорте не спрашивают: коробка и инструкция —
                // свойство экземпляра, а не строки файла.
                ->where('dictionaries.statuses', []));
    }

    /** Разбор показывает, но не создаёт: до нажатия в базе пусто. */
    public function test_reading_a_file_creates_nothing(): void
    {
        $this->parse(['<ITEM><ITEMTYPE>S</ITEMTYPE><ITEMID>set-a</ITEMID><QTY>2</QTY></ITEM>'])
            ->assertOk()
            ->assertJsonPath('rows.0.name', 'Set-a')
            ->assertJsonPath('rows.0.known', true)
            ->assertJsonPath('rows.0.holdable', true)
            ->assertJsonPath('wanted', false);

        $this->assertSame(0, Entry::count());
    }

    public function test_a_row_we_can_not_use_says_why(): void
    {
        $response = $this->parse([
            '<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>nobody-knows</ITEMID><QTY>1</QTY></ITEM>',
            '<ITEM><ITEMTYPE>I</ITEMTYPE><ITEMID>inst-a</ITEMID><QTY>1</QTY></ITEM>',
            '<ITEM><ITEMTYPE>P</ITEMTYPE><ITEMID>brick</ITEMID><COLOR>5</COLOR><QTY>1</QTY></ITEM>',
        ])->assertOk();

        // Справочник такого не знает.
        $response->assertJsonPath('rows.0.known', false)->assertJsonPath('rows.0.holdable', false);

        // Знает, но коллекция такого не держит.
        $response->assertJsonPath('rows.1.known', true)->assertJsonPath('rows.1.holdable', false);

        $response->assertJsonPath('rows.2.holdable', true)->assertJsonPath('rows.2.color_name', 'Red');
    }

    public function test_something_that_is_not_xml_is_refused_politely(): void
    {
        $this->post('/import/parse', [
            'file' => UploadedFile::fake()->createWithContent('order.xml', 'не файл вовсе'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    /**
     * Набор с количеством три — это три экземпляра: у каждого своя цена, своё
     * место и своя судьба. Деталь — одна партия на всё количество: поступление
     * со своей датой, а не три отдельных.
     */
    public function test_copies_are_made_for_sets_and_one_lot_for_parts(): void
    {
        $this->store([
            ['type' => 'S', 'id' => 'set-a', 'color_id' => null, 'qty' => 3],
            ['type' => 'M', 'id' => 'fig-a', 'color_id' => null, 'qty' => 2],
            ['type' => 'P', 'id' => 'brick', 'color_id' => 5, 'qty' => 7],
        ])->assertOk()->assertJson(['created' => 6, 'skipped' => 0]);

        $this->assertSame(3, Entry::where('item_type', 'S')->count());
        $this->assertSame(2, Entry::where('item_type', 'M')->count());

        $lots = Entry::where('item_type', 'P')->get();

        $this->assertCount(1, $lots, 'деталь кладётся одной партией');
        $this->assertSame(5, (int) $lots->first()->color_id);
        $this->assertSame(7, (int) DB::table('collection_items')->where('entry_id', $lots->first()->id)->value('qty'));
    }

    /** Чего справочник не знает и чего коллекция не держит — не создаётся. */
    public function test_rows_we_can_not_use_are_skipped_and_counted(): void
    {
        $this->store([
            ['type' => 'P', 'id' => 'nobody-knows', 'color_id' => null, 'qty' => 1],
            ['type' => 'I', 'id' => 'inst-a', 'color_id' => null, 'qty' => 1],
            ['type' => 'P', 'id' => 'brick', 'color_id' => 11, 'qty' => 1],
        ])->assertOk()->assertJson(['created' => 1, 'skipped' => 2]);

        $this->assertSame(1, Entry::count());
    }

    /**
     * Построчное перебивает общее полем, а не целиком: задав в строке цену, не
     * должны потерять общий источник.
     */
    public function test_a_row_overrides_the_shared_details_field_by_field(): void
    {
        $source = (int) DB::table('ref_sources')->value('id');
        $storage = (int) DB::table('ref_storages')->value('id');
        $tag = (int) DB::table('ref_tags')->value('id');

        $this->store(
            rows: [
                ['type' => 'P', 'id' => 'brick', 'color_id' => 11, 'qty' => 1, 'meta' => [
                    'price' => 500,
                    'storage_id' => $storage,
                ]],
                ['type' => 'P', 'id' => 'brick', 'color_id' => 5, 'qty' => 1],
            ],
            meta: [
                'price' => 100,
                'source_id' => $source,
                'note' => 'из заказа',
                'tag_ids' => [$tag],
            ],
        )->assertOk()->assertJson(['created' => 2]);

        $overridden = Entry::where('color_id', 11)->firstOrFail();

        $this->assertSame(500, $overridden->price, 'цена взята из строки');
        $this->assertSame($source, $overridden->source_id, 'источник остался общим');
        $this->assertSame($storage, $overridden->storage_id);
        $this->assertSame('из заказа', $overridden->note);
        $this->assertSame([$tag], $overridden->tags()->pluck('ref_tags.id')->all());

        $plain = Entry::where('color_id', 5)->firstOrFail();

        $this->assertSame(100, $plain->price, 'строка без своих полей берёт общие');
    }

    /**
     * У желания нет количества: хотят вещь, а не пять её штук. Повторы в файле
     * схлопываются, а у набора цвета нет вовсе.
     */
    public function test_a_wanted_list_goes_to_the_wishlist(): void
    {
        $this->store([
            ['type' => 'P', 'id' => 'brick', 'color_id' => 5, 'qty' => 3],
            ['type' => 'P', 'id' => 'brick', 'color_id' => 5, 'qty' => 2],
            ['type' => 'S', 'id' => 'set-a', 'color_id' => null, 'qty' => 1],
        ], destination: 'wishlist')->assertOk()->assertJson(['wished' => 2, 'skipped' => 0]);

        $this->assertSame(0, Entry::count(), 'в коллекции при этом ничего не заведено');
        $this->assertSame(2, Wish::count());
        $this->assertSame(5, (int) Wish::where('item_id', 'brick')->value('color_id'));
        $this->assertSame(0, (int) Wish::where('item_id', 'set-a')->value('color_id'));
    }

    public function test_nothing_is_created_without_rows(): void
    {
        $this->store([])->assertStatus(422);

        $this->assertSame(0, Entry::count());
    }
}
