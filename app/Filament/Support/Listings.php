<?php

namespace App\Filament\Support;

use App\Enums\PricingMode;
use App\Models\Product;
use App\Models\SupplierProduct;
use App\Services\InventoryService;
use App\Services\PriceCalculator;
use Illuminate\Support\Facades\DB;

/** Creates / links storefront listings from supplier products and keeps them priced. */
final class Listings
{
    /** Re-computes price + stock snapshot of a product. */
    public static function refresh(Product $product): void
    {
        app(PriceCalculator::class)->refresh($product);
        app(InventoryService::class)->refresh($product);
    }

    /** @param iterable<Product> $products */
    public static function refreshMany(iterable $products): void
    {
        foreach ($products as $product) {
            self::refresh($product);
        }
    }

    /** Products that resell supplier stock (their price depends on supplier cost / USDT rate). */
    public static function refreshAllSourced(): int
    {
        $count = 0;

        Product::query()->whereHas('sources')->chunkById(100, function ($products) use (&$count) {
            foreach ($products as $product) {
                self::refresh($product);
                $count++;
            }
        });

        return $count;
    }

    /**
     * @param  array{name?: string, category_id?: int|null, pricing_mode?: string|PricingMode, fixed_price?: float|string|null,
     *     markup_value?: float|string|null, short_description?: string|null, description?: string|null,
     *     warranty?: string|null, is_active?: bool}  $data
     */
    public static function createFromSupplierProduct(SupplierProduct $source, array $data): Product
    {
        $product = DB::transaction(function () use ($source, $data): Product {
            $product = Product::create([
                'name' => $data['name'] ?? $source->name,
                'category_id' => $data['category_id'] ?? null,
                'image' => $data['image'] ?? null,
                'pricing_mode' => $data['pricing_mode'] ?? PricingMode::MarkupPercent,
                'fixed_price' => $data['fixed_price'] ?? null,
                'markup_value' => $data['markup_value'] ?? 0,
                'short_description' => $data['short_description'] ?? null,
                'description' => $data['description'] ?? $source->description,
                'warranty' => $data['warranty'] ?? $source->warranty,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $product->sources()->attach($source->id, ['is_active' => true]);

            return $product;
        });

        self::refresh($product);

        return $product->refresh();
    }

    public static function link(SupplierProduct $source, Product $product): void
    {
        $product->sources()->syncWithoutDetaching([$source->id => ['is_active' => true]]);

        self::refresh($product);
    }

    /** Price preview for a supplier cost (PKR) under the given pricing rule, mirrors PriceCalculator. */
    public static function previewPrice(?float $costPkr, string|PricingMode|null $mode, mixed $fixedPrice, mixed $markup): float
    {
        $product = new Product([
            'pricing_mode' => $mode instanceof PricingMode ? $mode : (PricingMode::tryFrom((string) $mode) ?? PricingMode::Fixed),
            'fixed_price' => is_numeric($fixedPrice) ? (float) $fixedPrice : null,
            'markup_value' => is_numeric($markup) ? (float) $markup : 0,
        ]);

        return app(PriceCalculator::class)->sellingPrice($product, $costPkr);
    }
}
