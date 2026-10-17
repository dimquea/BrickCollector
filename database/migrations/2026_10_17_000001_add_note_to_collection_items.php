<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Комментарий у строки экземпляра.
 *
 * У экземпляра заметка есть давно, а у фигурки внутри набора своего экземпляра
 * нет — она только строка его описи. Особую отметку — трещина на ноге, чужая
 * голова — ставить было некуда. Поле общее для любой строки, а показывается у
 * фигурок и поднаборов: у детали в таблице для него нет места, да и дефект
 * детали — редкость.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            $table->text('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
