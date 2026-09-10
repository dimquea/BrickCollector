<?php

namespace App\Support;

/**
 * Resolves where mutable state lives.
 *
 * Deliberately free of the config() helper: config files are loaded in
 * alphabetical order, so one config file reading another only works by
 * accident of naming. This class is safe to call from any of them.
 *
 * Plain install : storage/app/brickcollector
 * HA add-on     : /addon_config  (needs read_only: false in the add-on manifest)
 */
class DataPath
{
    public static function root(): string
    {
        $root = env('BRICKCOLLECTOR_DATA_PATH') ?: storage_path('app/brickcollector');

        return rtrim($root, "/\\");
    }

    public static function database(): string
    {
        return env('DB_DATABASE') ?: self::root().'/brickcollector.sqlite';
    }

    /** Downloaded catalog release, kept after import so updates can diff CRC32 values. */
    public static function archive(): string
    {
        return env('BRICKCOLLECTOR_ARCHIVE_PATH') ?: self::root().'/downloads.zip';
    }

    /** Cached item images; image_cache rows store paths relative to this. */
    public static function imageCache(): string
    {
        return env('BRICKCOLLECTOR_IMAGE_CACHE_PATH') ?: self::root().'/images';
    }
}
