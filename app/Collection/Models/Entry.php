<?php

namespace App\Collection\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing the user owns.
 *
 * Three identical sets are three entries: metadata, condition and losses
 * belong to a physical copy, not to a catalog number.
 *
 * item_type is NULL for a user-made assembly, which has no catalog
 * counterpart; a CHECK constraint keeps that the only such case.
 */
class Entry extends Model
{
    protected $table = 'collection_entries';

    protected $guarded = [];

    protected $casts = [
        'acquired_at' => 'date',
        'price' => 'integer',
        'flag_incomplete' => 'boolean',
        'flag_missing_figs' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'entry_id');
    }

    /** Top of the tree: the lots of a set, or the item itself for anything else. */
    public function roots(): HasMany
    {
        return $this->items()->whereNull('parent_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'entry_tags', 'entry_id', 'tag_id');
    }

    public function statuses(): BelongsToMany
    {
        return $this->belongsToMany(Status::class, 'entry_statuses', 'entry_id', 'status_id');
    }

    public function isAssembly(): bool
    {
        return $this->item_type === null;
    }
}
