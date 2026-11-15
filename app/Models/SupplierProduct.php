<?php

namespace App\Models;

use App\Services\InventoryService;
use App\Services\PriceCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class SupplierProduct extends Model
{
    public static bool $suppressListingRefresh = false;

    public static function withoutListingRefresh(callable $callback): mixed
    {
        $prev = static::$suppressListingRefresh;
        static::$suppressListingRefresh = true;

        try {
            return $callback();
        } finally {
            static::$suppressListingRefresh = $prev;
        }
    }

    protected static function booted(): void
    {
        static::updated(function (SupplierProduct $sp): void {
            if (static::$suppressListingRefresh) {
                return;
            }

            if ($sp->wasChanged(['stock', 'unlimited_stock', 'is_available'])) {
                $sp->refreshLinkedListings();
            }
        });

        static::deleting(function (SupplierProduct $sp): void {
            $affectedProductIds = DB::table('product_sources')
                ->where('supplier_product_id', $sp->id)
                ->pluck('product_id')
                ->unique()
                ->all();

            DB::table('product_sources')->where('supplier_product_id', $sp->id)->delete();

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
    }

    public function refreshLinkedListings(): void
    {
        $affectedProductIds = DB::table('product_sources')
            ->where('supplier_product_id', $this->id)
            ->pluck('product_id')
            ->unique()
            ->all();

        if ($affectedProductIds === []) {
            return;
        }

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

    protected $fillable = [
        'supplier_id', 'external_id', 'name', 'cost', 'stock', 'unlimited_stock', 'is_available',
        'warranty', 'description', 'raw', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:4',
            'stock' => 'integer',
            'unlimited_stock' => 'boolean',
            'is_available' => 'boolean',
            'raw' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_sources')
            ->withPivot(['is_active', 'pricing_mode', 'markup_value'])
            ->withTimestamps();
    }

    public function products(): BelongsToMany
    {
        return $this->listings();
    }

    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_available', true)
            ->whereHas('supplier', fn (Builder $q) => $q->where('is_active', true))
            ->where(fn (Builder $q) => $q->where('unlimited_stock', true)->orWhere('stock', '>', 0));
    }

    public function isSellable(): bool
    {
        $pivotActive = $this->pivot === null
            || ! array_key_exists('is_active', $this->pivot->getAttributes())
            || (bool) $this->pivot->is_active;

        return $pivotActive
            && (bool) $this->is_available
            && (bool) $this->supplier?->is_active
            && ($this->unlimited_stock || (int) $this->stock > 0);
    }

    public static function simulatedUnlimitedStock(): int
    {
        return max(0, (int) Setting::get('supplier_unlimited_stock_qty', '100'));
    }

    /** Units this source can deliver; simulated when unlimited_stock is set (unless simulation = 0). */
    public function availableUnits(): ?int
    {
        if (! $this->isSellable()) {
            return 0;
        }

        if ($this->unlimited_stock) {
            $simulated = static::simulatedUnlimitedStock();

            return $simulated > 0 ? $simulated : null;
        }

        return (int) $this->stock;
    }

    public function displayStockLabel(): string
    {
        if (! $this->is_available) {
            return '0';
        }

        if ($this->unlimited_stock) {
            $simulated = static::simulatedUnlimitedStock();

            return $simulated > 0 ? "∞ ({$simulated})" : '∞';
        }

        return (string) (int) $this->stock;
    }

    public function costPkr(): float
    {
        return $this->supplier
            ? $this->supplier->toPkr((float) $this->cost)
            : (float) $this->cost * Setting::usdtRate();
    }
}
