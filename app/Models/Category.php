<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'icon', 'meta_title', 'meta_description', 'sort', 'is_active'];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('smt_search_catalog_v1'));
        static::deleted(fn () => Cache::forget('smt_search_catalog_v1'));
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function seoTitle(): string
    {
        if (filled($this->meta_title)) {
            return (string) $this->meta_title;
        }

        return "{$this->name} Subscriptions — Buy Online | Instant Delivery";
    }

    public function seoDescription(): string
    {
        if (filled($this->meta_description)) {
            return (string) $this->meta_description;
        }

        return "Compare {$this->name} plans and buy genuine {$this->name} subscriptions worldwide with USDT / Binance Pay or local PKR wallets (Easypaisa, JazzCash), delivered instantly.";
    }
}
