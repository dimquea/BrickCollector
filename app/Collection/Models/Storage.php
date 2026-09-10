<?php

namespace App\Collection\Models;

use Illuminate\Database\Eloquent\Model;

/** Where a copy physically lives: a shelf, a box, a drawer. */
class Storage extends Model
{
    protected $table = 'ref_storages';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
