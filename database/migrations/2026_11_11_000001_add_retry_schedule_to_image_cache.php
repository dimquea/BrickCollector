<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Когда снова пробовать картинку, которую не удалось скачать.
 *
 * Неудача прежде была навсегда: очередь брала только новые картинки, а
 * неудачные — лишь по ключу --retry, которого служба не передаёт. Сетевой
 * сбой — а это почти все неудачи — так и оставался сбоем, хотя назавтра
 * картинка скачалась бы.
 *
 * Теперь у строки есть число неудачных попыток и время следующей: каждая новая
 * неудача отодвигает её дальше, чтобы не дёргать источник впустую. Уже
 * накопившиеся неудачи остаются без срока — то есть подходят сразу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_cache', function (Blueprint $table) {
            $table->unsignedInteger('attempts')->default(0);
            $table->string('retry_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('image_cache', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'retry_at']);
        });
    }
};
