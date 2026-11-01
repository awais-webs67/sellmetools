<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'icon', 'meta_title', 'meta_description', 'sort', 'is_active'];

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

        return "Buy {$this->name} Subscriptions & Tools in Pakistan";
    }

    public function seoDescription(): string
    {
        if (filled($this->meta_description)) {
            return (string) $this->meta_description;
        }

        return "Buy genuine {$this->name} accounts, tools, and subscriptions in Pakistan at wholesale rates. Instant delivery via Easypaisa, JazzCash, NayaPay, SadaPay & Binance.";
    }
}
