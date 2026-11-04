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

    public function winningSource(Product $product): ?SupplierProduct
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

        return $source;
    }

    public function cheapestCost(Product $product): ?float
    {
        $source = $this->winningSource($product);

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

    /**
     * Computes the selling price when fulfilled by a specific supplier source.
     * Uses the supplier pivot's custom `pricing_mode` + `markup_value` if set;
     * otherwise falls back to the Product's overall markup settings.
     */
    public function sellingPriceForSource(Product $product, ?SupplierProduct $source, ?float $cost = null): float
    {
        if ($product->is_free) {
            return 0.0;
        }

        $resolvedCost = $cost ?? ($source ? round($source->costPkr(), 2) : null);

        $pivotModeRaw = trim((string) ($source?->pivot?->pricing_mode ?? ''));
        $pivotValRaw = $source?->pivot?->markup_value ?? null;

        if ($pivotModeRaw !== '' && $pivotValRaw !== null && $pivotValRaw !== '') {
            $mode = PricingMode::tryFrom($pivotModeRaw) ?? ($product->pricing_mode ?? PricingMode::Fixed);
            $markup = (float) $pivotValRaw;

            $price = match (true) {
                $mode === PricingMode::Fixed => $markup,
                $resolvedCost === null => (float) $product->fixed_price,
                $mode === PricingMode::MarkupAmount => $resolvedCost + $markup,
                $mode === PricingMode::MarkupPercent => $resolvedCost * (1 + $markup / 100),
            };

            return $this->round($price, $mode === PricingMode::Fixed || $resolvedCost === null);
        }

        return $this->sellingPrice($product, $resolvedCost);
    }

    public function sourceMarginLabel(Product $product, SupplierProduct $source): string
    {
        $pivotModeRaw = trim((string) ($source->pivot?->pricing_mode ?? ''));
        $pivotValRaw = $source->pivot?->markup_value ?? null;

        if ($pivotModeRaw !== '' && $pivotValRaw !== null && $pivotValRaw !== '') {
            $val = rtrim(rtrim(number_format((float) $pivotValRaw, 2, '.', ''), '0'), '.');

            return match ($pivotModeRaw) {
                PricingMode::MarkupPercent->value => "Custom +{$val}%",
                PricingMode::MarkupAmount->value => "Custom +Rs {$val}",
                PricingMode::Fixed->value => "Custom Fixed Rs {$val}",
                default => "Custom {$val}",
            };
        }

        $mode = $product->pricing_mode ?? PricingMode::Fixed;
        $val = rtrim(rtrim(number_format((float) $product->markup_value, 2, '.', ''), '0'), '.');

        return match ($mode) {
            PricingMode::MarkupPercent => "Overall (+{$val}%)",
            PricingMode::MarkupAmount => "Overall (+Rs {$val})",
            PricingMode::Fixed => 'Overall Fixed (Rs '.number_format((float) $product->fixed_price, 0).')',
        };
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
        $winningSource = $this->winningSource($product);
        $supplierCost = $winningSource ? round($winningSource->costPkr(), 2) : null;
        $isManualFixed = ($product->pricing_mode ?? PricingMode::Fixed) === PricingMode::Fixed && ! $product->sources()->exists();
        $cost = $supplierCost ?? ($isManualFixed && $product->cost_price !== null ? round((float) $product->cost_price, 2) : null);
        $price = $this->sellingPriceForSource($product, $winningSource, $cost);

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
