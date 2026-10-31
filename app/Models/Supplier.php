<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name', 'driver', 'base_url', 'api_key', 'currency', 'is_active', 'priority',
        'balance', 'low_balance_threshold', 'balance_alerted_at', 'last_synced_at', 'last_sync_error', 'meta',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'balance' => 'decimal:4',
            'low_balance_threshold' => 'decimal:4',
            'balance_alerted_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SupplierOrder::class);
    }

    /** Converts an amount in this supplier's currency to PKR. */
    public function toPkr(float $amount): float
    {
        return strtoupper($this->currency) === 'PKR' ? $amount : $amount * Setting::usdtRate();
    }
}
