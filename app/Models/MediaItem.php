<?php

namespace App\Models;

use App\Services\ImageOptimizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaItem extends Model
{
    protected $fillable = ['name', 'path', 'size'];

    protected static function booted(): void
    {
        static::saving(function (MediaItem $item) {
            $oldPath = $item->exists ? $item->getOriginal('path') : null;

            if ($item->path && ! Str::startsWith($item->path, ['http://', 'https://'])) {
                $res = app(ImageOptimizer::class)->optimizePublicFile($item->path);
                $item->path = $res['path'];
                if ($res['new_size'] > 0) {
                    $item->size = $res['new_size'];
                }
            }

            if ($item->exists && $oldPath && $item->path) {
                if ($oldPath !== $item->path) {
                    Product::withTrashed()->where('image', $oldPath)->update([
                        'image' => $item->path,
                        'updated_at' => now(),
                    ]);
                    PaymentMethod::query()->where('logo', $oldPath)->update([
                        'logo' => $item->path,
                        'updated_at' => now(),
                    ]);

                    if (! Str::startsWith($oldPath, ['http://', 'https://'])) {
                        Storage::disk('public')->delete($oldPath);
                    }
                } else {
                    Product::withTrashed()->where('image', $item->path)->update([
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        static::saved(function () {
            Cache::forget('smt_search_catalog_v1');
        });

        static::deleting(function (MediaItem $item) {
            if ($item->path) {
                Storage::disk('public')->delete($item->path);
            }
            Cache::forget('smt_search_catalog_v1');
        });
    }

    public function url(): ?string
    {
        if (! $this->path) {
            return null;
        }

        return Str::startsWith($this->path, ['http://', 'https://'])
            ? $this->path
            : url('/media/'.ltrim($this->path, '/'));
    }

    /** Html label with thumbnail for searchable gallery dropdowns. */
    public function optionHtml(): string
    {
        $url = e($this->url());
        $name = e($this->name);

        return '<div class="flex items-center gap-3 py-0.5">'
            .'<img src="'.$url.'" alt="" class="h-9 w-9 shrink-0 rounded object-cover border border-gray-200 bg-gray-50" />'
            .'<span class="font-medium text-sm truncate">'.$name.'</span>'
            .'</div>';
    }
}
