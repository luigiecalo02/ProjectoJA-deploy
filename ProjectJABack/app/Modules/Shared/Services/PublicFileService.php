<?php

namespace App\Modules\Shared\Services;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve y resuelve archivos del disco public sin pasar por /storage,
 * que en Windows choca con carpetas reales dentro de public/storage.
 */
final class PublicFileService
{
    public function url(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $path = parse_url($value, PHP_URL_PATH) ?: '';
            $stored = $this->storedPathFromUrlPath($path);
            if ($stored === null) {
                return $value;
            }

            return url('api/v1/files/'.$stored);
        }

        $stored = $this->normalizeRelative($value);

        return $stored !== null ? url('api/v1/files/'.$stored) : null;
    }

    public function stream(string $path): StreamedResponse
    {
        $normalized = $this->normalizeRelative($path);
        abort_unless($normalized !== null && Storage::disk('public')->exists($normalized), 404);

        return Storage::disk('public')->response($normalized);
    }

    private function storedPathFromUrlPath(string $path): ?string
    {
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');

        foreach (['/api/v1/files/', '/storage/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $this->normalizeRelative(substr($path, strlen($prefix)));
            }
        }

        return null;
    }

    private function normalizeRelative(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $normalized = ltrim(str_replace('\\', '/', $value), '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = substr($normalized, strlen('storage/'));
        }
        if (str_starts_with($normalized, 'api/v1/files/')) {
            $normalized = substr($normalized, strlen('api/v1/files/'));
        }

        if ($normalized === '' || str_contains($normalized, '..')) {
            return null;
        }

        return $normalized;
    }
}
