<?php

namespace App\Catalog\Models;

class ItemType extends CatalogModel
{
    protected $table = 'bl_item_types';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    /** Types the user actually browses; I, O and U are not imported. */
    public const BROWSABLE = ['S', 'M', 'P', 'G', 'B', 'C'];
}
