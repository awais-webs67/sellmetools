<?php

namespace App\Services;

use App\Enums\PricingMode;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SupplierProduct;
use Illuminate\Support\Collection;

class PriceCalculator
{
    public const REASON_BELOW_COST = 'Supplier cost is higher than selling price';

    /** Active, sellable sources of a product, cheapest first. */
    public function rankedSources(Product $product): Collection
    {
        $product->loadMissing('activeSources.supplier');

        return $product->activeSources
            ->filter(fn (SupplierProduct $sp) => $sp->isSellable())
            ->sortBy([
                fn ($a, $b) => $a->costPkr() <=> $b->costPkr(),
                fn ($a, $b) => $a->supplier->priority <=> $b->supplier->priority,
            ])
            ->values();
    }

    public function cheapestCost(Product $product): ?float
    {
        $source = $this->rankedSources($product)->first();

        // If all linked suppliers are currently out of stock (0 stock), still compute the price
        // from the cheapest enabled supplier source so out-of-stock products keep their real price instead of Rs 0.
        if (! $source) {
            $product->loadMissing('activeSources.supplier');
            $source = $product->activeSources
                ->filter(fn (SupplierProduct $sp) => (bool) $sp->supplier?->is_active)
                ->sortBy([
                    fn ($a, $b) => $a->costPkr() <=> $b->costPkr(),
                    fn ($a, $b) => ($a->supplier->priority ?? 999) <=> ($b->supplier->priority ?? 999),
                ])
                ->first();
        }

        return $source ? round($source->costPkr(), 2) : null;
    }

    public function sellingPrice(Product $product, ?float $cost): float
    {
        if ($product->is_free) {
            return 0.0;
        }

        $mode = $product->pricing_mode ?? PricingMode::Fixed;
        $markup = (float) $product->markup_value;

        $price = match (true) {
            $mode === PricingMode::Fixed || $cost === null => (float) $product->fixed_price,
            $mode === PricingMode::MarkupAmount => $cost + $markup,
            $mode === PricingMode::MarkupPercent => $cost * (1 + $markup / 100),
        };

        return $this->round($price, $mode === PricingMode::Fixed || $cost === null);
    }

    protected function round(float $price, bool $exact): float
    {
        $step = (float) Setting::get('price_rounding', 10);

        if ($exact || $step <= 0) {
            return round($price, 2);
        }

        return ceil($price / $step) * $step;
    }

    /**
     * Recomputes cost + selling price. Auto-disables products that would sell at a loss
     * and re-enables them once the supplier price recovers.
     *
     * @return array{changed: bool, disabled: bool, enabled: bool}
     */
    public function refresh(Product $product): array
    {
        $supplierCost = $this->cheapestCost($product);
        $isManualFixed = ($product->pricing_mode ?? PricingMode::Fixed) === PricingMode::Fixed && ! $product->sources()->exists();
        $cost = $supplierCost ?? ($isManualFixed && $product->cost_price !== null ? round((float) $product->cost_price, 2) : null);
        $price = $this->sellingPrice($product, $cost);

        $product->cost_price = $cost;
        $product->price = $price;

        $result = ['changed' => $product->isDirty(['price', 'cost_price']), 'disabled' => false, 'enabled' => false];

        $hasOwnStock = $product->ownAvailableCount() > 0;
        $losing = $supplierCost !== null && $price < $supplierCost && ! $hasOwnStock;

        if ($losing && $product->is_active) {
            $product->is_active = false;
            $product->disabled_reason = self::REASON_BELOW_COST;
            $result['disabled'] = true;
        } elseif (! $losing && ! $product->is_active && $product->disabled_reason === self::REASON_BELOW_COST) {
            $product->is_active = true;
            $product->disabled_reason = null;
            $result['enabled'] = true;
        }

        if ($product->isDirty(['price', 'cost_price', 'is_active', 'disabled_reason'])) {
            $product->saveQuietly();
        }

        return $result;
    }
}
