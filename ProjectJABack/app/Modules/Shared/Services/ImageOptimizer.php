<?php

namespace App\Modules\Shared\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Punto único para guardar fotos. Reutilizar en cualquier módulo nuevo.
 * Recorta el lado mayor a 1600 px y guarda JPEG al 82 %. PNG, WebP, GIF, SVG y no-imágenes se guardan tal cual.
 */
final class ImageOptimizer
{
    public const MAX_EDGE = 1600;

    public const JPEG_QUALITY = 82;

    public function store(UploadedFile $file, string $directory, string $prefix = 'img'): OptimizedStoredFile
    {
        if (! $this->isOptimizable($file)) {
            return $this->storeOriginal($file, $directory);
        }

        $realPath = $file->getRealPath();
        if (! $realPath || ! function_exists('imagecreatefromstring')) {
            return $this->storeOriginal($file, $directory);
        }

        $raw = file_get_contents($realPath);
        $source = $raw !== false ? @imagecreatefromstring($raw) : false;
        if ($source === false) {
            return $this->storeOriginal($file, $directory);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_EDGE / max($width, $height));
        $nextWidth = max(1, (int) round($width * $scale));
        $nextHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($nextWidth, $nextHeight);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nextWidth, $nextHeight, $width, $height);
        imagedestroy($source);

        $relative = trim($directory, '/').'/'.uniqid($prefix.'_', true).'.jpg';
        $absolute = Storage::disk('public')->path($relative);
        Storage::disk('public')->makeDirectory(dirname($relative));
        imagejpeg($canvas, $absolute, self::JPEG_QUALITY);
        imagedestroy($canvas);

        return $this->describe($relative, 'image/jpeg');
    }

    public function isOptimizable(UploadedFile $file): bool
    {
        $mime = (string) $file->getMimeType();

        return str_starts_with($mime, 'image/')
            && ! in_array($mime, ['image/gif', 'image/svg+xml', 'image/png', 'image/webp'], true);
    }

    private function storeOriginal(UploadedFile $file, string $directory): OptimizedStoredFile
    {
        $path = $file->store($directory, 'public');

        return $this->describe($path, $file->getMimeType() ?: 'application/octet-stream');
    }

    private function describe(string $path, string $mime): OptimizedStoredFile
    {
        $absolute = Storage::disk('public')->path($path);

        return new OptimizedStoredFile(
            path: $path,
            size: is_file($absolute) ? (int) filesize($absolute) : 0,
            mime: $mime,
            hash: is_file($absolute) ? (hash_file('sha256', $absolute) ?: null) : null,
        );
    }
}
