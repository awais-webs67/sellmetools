<?php

namespace Tests\Concerns;

use App\Enums\PricingMode;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\InventoryService;
use App\Services\PriceCalculator;

trait CreatesStore
{
    protected function setUpStore(): void
    {
        Setting::setMany(['usdt_rate' => '300', 'price_rounding' => '10', 'order_expiry_minutes' => '15']);
    }

    protected function supplier(array $attrs = []): Supplier
    {
        static $n = 0;
        $n++;

        return Supplier::create($attrs + [
            'name' => "Supplier {$n}",
            'driver' => 'standard_bot',
            'base_url' => "https://supplier{$n}.test/api",
            'api_key' => 'key-'.$n,
            'currency' => 'USDT',
            'is_active' => true,
            'priority' => 100,
            'low_balance_threshold' => 1,
        ]);
    }

    protected function supplierProduct(Supplier $supplier, array $attrs = []): SupplierProduct
    {
        static $n = 0;
        $n++;

        return $supplier->products()->create($attrs + [
            'external_id' => (string) (100 + $n),
            'name' => 'Supplier item '.$n,
            'cost' => 1.00,
            'stock' => 10,
            'unlimited_stock' => false,
            'is_available' => true,
        ]);
    }

    /** Creates an active product linked to the given sources and refreshes price + stock. */
    protected function listing(array $sources = [], array $attrs = []): Product
    {
        $product = Product::create($attrs + [
            'name' => 'ChatGPT Plus 1 Month',
            'pricing_mode' => PricingMode::Fixed,
            'fixed_price' => 550,
            'is_active' => true,
            'max_per_order' => 5,
        ]);

        foreach ($sources as $source) {
            $product->sources()->attach($source->id, ['is_active' => true]);
        }

        app(PriceCalculator::class)->refresh($product);
        app(InventoryService::class)->refresh($product);

        return $product->refresh();
    }

    protected function paymentMethod(array $attrs = []): PaymentMethod
    {
        return PaymentMethod::create($attrs + [
            'name' => 'Easypaisa',
            'code' => 'easypaisa-'.uniqid(),
            'type' => 'manual',
            'currency' => 'PKR',
            'account_title' => 'Test Account',
            'account_number' => '03001234567',
            'requires_sender_number' => true,
            'is_active' => true,
        ]);
    }

    protected function customer(): array
    {
        return ['email' => 'buyer@example.com', 'name' => 'Buyer', 'phone' => '03000000000'];
    }
}
