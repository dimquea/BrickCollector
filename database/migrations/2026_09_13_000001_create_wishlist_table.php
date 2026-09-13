<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Желаемое: чего в коллекции нет, но хочется.
 *
 * Список, а не коллекция: ни количества, ни цены, ни места хранения — всё это
 * про вещь, которой владеешь. Здесь только «хочу вот это».
 *
 * Живёт рядом с коллекцией, а не со справочником: справочник пересоздаётся
 * импортом целиком, а желаемое переживает обновления каталога.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Сырой SQL: у таблицы есть DEFAULT и составной UNIQUE, а объяснение
        // нуля в цвете важнее краткости.
        DB::statement('CREATE TABLE wishlist (
            id         INTEGER PRIMARY KEY,
            item_type  TEXT NOT NULL REFERENCES bl_item_types(code),
            item_id    TEXT NOT NULL,

            -- Цвет есть только у детали; для набора и фигурки это 0,
            -- «неприменимо», как и в составе коллекции. NULL был бы хуже: в
            -- SQLite два NULL считаются разными значениями, и UNIQUE ниже
            -- перестал бы ловить повторное добавление того же набора.
            color_id   INTEGER NOT NULL DEFAULT 0,

            created_at TEXT,
            updated_at TEXT
        )');

        // Одно и то же желание не заводится дважды: кнопка в справочнике
        // нажимается повторно, и это не ошибка, а просто ничего.
        DB::statement('CREATE UNIQUE INDEX ux_wishlist_item ON wishlist(item_type, item_id, color_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist');
    }
};
