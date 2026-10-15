<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Normalizes uploaded phone photos (fixes EXIF rotation, downsizes, re-encodes as JPEG) and moves them
 * through their life cycle on the public disk:
 *   tmp/   - uploaded in the add/edit form, not saved yet (pruned after a day)
 *   inbox/ - waiting in the bulk scan / check queue
 *   diecasts/ - belongs to a saved diecast
 */
class PhotoStorage
{
    public const TMP_DIR = 'diecasts/tmp';

    public const INBOX_DIR = 'diecasts/inbox';

    public const DIR = 'diecasts';

    private const MAX_EDGE = 1600;

    /**
     * Store an upload (normalized to JPEG) and return its path on the public disk.
     */
    public function store(UploadedFile $file, string $dir = self::TMP_DIR): string
    {
        $source = $this->load($file);
        $source = $this->orient($source, $file);
        $image = $this->resize($source);

        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = (string) ob_get_clean();

        $path = $dir.'/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        if ($dir === self::TMP_DIR) {
            $this->pruneTemporary();
        }

        return $path;
    }

    /**
     * Store raw JPEG bytes as a pending upload (used when restoring a backup).
     */
    public function storeBytes(string $bytes): string
    {
        $path = self::TMP_DIR.'/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    /**
     * Move a tmp/inbox photo into permanent storage. Returns the new path.
     */
    public function promote(string $pendingPath): string
    {
        $path = self::DIR.'/'.basename($pendingPath);
        Storage::disk('public')->move($pendingPath, $path);

        return $path;
    }

    /**
     * True for an uploaded-but-unsaved photo (tmp or inbox) that still exists.
     */
    public function isPending(?string $path): bool
    {
        return $path !== null
            && preg_match('#^diecasts/(tmp|inbox)/[0-9a-f-]{36}\.jpg$#', $path) === 1
            && Storage::disk('public')->exists($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk('public')->exists($path);
    }

    public function contents(string $path): string
    {
        return (string) Storage::disk('public')->get($path);
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    public function base64(string $path): string
    {
        return base64_encode($this->contents($path));
    }

    /**
     * Remove form uploads that were never saved to a diecast.
     */
    public function pruneTemporary(): void
    {
        $disk = Storage::disk('public');

        foreach ($disk->files(self::TMP_DIR) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }

    private function load(UploadedFile $file): \GdImage
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw new RuntimeException('Format foto tidak didukung. Gunakan JPG, PNG, atau WEBP.');
        }

        return $image;
    }

    private function orient(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'])) {
            return $image;
        }

        $orientation = @exif_read_data($file->getRealPath())['Orientation'] ?? 1;

        $rotated = match ((int) $orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated ?: $image;
    }

    private function resize(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_EDGE / max($width, $height));

        $newWidth = (int) round($width * $scale);
        $newHeight = (int) round($height * $scale);

        // Always copy onto a white canvas so transparent PNGs don't turn black as JPEG.
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $canvas;
    }
}
