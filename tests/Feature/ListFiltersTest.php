<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Фильтры списков, как их присылает браузер.
 *
 * Прежние тесты передавали значения PHP-массивом, поэтому не видели главного:
 * в строке запроса всё — строки. Галочка «Некомплект» отправляла
 * incomplete=true, правило boolean эту строку не пускало, и фильтр молча не
 * работал. Здесь значения идут в адресе, ровно так, как их шлёт страница.
 */
class ListFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bl_item_types')->insert([
            ['code' => 'S', 'name' => 'Set'],
            ['code' => 'P', 'name' => 'Part'],
        ]);

        foreach (['set-a', 'set-b', 'set-c'] as $id) {
            DB::table('bl_items')->insert([
                'type' => 'S', 'id' => $id, 'name' => ucfirst($id),
                'image_color_id' => 0, 'has_inventory' => $id === 'set-a' ? 1 : 0,
            ]);
        }

        Entry::create(['item_type' => 'S', 'item_id' => 'set-a', 'flag_incomplete' => true]);
        Entry::create(['item_type' => 'S', 'item_id' => 'set-b', 'flag_missing_figs' => true]);
        Entry::create(['item_type' => 'S', 'item_id' => 'set-c']);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function switchValues(): array
    {
        return [
            'true, как шлёт страница' => ['true', 1],
            'единица' => ['1', 1],
            'on, как шлёт обычная форма' => ['on', 1],
            'false — фильтра нет' => ['false', 3],
            'ноль — фильтра нет' => ['0', 3],
            'мусор — фильтра нет' => ['maybe', 3],
        ];
    }

    /** @dataProvider switchValues */
    public function test_a_switch_is_read_the_way_a_browser_sends_it(string $value, int $expected): void
    {
        $this->get('/sets?incomplete='.$value)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('entries.total', $expected));
    }

    public function test_both_switches_of_the_sets_list_work(): void
    {
        $this->get('/sets?missing_figs=true')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('entries.total', 1)
                ->where('entries.data.0.item_id', 'set-b'));
    }

    public function test_the_catalogue_switch_works_too(): void
    {
        $this->get('/catalog?has_inventory=true')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.total', 1));
    }

    /**
     * Выключенный переключатель — отсутствие фильтра. В ответ страница должна
     * получить его отсутствующим, а не false: иначе она сама отправит
     * incomplete=false следующим запросом.
     */
    public function test_an_off_switch_does_not_come_back_as_a_filter(): void
    {
        $this->get('/sets?incomplete=false')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('filters.incomplete'));
    }

    /**
     * Адрес с фильтром копируют и правят руками. Негодное значение заслуживает
     * полного списка, а не редиректа «назад».
     *
     * @return array<string, array{0: string}>
     */
    public static function nonsense(): array
    {
        return [
            'год буквами' => ['/sets?year=abc'],
            'несуществующий тип' => ['/sets?type=Z'],
            'тема не числом' => ['/sets?theme_id=star-wars'],
            'место, которого нет' => ['/minifigures?placement=nowhere'],
            'цвет словом' => ['/parts?color_id=red'],
            'справочник: тип и год' => ['/catalog?type=Q&year=abc'],
        ];
    }

    /** @dataProvider nonsense */
    public function test_a_value_that_makes_no_sense_is_dropped_not_bounced(string $url): void
    {
        $this->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters', []));
    }

    /** Годное значение рядом с негодным не пропадает вместе с ним. */
    public function test_a_good_filter_survives_a_bad_neighbour(): void
    {
        $this->get('/sets?year=abc&incomplete=true')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters', ['incomplete' => true])
                ->where('entries.total', 1));
    }

    /**
     * Картинка не должна становиться «предыдущей страницей».
     *
     * Список рисует два десятка картинок, и пока их маршрут шёл через сессию,
     * каждая перезаписывала адрес, на который ведёт «назад». В Home Assistant
     * переключение языка или отказ валидации уводили на /images/…
     */
    public function test_a_picture_is_not_where_back_leads(): void
    {
        $this->get('/sets')->assertOk();
        $this->get('/images/S/set-a/0');

        $this->post('/locale', ['locale' => 'en'])->assertRedirect(url('/sets'));
    }

    /** Картинке не нужна сессия, и её ответ не должен ставить cookie. */
    public function test_a_picture_sets_no_cookie(): void
    {
        $response = $this->get('/images/S/set-a/0');

        $this->assertSame([], $response->headers->getCookies());
    }
}
