<?php

namespace App\Collection\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $table = 'ref_tags';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['show_in_list' => 'boolean'];
}
