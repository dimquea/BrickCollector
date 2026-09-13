<?php

namespace App\Collection\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Одна строка желаемого: предмет справочника, который хочется.
 *
 * Меты у желания нет намеренно. Дата покупки, цена и место хранения — свойства
 * вещи, которой владеешь; пока её нет, описывать нечего, кроме самого желания.
 */
class Wish extends Model
{
    protected $table = 'wishlist';

    protected $guarded = [];

    protected $casts = [
        'color_id' => 'integer',
    ];

    /** Цвет осмыслен только у детали; у остального это 0 — «неприменимо». */
    public function hasColour(): bool
    {
        return $this->item_type === 'P' && $this->color_id > 0;
    }
}
