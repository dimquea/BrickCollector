<?php

namespace App\Catalog\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node of the theme tree, rebuilt by the importer from the path strings in
 * items/*.csv. categories.xml carries only root names.
 */
class Theme extends CatalogModel
{
    protected $table = 'bl_themes';

    protected $casts = ['depth' => 'integer'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
