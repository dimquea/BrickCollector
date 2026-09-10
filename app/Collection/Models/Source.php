<?php

namespace App\Collection\Models;

use Illuminate\Database\Eloquent\Model;

/** Where a copy came from: a shop, a fair, a friend. */
class Source extends Model
{
    protected $table = 'ref_sources';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
