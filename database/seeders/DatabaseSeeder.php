<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Ничего не засевает.
 *
 * Строки справочников, которые поставляются с приложением, кладёт миграция:
 * развернуть его — это `migrate` и ничего больше. Аддон Home Assistant так и
 * делает, и отдельный шаг, о котором надо помнить, там было бы негде выполнить.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
