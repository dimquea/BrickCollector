<?php

namespace App\Collection\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node of what an entry consists of.
 *
 * Copied from the catalog when the entry is created rather than read through
 * it: a physical set does not change when BrickLink edits an inventory, and
 * losses are recorded against a specific lot of a specific copy.
 */
class Item extends Model
{
    protected $table = 'collection_items';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'qty' => 'integer',
        'lost_qty' => 'integer',
        'is_extra' => 'boolean',
        'is_alternate' => 'boolean',
        'is_counterpart' => 'boolean',
        'match_id' => 'integer',
        'counts' => 'boolean',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'entry_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
