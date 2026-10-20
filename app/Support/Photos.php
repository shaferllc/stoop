<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Re-encodes uploaded photos so EXIF data (including GPS location) never gets stored.
 */
class Photos
{
    /**
     * @return array{path: string, width: int, height: int}
     */
    public static function store(UploadedFile $file, string $disk = 'public', string $folder = 'dispatches'): array
    {
        $image = imagecreatefromstring(file_get_contents($file->getRealPath()));

        $orientation = @exif_read_data($file->getRealPath())['Orientation'] ?? 1;
        $image = match ((int) $orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        // Keep stored photos at a sensible size; originals can be up to 6,000px.
        if (imagesx($image) > 2400) {
            $image = imagescale($image, 2400);
        }

        ob_start();
        imagewebp($image, null, 82);
        $path = $folder.'/'.Str::random(32).'.webp';
        Storage::disk($disk)->put($path, ob_get_clean());

        return ['path' => $path, 'width' => imagesx($image), 'height' => imagesy($image)];
    }
}
