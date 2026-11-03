<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves public uploaded images directly from storage/app/public without needing
 * a symbolic link (which is often missing or blocked on shared hosting after git deploy).
 */
class MediaController extends Controller
{
    public function __invoke(string $path): BinaryFileResponse
    {
        $path = ltrim(str_replace(['..', '\\'], ['', '/'], $path), '/');
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            $webpFallback = preg_replace('/\.[^.\/]+$/', '.webp', $path);
            if ($webpFallback && $disk->exists($webpFallback)) {
                $path = $webpFallback;
            } else {
                abort(404);
            }
        }

        return response()->file($disk->path($path), [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
