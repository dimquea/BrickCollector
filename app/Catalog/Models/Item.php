<?php

namespace App\Catalog\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A catalog entry: a set, part, minifigure, gear, book or paper catalog.
 *
 * The key is (type, id), not an autoincrement: those are BrickLink's own
 * identifiers and everything else in the archive refers to items by them.
 */
class Item extends CatalogModel
{
    protected $table = 'bl_items';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $casts = [
        'year' => 'integer',
        'weight' => 'float',
        'image_color_id' => 'integer',
        'has_inventory' => 'boolean',
    ];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class, 'theme_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Source URL of the item image at BrickLink.
     *
     * Only ever used through the local image cache: BrickLink refuses direct
     * hotlinking, and hammering it on every page view would be rude anyway.
     */
    public function imageUrl(?int $colorId = null): string
    {
        return strtr(config('brickcollector.image_url'), [
            '{type}' => $this->type,
            '{color}' => (string) ($colorId ?? $this->image_color_id ?? 0),
            '{id}' => $this->id,
        ]);
    }
}
