<?php

namespace App\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Base for everything in bl_*.
 *
 * The catalog is replaced wholesale by the importer, which writes through the
 * query builder. Nothing else may touch it, and that boundary is enforced here
 * rather than left to discipline: a stray save() in a controller would be
 * silently undone by the next import, which is a miserable thing to debug.
 */
abstract class CatalogModel extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting', 'saving'] as $event) {
            static::$event(function (self $model) use ($event) {
                throw new LogicException(sprintf(
                    'The catalog is read-only; %s on %s is not allowed. Only the importer writes bl_* tables.',
                    $event,
                    static::class,
                ));
            });
        }
    }
}
