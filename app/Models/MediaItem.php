<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaItem extends Model
{
    protected $fillable = ['name', 'path', 'size'];

    protected static function booted(): void
    {
        static::deleting(function (MediaItem $item) {
            if ($item->path) {
                Storage::disk('public')->delete($item->path);
            }
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
