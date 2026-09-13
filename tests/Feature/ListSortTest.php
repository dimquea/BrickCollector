<?php

namespace Tests\Feature;

use App\Collection\Models\Entry;
use App\Http\ListSort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Порядок списка живёт в адресе.
 *
 * Там, а не в настройках, потому что он должен переживать фильтрацию, переход
 * по страницам и пересылку ссылки: «наборы 2019 года, от больших к меньшим» —
 * это один адрес, а не последовательность кликов.
 */
class ListSortTest extends TestCase
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

        DB::table('bl_colors')->insert([['id' => 11, 'name' => 'Black', 'rgb' => '2E2E2E']]);

        foreach ([
            ['S', 'big', 'Big castle', 2010],
            ['S', 'small', 'Alpha rover', 2020],
            ['P', 'brick', 'Brick 2 x 4', null],
            ['P', 'plate', 'Alpha plate', null],
        ] as [$type, $id, $name, $year]) {
            DB::table('bl_items')->insert([
                'type' => $type, 'id' => $id, 'name' => $name, 'year' => $year,
                'image_color_id' => 11, 'has_inventory' => 0,
            ]);
        }

        // «Big castle» — пять деталей, «Alpha rover» — одна. Заводятся в этом
        // порядке, поэтому по умолчанию сверху окажется вторая.
        $this->set('big', ['brick' => 4, 'plate' => 1]);
        $this->set('small', ['brick' => 1]);
    }

    /** @param array<string, int> $parts */
    private function set(string $itemId, array $parts): Entry
    {
        $entry = Entry::create(['item_type' => 'S', 'item_id' => $itemId]);

        foreach ($parts as $part => $qty) {
            DB::table('collection_items')->insert([
                'entry_id' => $entry->id, 'item_type' => 'P', 'item_id' => $part,
                'color_id' => 11, 'qty' => $qty, 'lost_qty' => 0, 'counts' => 1,
            ]);
        }

        return $entry;
    }

    /**
     * Артикул строки списка. В коллекции он лежит в item_id — строка там
     * описывает экземпляр, а не предмет справочника; в справочнике это id.
     *
     * @return array<int, string>
     */
    private function ids(string $url, string $prop, string $key = 'item_id'): array
    {
        $page = $this->get($url)->assertOk()->viewData('page');

        return array_column($page['props'][$prop]['data'], $key);
    }

    public function test_without_a_choice_every_list_keeps_its_own_order(): void
    {
        // Наборы: последнее заведённое сверху.
        $this->assertSame(['small', 'big'], $this->ids('/sets', 'entries'));

        // Детали: по названию из справочника.
        $this->assertSame(['plate', 'brick'], $this->ids('/parts', 'parts'));
    }

    public function test_a_chosen_field_takes_over(): void
    {
        $this->assertSame(['big', 'small'], $this->ids('/sets?sort=parts&dir=desc', 'entries'));
        $this->assertSame(['small', 'big'], $this->ids('/sets?sort=parts', 'entries'));
        $this->assertSame(['small', 'big'], $this->ids('/sets?sort=name', 'entries'));
        $this->assertSame(['big', 'small'], $this->ids('/sets?sort=year', 'entries'));
        $this->assertSame(['brick', 'plate'], $this->ids('/parts?sort=total&dir=desc', 'parts'));
        // Год по убыванию: 2020, 2010, а детали без года — в конце, где их
        // порядок решает обычный разрыв ничьих, тип и артикул.
        $this->assertSame(
            ['small', 'big', 'brick', 'plate'],
            $this->ids('/catalog?sort=year&dir=desc', 'results', 'id'),
        );
    }

    /**
     * Адрес правят руками, и «sort=потолок» не повод отбросить страницу: раздел
     * показывает свой обычный порядок.
     */
    public function test_a_field_the_list_does_not_know_is_ignored(): void
    {
        $this->assertSame(['small', 'big'], $this->ids('/sets?sort=price&dir=desc', 'entries'));
    }

    /** Порядок переживает фильтры и пагинацию — он едет в тех же ссылках. */
    public function test_the_order_travels_with_the_filters(): void
    {
        $response = $this->get('/sets?sort=parts&dir=desc&year=2010');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('sort.by', 'parts')
            ->where('sort.dir', 'desc')
            ->where('entries.data.0.item_id', 'big')
            ->where('entries.links.1.url', fn ($url) => str_contains($url, 'sort=parts')
                && str_contains($url, 'dir=desc')
                && str_contains($url, 'year=2010')));
    }

    public function test_the_direction_defaults_to_the_one_the_list_uses(): void
    {
        $read = fn (string $query) => ListSort::read(
            Request::create('/sets?'.$query),
            ['id', 'name', 'parts'],
            'added',
            'desc',
        );

        $this->assertSame(['by' => 'added', 'dir' => 'desc'], $read(''), 'без выбора — порядок раздела');
        $this->assertSame(['by' => 'parts', 'dir' => 'asc'], $read('sort=parts'), 'выбранное поле — по возрастанию');
        $this->assertSame(['by' => 'parts', 'dir' => 'desc'], $read('sort=parts&dir=desc'));
        $this->assertSame(['by' => 'added', 'dir' => 'asc'], $read('dir=asc'), 'направление и без поля работает');
        $this->assertSame(['by' => 'added', 'dir' => 'desc'], $read('sort=nope&dir=вверх'));
    }
}
