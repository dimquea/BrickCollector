<?php

namespace App\Collection;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The picture of an assembly, if its owner gave it one.
 *
 * An assembly has no catalogue counterpart and therefore no picture to fetch,
 * so this one is uploaded — a photo of the thing standing on the shelf. It
 * lives on the same disk as the item image cache, which is inside the data
 * directory and survives an add-on rebuild.
 *
 * The file is named after the entry, so there is nothing to store in the
 * database and nothing that can fall out of step with the file.
 */
class AssemblyImages
{
    private const DISK = 'images';

    private const DIR = 'assemblies';

    /** Extensions a browser can display; the upload is validated against them. */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** Path of the picture on the disk, or null when there is none. */
    public function path(int $entryId): ?string
    {
        $disk = Storage::disk(self::DISK);

        foreach (self::EXTENSIONS as $extension) {
            $path = $this->file($entryId, $extension);

            if ($disk->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function has(int $entryId): bool
    {
        return $this->path($entryId) !== null;
    }

    /** Replaces whatever picture the assembly had. */
    public function put(int $entryId, UploadedFile $file): void
    {
        $this->delete($entryId);

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::EXTENSIONS, true)) {
            $extension = 'jpg';
        }

        Storage::disk(self::DISK)->put($this->file($entryId, $extension), $file->get());
    }

    public function delete(int $entryId): void
    {
        $disk = Storage::disk(self::DISK);

        foreach (self::EXTENSIONS as $extension) {
            $disk->delete($this->file($entryId, $extension));
        }
    }

    public function contents(string $path): ?string
    {
        $disk = Storage::disk(self::DISK);

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    public function mime(string $path): string
    {
        return match (pathinfo($path, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    private function file(int $entryId, string $extension): string
    {
        return self::DIR.'/'.$entryId.'.'.$extension;
    }
}
