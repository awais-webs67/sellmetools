<?php

namespace App\Filament\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Payment screenshots live on the private "local" disk. The admin panel has no route
 * to serve them, so they are embedded inline as base64 data URIs.
 */
final class Screenshots
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public static function dataUri(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        try {
            $disk = Storage::disk('local');

            if (! $disk->exists($path) || $disk->size($path) > self::MAX_BYTES) {
                return null;
            }

            $mime = $disk->mimeType($path) ?: 'image/jpeg';

            if (! str_starts_with($mime, 'image/')) {
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Safe <img> markup for the screenshot, or a muted notice when unavailable. */
    public static function html(?string $path, string $maxHeight = '70vh'): string
    {
        $uri = self::dataUri($path);

        if ($uri === null) {
            return '<p style="color: rgb(107 114 128);">'.(blank($path) ? 'No screenshot uploaded.' : 'Screenshot file not found.').'</p>';
        }

        return '<img src="'.e($uri).'" alt="Payment screenshot" style="max-width: 100%; max-height: '.e($maxHeight).'; border-radius: 0.5rem; margin: 0 auto; display: block;">';
    }
}
