<?php

namespace App\Catalog\Models;

class Color extends CatalogModel
{
    protected $table = 'bl_colors';

    protected $casts = [
        'year_from' => 'integer',
        'year_to' => 'integer',
    ];

    /** Colour 0 is "(Not Applicable)" and has no RGB of its own. */
    public function swatch(): string
    {
        return $this->rgb ? '#'.$this->rgb : 'transparent';
    }
}
