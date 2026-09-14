<?php

namespace Tests\Feature;

use App\Catalog\Images\ItemImages;
use App\Collection\Models\Wish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Желаемое: список того, чего в коллекции нет.
 *
 * Меты у желания нет, поэтому и проверять здесь нечего, кроме самого списка:
 * попал предмет туда или нет, в каком цвете, и виден ли он потом.
 */
class WishlistTest extends TestCase
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
            ['id' => 85, 'name' => 'Dark Bluish Gray', 'rgb' => '595D60'],
        ]);

        DB::table('bl_themes')->insert([
            ['id' => 1, 'name' => 'Star Wars', 'path' => 'Star Wars', 'depth' => 0],
            ['id' => 2, 'name' => 'Town', 'path' => 'Town', 'depth' => 0],
        ]);

        foreach ([
            ['S', 'falcon', 'Millennium Falcon', 2017, 1],
            ['S', 'garage', 'Garage', 1999, 2],
            ['P', 'brick', 'Brick 2 x 4', null, null],
            ['M', 'fig', 'Pilot', 2017, 1],
        ] as [$type, $id, $name, $year, $theme]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => $name, 'year' => $year, 'theme_id' => $theme,
                'image_color_id' => $type === 'P' ? 85 : 0, 'has_inventory' => 0,
            ]);
        }
    }

    public function test_an_item_is_wished_from_the_catalogue(): void
    {
        // Ответ — JSON, а не редирект: желание добавляют, не сходя со страницы
        // справочника, и кнопка меняет вид по ответу.
        $this->postJson('/wishlist', ['type' => 'S', 'id' => 'falcon'])
            ->assertOk()
            ->assertJsonPath('wish.color_id', 0);

        $wish = Wish::first();

        $this->assertSame('falcon', $wish->item_id);
        $this->assertSame(0, $wish->color_id, 'у набора цвета нет');
    }

    /**
     * Кнопку нажимают повторно, и это не ошибка: у желания нет количества, и
     * второе такое же ничего к списку не прибавляет.
     */
    public function test_wishing_the_same_thing_twice_changes_nothing(): void
    {
        $this->post('/wishlist', ['type' => 'S', 'id' => 'falcon']);
        $this->post('/wishlist', ['type' => 'S', 'id' => 'falcon']);

        $this->assertSame(1, Wish::count());
    }

    public function test_a_part_is_wished_in_a_colour(): void
    {
        $this->post('/wishlist', ['type' => 'P', 'id' => 'brick', 'color_id' => 11]);
        $this->post('/wishlist', ['type' => 'P', 'id' => 'brick', 'color_id' => 85]);

        $this->assertSame([11, 85], Wish::orderBy('color_id')->pluck('color_id')->all(),
            'один артикул в двух цветах — два разных желания');

        // Без выбора берётся цвет, в котором деталь показана в справочнике.
        Wish::query()->delete();
        $this->post('/wishlist', ['type' => 'P', 'id' => 'brick']);

        $this->assertSame(85, Wish::first()->color_id);
    }

    public function test_an_item_the_catalogue_does_not_know_is_refused(): void
    {
        $this->post('/wishlist', ['type' => 'S', 'id' => 'nope'])->assertNotFound();

        $this->assertSame(0, Wish::count());
    }

    public function test_the_list_shows_what_is_wished_and_filters_like_the_catalogue(): void
    {
        $this->post('/wishlist', ['type' => 'S', 'id' => 'falcon']);
        $this->post('/wishlist', ['type' => 'S', 'id' => 'garage']);
        $this->post('/wishlist', ['type' => 'P', 'id' => 'brick', 'color_id' => 11]);

        $this->get('/wishlist')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Wishlist/Index')
                ->has('items.data', 3)
                // Последнее добавленное сверху.
                ->where('items.data.0.id', 'brick')
                ->where('items.data.0.color_name', 'Black')
                // Варианты фильтров — из самого списка, а не из справочника.
                ->has('itemTypes', 2)
                ->has('themes', 2)
                ->has('years', 2));

        $this->get('/wishlist?type=S')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('items.data', 2));

        $this->get('/wishlist?theme_id=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.data.0.id', 'falcon')->has('items.data', 1));

        $this->get('/wishlist?year=1999')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.data.0.id', 'garage'));

        $this->get('/wishlist?q=falc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('items.data', 1));

        $this->get('/wishlist?sort=name')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.data.0.id', 'brick'));
    }

    public function test_a_wish_is_removed(): void
    {
        $this->post('/wishlist', ['type' => 'S', 'id' => 'falcon']);

        $this->deleteJson('/wishlist/'.Wish::first()->id)->assertOk();

        $this->assertSame(0, Wish::count());
    }

    /** Желаемое разглядывают, значит картинки нужны так же, как коллекции. */
    public function test_wished_pictures_are_queued_for_caching(): void
    {
        $this->post('/wishlist', ['type' => 'P', 'id' => 'brick', 'color_id' => 11]);

        app(ItemImages::class)->queueCollection();

        $this->assertDatabaseHas('image_cache', [
            'item_type' => 'P', 'item_id' => 'brick', 'color_id' => 11, 'status' => 'pending',
        ]);
    }

    public function test_the_catalogue_page_knows_what_is_already_wished(): void
    {
        $this->get('/catalog/S/falcon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('wishes', []));

        $this->post('/wishlist', ['type' => 'S', 'id' => 'falcon']);

        $this->get('/catalog/S/falcon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('wishes', 1)
                ->where('wishes.0.color_id', 0));
    }
}
