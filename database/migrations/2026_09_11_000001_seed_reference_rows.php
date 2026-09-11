<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Строки справочников, которые поставляются вместе с приложением.
 *
 * Миграцией, а не сеялкой: разворачивание — это `migrate` и ничего больше.
 * Аддон Home Assistant так и делает, и пока это лежало в сеялке, у него не
 * было ни системных статусов, ни блоков ссылок — при том что удалить их
 * пользователь не может, они часть приложения.
 *
 * Идемпотентна: что человек поправил под себя, миграция не трогает.
 */
return new class extends Migration
{
    /** @var array<int, array<string, mixed>> */
    private array $statuses = [
        ['code' => 'box', 'name' => 'Box', 'sort' => 10],
        ['code' => 'manual', 'name' => 'Instructions', 'sort' => 20],
    ];

    /**
     * Шесть блоков ссылок. Адреса трёх известных каталогов выверены на живых
     * сайтах и приведены в том виде, в каком те их отдают; два последних блока
     * оставлены под собственные ресурсы пользователя.
     *
     * Пустой паттерн — признак «сайт про такое не знает»: у Rebrickable своя
     * нумерация фигурок, и наш артикул привёл бы в никуда.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $links = [
        [
            'code' => 'bricklink', 'label' => 'BrickLink', 'enabled' => true, 'sort' => 10,
            'url_set' => 'https://www.bricklink.com/v2/catalog/catalogitem.page?S={id}',
            'url_minifig' => 'https://www.bricklink.com/v2/catalog/catalogitem.page?M={id}',
            'url_part' => 'https://www.bricklink.com/v2/catalog/catalogitem.page?P={id}&idColor={color}',
        ],
        [
            'code' => 'rebrickable', 'label' => 'Rebrickable', 'enabled' => true, 'sort' => 20,
            'url_set' => 'https://rebrickable.com/sets/{id}',
            'url_minifig' => null,
            'url_part' => 'https://rebrickable.com/parts/{id}',
        ],
        [
            // Brickset адресует набор номером без варианта: 75005, не 75005-1.
            'code' => 'brickset', 'label' => 'Brickset', 'enabled' => true, 'sort' => 30,
            'url_set' => 'https://brickset.com/sets/{number}',
            'url_minifig' => 'https://brickset.com/minifigs/{id}',
            'url_part' => 'https://brickset.com/parts/{id}',
        ],
        [
            // Инструкция бывает только у набора, поэтому паттерн один.
            'code' => 'instructions', 'label' => null, 'enabled' => false, 'sort' => 40,
            'url_set' => null, 'url_minifig' => null, 'url_part' => null,
        ],
        [
            'code' => 'custom1', 'label' => null, 'enabled' => false, 'sort' => 50,
            'url_set' => null, 'url_minifig' => null, 'url_part' => null,
        ],
        [
            'code' => 'custom2', 'label' => null, 'enabled' => false, 'sort' => 60,
            'url_set' => null, 'url_minifig' => null, 'url_part' => null,
        ],
    ];

    public function up(): void
    {
        foreach ($this->statuses as $status) {
            DB::table('ref_statuses')->updateOrInsert(
                ['code' => $status['code']],
                ['name' => $status['name'], 'is_system' => true, 'sort' => $status['sort']],
            );
        }

        foreach ($this->links as $block) {
            $existing = DB::table('ref_links')->where('code', $block['code'])->first();

            if ($existing === null) {
                DB::table('ref_links')->insert($block);

                continue;
            }

            // Установка, которая появилась раньше этой миграции: строки есть,
            // адресов в них нет. Заполняем пустое и включаем блок — правку
            // пользователя это не затрагивает.
            $update = [];

            foreach (['url_set', 'url_minifig', 'url_part'] as $column) {
                if ($block[$column] !== null && ($existing->{$column} === null || $existing->{$column} === '')) {
                    $update[$column] = $block[$column];
                }
            }

            if ($update !== []) {
                $update['enabled'] = $block['enabled'];

                DB::table('ref_links')->where('code', $block['code'])->update($update);
            }
        }
    }

    public function down(): void
    {
        // Строки создаёт миграция таблиц, а не эта; откат снимает только то,
        // что здесь и проставлено.
        foreach ($this->links as $block) {
            DB::table('ref_links')->where('code', $block['code'])->update([
                'url_set' => null,
                'url_minifig' => null,
                'url_part' => null,
            ]);
        }
    }
};
