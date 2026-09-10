<?php

namespace App\Collection\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An entry of the "Status" dictionary.
 *
 * Rows carrying a code are seeded and cannot be deleted. Their label comes
 * from the translation files keyed by that code, not from the name column, or
 * they would stay in whatever language the installation was set up in.
 */
class Status extends Model
{
    protected $table = 'ref_statuses';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_system' => 'boolean'];
}
