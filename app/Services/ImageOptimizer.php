<?php

namespace App\Services;

use App\Models\MediaItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImageOptimizer
{
    /**
     * Automatically resizes and compresses an uploaded image on the `public` disk into
     * a lightweight WebP (or optimized JPEG fallback) in kilobytes (KB).
     *
     * @return array{path: string, old_size: int, new_size: int, saved_bytes: int}
     */
    public function optimizePublicFile(string $relativePath, int $maxDimension = 960, int $quality = 80): array
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $disk = Storage::disk('public');

        if ($relativePath === '' || ! $disk->exists($relativePath)) {
            return ['path' => $relativePath, 'old_size' => 0, 'new_size' => 0, 'saved_bytes' => 0];
        }

        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $raw = (string) $disk->get($relativePath);
        $oldSize = strlen($raw);

        // Skip vector SVG or tiny already-optimized WebP files (< 25 KB)
        if ($ext === 'svg' || $ext === 'gif' || ($ext === 'webp' && $oldSize > 0 && $oldSize <= 25 * 1024)) {
            return ['path' => $relativePath, 'old_size' => $oldSize, 'new_size' => $oldSize, 'saved_bytes' => 0];
        }

        if (! function_exists('imagecreatefromstring')) {
            return ['path' => $relativePath, 'old_size' => $oldSize, 'new_size' => $oldSize, 'saved_bytes' => 0];
        }

        try {
            $src = @imagecreatefromstring($raw);
            if (! $src) {
                return ['path' => $relativePath, 'old_size' => $oldSize, 'new_size' => $oldSize, 'saved_bytes' => 0];
            }

            $width = imagesx($src);
            $height = imagesy($src);

            if ($width <= 0 || $height <= 0) {
                imagedestroy($src);

                return ['path' => $relativePath, 'old_size' => $oldSize, 'new_size' => $oldSize, 'saved_bytes' => 0];
            }

            // Calculate target dimensions (cap longest side at $maxDimension)
            $targetW = $width;
            $targetH = $height;
            if ($width > $maxDimension || $height > $maxDimension) {
                if ($width >= $height) {
                    $targetW = $maxDimension;
                    $targetH = max(1, (int) round($height * ($maxDimension / $width)));
                } else {
                    $targetH = $maxDimension;
                    $targetW = max(1, (int) round($width * ($maxDimension / $height)));
                }
            }

            $canvas = imagecreatetruecolor($targetW, $targetH);
            imagepalettetotruecolor($canvas);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $transparent);
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
            imagedestroy($src);

            $canWebp = function_exists('imagewebp');
            $encoded = $this->encodeWithTargetKb($canvas, $canWebp, $quality, 60 * 1024);
            imagedestroy($canvas);

            if ($encoded === '' || strlen($encoded) === 0) {
                return ['path' => $relativePath, 'old_size' => $oldSize, 'new_size' => $oldSize, 'saved_bytes' => 0];
            }

            $newExt = $canWebp ? 'webp' : 'jpg';
            $newPath = preg_replace('/\.[^.\/]+$/', '.'.$newExt, $relativePath) ?: ($relativePath.'.'.$newExt);

            // If already same format and new size is somehow larger without dimension reduction, keep original
            if ($newPath === $relativePath && strlen($encoded) >= $oldSize) {
                return ['path' => $relativePath, 'old_size' => $oldSize, 'new_size' => $oldSize, 'saved_bytes' => 0];
            }

            $disk->put($newPath, $encoded);
            $newSize = strlen($encoded);

            if ($newPath !== $relativePath) {
                $disk->delete($relativePath);
                $this->syncDatabasePaths($relativePath, $newPath, $newSize);
            }

            return [
                'path' => $newPath,
                'old_size' => $oldSize,
                'new_size' => $newSize,
                'saved_bytes' => max(0, $oldSize - $newSize),
            ];
        } catch (Throwable $e) {
            report($e);

            return ['path' => $relativePath, 'old_size' => $oldSize, 'new_size' => $oldSize, 'saved_bytes' => 0];
        }
    }

    /**
     * Optimizes all images in `storage/app/public/gallery` and linked product/payment logos.
     *
     * @return array{count: int, saved_kb: float}
     */
    public function optimizeAllExisting(): array
    {
        $disk = Storage::disk('public');
        $files = collect($disk->allFiles('gallery'))
            ->merge(Product::query()->whereNotNull('image')->pluck('image'))
            ->merge(PaymentMethod::query()->whereNotNull('logo')->pluck('logo'))
            ->filter(fn ($p) => is_string($p) && $p !== '' && ! str_starts_with($p, 'http'))
            ->unique()
            ->values();

        $count = 0;
        $savedBytes = 0;

        foreach ($files as $file) {
            if (! $disk->exists($file)) {
                continue;
            }
            $res = $this->optimizePublicFile((string) $file);
            if ($res['saved_bytes'] > 0 || $res['path'] !== $file) {
                $count++;
                $savedBytes += $res['saved_bytes'];
            }
        }

        return [
            'count' => $count,
            'saved_kb' => round($savedBytes / 1024, 1),
        ];
    }

    public static function formatKb(?int $bytes): string
    {
        if (! $bytes || $bytes <= 0) {
            return '-';
        }
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return round($bytes / 1024, 1).' KB';
    }

    protected function encodeWithTargetKb($canvas, bool $useWebp, int $initialQuality, int $targetBytes): string
    {
        $qualities = [$initialQuality, 72, 64, 56];
        $best = '';

        foreach ($qualities as $q) {
            ob_start();
            if ($useWebp) {
                @imagewebp($canvas, null, $q);
            } else {
                @imagejpeg($canvas, null, $q);
            }
            $data = (string) ob_get_clean();

            if ($data !== '') {
                $best = $data;
                if (strlen($data) <= $targetBytes) {
                    break;
                }
            }
        }

        return $best;
    }

    protected function syncDatabasePaths(string $oldPath, string $newPath, int $newSize): void
    {
        try {
            if (Schema::hasTable('products')) {
                Product::withTrashed()->where('image', $oldPath)->update(['image' => $newPath]);
            }
            if (Schema::hasTable('payment_methods')) {
                PaymentMethod::query()->where('logo', $oldPath)->update(['logo' => $newPath]);
            }
            if (Schema::hasTable('media_items')) {
                $existingNew = MediaItem::query()->where('path', $newPath)->first();
                $existingOld = MediaItem::query()->where('path', $oldPath)->first();

                if ($existingOld && $existingNew && $existingOld->id !== $existingNew->id) {
                    $existingNew->update(['size' => $newSize]);
                    $existingOld->deleteQuietly();
                } elseif ($existingOld) {
                    $existingOld->update(['path' => $newPath, 'size' => $newSize]);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
