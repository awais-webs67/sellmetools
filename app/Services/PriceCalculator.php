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

        return $source ? round($source->costPkr(), 2) : null;
    }

    public function sellingPrice(Product $product, ?float $cost): float
    {
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
        $cost = $this->cheapestCost($product);
        $price = $this->sellingPrice($product, $cost);

        $product->cost_price = $cost;
        $product->price = $price;

        $result = ['changed' => $product->isDirty(['price', 'cost_price']), 'disabled' => false, 'enabled' => false];

        $hasOwnStock = $product->ownAvailableCount() > 0;
        $losing = $cost !== null && $price < $cost && ! $hasOwnStock;

        if ($losing && $product->is_active) {
            $product->is_active = false;
            $product->disabled_reason = self::REASON_BELOW_COST;
            $result['disabled'] = true;
        } elseif (! $losing && ! $product->is_active && $product->disabled_reason === self::REASON_BELOW_COST) {
            $product->is_active = true;
            $product->disabled_reason = null;
            $result['enabled'] = true;
        }

        $product->saveQuietly();

        return $result;
    }
}
