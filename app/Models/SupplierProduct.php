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
    protected static function booted(): void
    {
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
        return $this->belongsToMany(Product::class, 'product_sources')->withPivot('is_active')->withTimestamps();
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
        return $this->is_available
            && $this->supplier?->is_active
            && ($this->unlimited_stock || (int) $this->stock > 0);
    }

    /** Units this source can deliver; null means unlimited. */
    public function availableUnits(): ?int
    {
        if (! $this->isSellable()) {
            return 0;
        }

        return $this->unlimited_stock ? null : (int) $this->stock;
    }

    public function costPkr(): float
    {
        return $this->supplier->toPkr((float) $this->cost);
    }
}
