<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Что переименовано в коллекции по журналу BrickLink.
 *
 * Переименование идёт само, после каждого обновления справочника, и человек
 * должен видеть, что поменялось: артикул на этикетке коробки и в приложении
 * могут разойтись, и «куда делась 3957» без ответа — загадка. Одна строка — один
 * предмет за один прогон; страница настроек показывает последний прогон.
 *
 * В базе, а не в файле состояния справочника: переименовывает не только
 * импорт, но и «Обновить описи», и отчёт должен пережить следующий импорт, в
 * котором переименовывать оказалось нечего.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_renames', function ($table) {
            $table->id();
            $table->string('renamed_at')->index();
            $table->string('from_type');
            $table->string('from_id');
            $table->string('to_type');
            $table->string('to_id');
            // Сколько экземпляров, строк описей и желаний ссылалось на старый
            // артикул, и на скольких из строк стояла отметка о недостаче или
            // комментарий — ради них всё и затевалось.
            $table->unsignedInteger('entries')->default(0);
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedInteger('wishes')->default(0);
            $table->unsignedInteger('marks')->default(0);
            // Смену типа в описи не пишем — её делает «Обновить описи»; здесь
            // она только для сведения.
            $table->boolean('applied')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_renames');
    }
};
