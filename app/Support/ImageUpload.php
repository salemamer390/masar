<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores uploaded files, downscaling large raster images at upload time so the
 * free host's storage lasts. Only shrinks: a small image and any non-image file
 * (PDF, Word) is stored byte-for-byte as before. Aspect ratio is preserved and
 * phone photos keep their EXIF orientation, so a document stays readable.
 */
class ImageUpload
{
    /**
     * Store like $file->store($directory, $disk), with optional optimization.
     *
     * @return array{0:string,1:int} the stored path and the stored size in bytes
     */
    public static function store(UploadedFile $file, string $directory, string $disk, int $maxDim, int $quality): array
    {
        $optimized = self::optimize($file, $maxDim, $quality);
        if ($optimized === null) {
            return [$file->store($directory, $disk), (int) $file->getSize()];
        }

        [$contents, $extension] = $optimized;
        $path = $directory.'/'.Str::random(40).'.'.$extension;
        Storage::disk($disk)->put($path, $contents);

        return [$path, strlen($contents)];
    }

    /**
     * @return array{0:string,1:string}|null [image bytes, extension] or null to keep the original file
     */
    private static function optimize(UploadedFile $file, int $maxDim, int $quality): ?array
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return null; // GD unavailable on this host: store as before.
        }

        $mime = $file->getMimeType();
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file->getPathname()),
            'image/png' => @imagecreatefrompng($file->getPathname()),
            'image/webp' => @imagecreatefromwebp($file->getPathname()),
            default => null, // PDF, Word, GIF and anything else passes through untouched.
        };
        if (!$source) {
            return null;
        }

        // A phone camera marks rotation in EXIF instead of rotating the pixels;
        // apply it so the stored image is not sideways.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $orientation = @exif_read_data($file->getPathname())['Orientation'] ?? null;
            $rotated = match ($orientation) {
                3 => @imagerotate($source, 180, 0),
                6 => @imagerotate($source, -90, 0),
                8 => @imagerotate($source, 90, 0),
                default => null,
            };
            if ($rotated) {
                imagedestroy($source);
                $source = $rotated;
            }
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if (max($width, $height) <= $maxDim) {
            imagedestroy($source);

            return null; // Already small enough: keep the original bytes.
        }

        $scale = $maxDim / max($width, $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        if (!$target) {
            imagedestroy($source);

            return null;
        }
        if ($mime === 'image/jpeg') {
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefill($target, 0, 0, $white);
        } else {
            imagealphablending($target, false);
            imagesavealpha($target, true);
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        $written = match ($mime) {
            'image/jpeg' => imagejpeg($target, null, $quality),
            'image/webp' => imagewebp($target, null, $quality),
            default => imagepng($target, null, 9),
        };
        $contents = ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        if (!$written || !$contents) {
            return null;
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        return [$contents, $extension];
    }
}
