<?php

namespace Tests\Feature;

use App\Catalog\Images\ItemImages;
use App\Catalog\Models\Item;
use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Картинки предметов.
 *
 * Кэшируем то, чем человек владеет, — это делает коллекцию независимой от
 * источника. Всё остальное показываем прямо из источника, чтобы никто не ждал
 * очередь. Просмотр страницы при этом ничего не решает и ничего не пишет.
 */
class ItemImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('images');

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        DB::table('bl_colors')->insert([
            ['id' => 0, 'name' => '(Not Applicable)', 'rgb' => '000000'],
            ['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E'],
        ]);

        foreach ([['S', 'set-a'], ['P', 'brick'], ['P', 'plate']] as [$type, $id]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => ucfirst($id),
                'image_color_id' => 0, 'has_inventory' => 0,
            ]);
        }
    }

    private function own(): Entry
    {
        $entry = Entry::create(['item_type' => 'S', 'item_id' => 'set-a']);

        DB::table('collection_items')->insert([
            'entry_id' => $entry->id, 'item_type' => 'P', 'item_id' => 'brick',
            'color_id' => 11, 'qty' => 4, 'lost_qty' => 0, 'counts' => 1,
        ]);

        return $entry;
    }

    public function test_the_queue_holds_what_is_owned_and_nothing_else(): void
    {
        $this->own();

        $added = app(ItemImages::class)->queueCollection();

        $this->assertSame(2, $added, 'сам набор и деталь в нём');
        $this->assertDatabaseHas('image_cache', ['item_type' => 'S', 'item_id' => 'set-a', 'status' => 'pending']);
        $this->assertDatabaseHas('image_cache', ['item_type' => 'P', 'item_id' => 'brick', 'color_id' => 11]);
        $this->assertDatabaseMissing('image_cache', ['item_id' => 'plate']);
    }

    /** Служба дёргает её раз в несколько минут: повтор не должен ничего плодить. */
    public function test_queueing_twice_adds_nothing_the_second_time(): void
    {
        $this->own();

        $images = app(ItemImages::class);
        $images->queueCollection();

        $this->assertSame(0, $images->queueCollection());
        $this->assertSame(2, DB::table('image_cache')->count());
    }

    /** Кэш решает владение, а не то, что человек пролистал. */
    public function test_looking_at_a_page_queues_nothing(): void
    {
        app(ItemImages::class)->availability([['P', 'plate', 11]]);

        $this->assertSame(0, DB::table('image_cache')->count());
    }

    public function test_a_cached_picture_is_served_by_us(): void
    {
        Storage::disk('images')->put('P/brick-11.png', 'not really a png');

        DB::table('image_cache')->insert([
            'item_type' => 'P', 'item_id' => 'brick', 'color_id' => 11,
            'path' => 'P/brick-11.png', 'status' => 'ok', 'fetched_at' => now()->toDateTimeString(),
        ]);

        $this->get('/images/P/brick/11')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    /**
     * Нет в кэше — отправляем к источнику, а не рисуем заглушку: ждать, пока
     * очередь доберётся до картинки, никто не должен.
     */
    public function test_a_picture_we_do_not_have_is_taken_from_the_source(): void
    {
        $expected = Item::where('type', 'P')->where('id', 'brick')->first()->imageUrl(11);

        $this->get('/images/P/brick/11')->assertRedirect($expected);
    }

    /** Спрашивать не у кого: источник знает только то, что есть в справочнике. */
    public function test_an_unknown_item_gets_a_placeholder(): void
    {
        $this->get('/images/P/nothing/0')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');
    }

    /** Из него карточка строит адрес источника сама, не тревожа нас редиректом. */
    public function test_the_source_pattern_reaches_the_frontend(): void
    {
        $this->get('/')->assertInertia(fn ($page) => $page->where(
            'imageUrl',
            config('brickcollector.image_url'),
        ));
    }
}
