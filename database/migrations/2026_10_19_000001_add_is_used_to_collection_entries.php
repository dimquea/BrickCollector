<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Б/у или новое — состояние экземпляра.
 *
 * Долго это отмечали тегом, но тег — свободная пометка: его удаляют и
 * переименовывают, расформирование его отбрасывает, импорт о нём не знает. А
 * для магазина состояние — не пометка, а половина цены: б/у деталь, ушедшая
 * новой, — это возврат. Двух значений хватает, у BrickLink их тоже два.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->boolean('is_used')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('collection_entries', function (Blueprint $table) {
            $table->dropColumn('is_used');
        });
    }
};
