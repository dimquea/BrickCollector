<?php

namespace App\Catalog\Import;

use RuntimeException;
use ZipArchive;

/**
 * Read access to a brickstore-database release archive.
 *
 * The archive is opened once and kept open: opening it costs about 590 ms
 * because libzip parses a central directory of ~53,000 entries, while reading
 * an entry afterwards costs a fraction of a millisecond. Never reach for the
 * zip:// stream wrapper instead — it reopens the archive on every read, which
 * measured 1.2 seconds each.
 */
class CatalogArchive
{
    private ZipArchive $zip;

    public function __construct(private readonly string $path)
    {
        if (! is_file($this->path)) {
            throw new RuntimeException("Catalog archive not found: {$this->path}");
        }

        $this->zip = new ZipArchive;

        $opened = $this->zip->open($this->path, ZipArchive::RDONLY);

        if ($opened !== true) {
            throw new RuntimeException("Cannot open catalog archive {$this->path} (code {$opened})");
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function has(string $name): bool
    {
        return $this->zip->locateName($name) !== false;
    }

    /** Contents of one entry, or null when it is missing. */
    public function get(string $name): ?string
    {
        $contents = $this->zip->getFromName($name);

        return $contents === false ? null : $contents;
    }

    public function require(string $name): string
    {
        return $this->get($name) ?? throw new RuntimeException("Missing archive entry: {$name}");
    }

    /**
     * Entry names and contents, yielded one at a time so the 497 MB of
     * uncompressed data never sits in memory at once.
     *
     * @return iterable<string, string>
     */
    public function each(?callable $filter = null): iterable
    {
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = $this->zip->getNameIndex($i);

            if ($name === false || ($filter && ! $filter($name))) {
                continue;
            }

            $contents = $this->zip->getFromIndex($i);

            if ($contents !== false) {
                yield $name => $contents;
            }
        }
    }

    /**
     * name => CRC32 for every entry, taken from the central directory.
     *
     * This is what makes incremental updates possible: comparing these values
     * against the previous release shows exactly which entries changed,
     * without decompressing anything. The dates in btinvlist.csv cannot serve
     * the same purpose — two thirds of them are empty.
     *
     * @return array<string, int>
     */
    public function checksums(): array
    {
        $index = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);

            if ($stat !== false) {
                $index[$stat['name']] = $stat['crc'];
            }
        }

        return $index;
    }

    public function close(): void
    {
        $this->zip->close();
    }
}
