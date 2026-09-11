<?php

namespace App\Collection\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Блок ссылок на чужой каталог.
 *
 * Шесть строк кладёт миграция, пользователь их только настраивает: добавить или
 * удалить блок нельзя — на них завязан вывод кнопок, а два последних и так
 * оставлены под его собственные ресурсы.
 *
 * Пустой паттерн — не «не заполнено», а «этот сайт про такой тип не знает»:
 * кнопки просто не будет.
 */
class Link extends Model
{
    protected $table = 'ref_links';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /** У инструкции один паттерн: инструкция бывает только у набора. */
    public function isSetOnly(): bool
    {
        return $this->code === 'instructions';
    }
}
