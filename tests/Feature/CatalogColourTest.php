<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Цвет, в котором смотрят деталь в справочнике.
 *
 * У карточки один цвет на всё цветное: картинку, внешние ссылки, окно
 * добавления и желание. Прежде его знало одно лишь желание, и карточка,
 * открытая со страницы детали в Reddish Brown, предлагала добавить Dark Tan —
 * тот цвет, в каком деталь нарисована в справочнике.
 */
class CatalogColourTest extends TestCase
{
    use RefreshDatabase;

    private const DARK_TAN = 69;

    private const REDDISH_BROWN = 88;

    private const BLACK = 11;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        DB::table('bl_colors')->insert([
            ['id' => self::BLACK, 'name' => 'Black', 'rgb' => '2E2E2E'],
            ['id' => self::DARK_TAN, 'name' => 'Dark Tan', 'rgb' => '958A73'],
            ['id' => self::REDDISH_BROWN, 'name' => 'Reddish Brown', 'rgb' => '693F23'],
        ]);

        DB::table('bl_items')->insert([
            [
                'type' => 'P', 'id' => 'brick', 'name' => 'Brick',
                'image_color_id' => self::DARK_TAN, 'has_inventory' => 0,
            ],
            ['type' => 'S', 'id' => 'set-a', 'name' => 'Set A', 'image_color_id' => 0, 'has_inventory' => 0],
        ]);

        // Деталь известна справочнику в двух цветах; чёрного среди них нет.
        DB::table('bl_element_codes')->insert([
            ['item_type' => 'P', 'item_id' => 'brick', 'color_id' => self::DARK_TAN, 'code' => '4200000'],
            ['item_type' => 'P', 'item_id' => 'brick', 'color_id' => self::REDDISH_BROWN, 'code' => '4200001'],
        ]);

        // Внешние ссылки собираются на сервере и должны следовать за цветом.
        DB::table('ref_links')->update(['enabled' => true, 'url_part' => 'https://example.test/{id}/{color}']);
    }

    private function colourOf(string $url): int
    {
        return (int) $this->get($url)->assertOk()->viewData('page')['props']['colour'];
    }

    public function test_the_card_opens_in_the_colour_from_the_address(): void
    {
        $this->get('/catalog/P/brick?color='.self::REDDISH_BROWN)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('colour', self::REDDISH_BROWN)
                ->where('links.0.url', 'https://example.test/brick/'.self::REDDISH_BROWN));
    }

    /** Без цвета в адресе карточка выглядит как раньше. */
    public function test_without_a_colour_the_picture_colour_is_used(): void
    {
        $this->assertSame(self::DARK_TAN, $this->colourOf('/catalog/P/brick'));
    }

    /**
     * Адрес правят руками и пересылают: негодный цвет заслуживает обычной
     * карточки, а не отказа — и уж точно не картинки, которой нет.
     */
    public function test_a_colour_that_makes_no_sense_is_dropped(): void
    {
        $this->assertSame(self::DARK_TAN, $this->colourOf('/catalog/P/brick?color=abc'));
        $this->assertSame(self::DARK_TAN, $this->colourOf('/catalog/P/brick?color=4242'));
        $this->assertSame(self::DARK_TAN, $this->colourOf('/catalog/P/brick?color='));
    }

    /**
     * Цвет, в котором пришли, попадает и в выбор — даже если кода элемента на
     * него нет: в наборе деталь в этом цвете есть, раз оттуда пришли. Иначе
     * выбор молча вернул бы карточку в цвет картинки, то есть к тому же багу.
     */
    public function test_the_colour_arrived_in_is_offered_even_when_unknown_to_the_part(): void
    {
        $this->get('/catalog/P/brick?color='.self::BLACK)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('colour', self::BLACK)
                ->has('colours', 3)
                ->where('colours', fn ($colours) => collect($colours)->pluck('id')->sort()->values()->all()
                    === [self::BLACK, self::DARK_TAN, self::REDDISH_BROWN]));
    }

    /** У набора цвета нет: спрашивать о нём нечего. */
    public function test_a_set_ignores_the_colour(): void
    {
        $this->assertSame(0, $this->colourOf('/catalog/S/set-a?color='.self::BLACK));
    }
}
