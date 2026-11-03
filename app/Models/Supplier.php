<?php

namespace App\Models;

use App\Services\InventoryService;
use App\Services\PriceCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Supplier extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (Supplier $supplier): void {
            $spIds = $supplier->products()->pluck('id')->all();
            if ($spIds === []) {
                return;
            }

            $affectedProductIds = DB::table('product_sources')
                ->whereIn('supplier_product_id', $spIds)
                ->pluck('product_id')
                ->unique()
                ->all();

            // Detach product_sources and delete supplier_products before refreshing affected store listings
            DB::table('product_sources')->whereIn('supplier_product_id', $spIds)->delete();
            SupplierProduct::whereIn('id', $spIds)->delete();

            if ($affectedProductIds !== []) {
                $pricing = app(PriceCalculator::class);
                $inventory = app(InventoryService::class);

                Product::query()
                    ->whereIn('id', $affectedProductIds)
                    ->with(['activeSources.supplier'])
                    ->each(function (Product $product) use ($pricing, $inventory): void {
                        $pricing->refresh($product);
                        $inventory->refresh($product);
                    });
            }
        });

        static::updated(function (Supplier $supplier): void {
            if ($supplier->wasChanged(['is_active', 'currency', 'priority'])) {
                $spIds = $supplier->products()->pluck('id')->all();
                if ($spIds === []) {
                    return;
                }

                $affectedProductIds = DB::table('product_sources')
                    ->whereIn('supplier_product_id', $spIds)
                    ->pluck('product_id')
                    ->unique()
                    ->all();

                if ($affectedProductIds !== []) {
                    $pricing = app(PriceCalculator::class);
                    $inventory = app(InventoryService::class);

                    Product::query()
                        ->whereIn('id', $affectedProductIds)
                        ->with(['activeSources.supplier'])
                        ->each(function (Product $product) use ($pricing, $inventory): void {
                            $pricing->refresh($product);
                            $inventory->refresh($product);
                        });
                }
            }
        });
    }

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
