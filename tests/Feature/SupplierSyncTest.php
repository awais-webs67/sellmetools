<?php

namespace Tests\Feature;

use App\Models\SupplierProduct;
use App\Services\Suppliers\Exceptions\SupplierAuthException;
use App\Services\SupplierSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class SupplierSyncTest extends TestCase
{
    use CreatesStore, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
    }

    public function test_sync_upserts_products_updates_balance_and_reprices_listings(): void
    {
        $supplier = $this->supplier(['base_url' => 'https://bot.test/api']);

        Http::fake([
            'bot.test/api/products' => Http::response(['ok' => true, 'products' => [
                ['id' => 2, 'name' => 'Chatgpt Plus 1M (1HW)', 'price' => 2.8, 'stock' => 21, 'available' => true, 'unlimited_stock' => false, 'warranty' => '1-HOUR{{ce:1|??}}', 'description' => null],
                ['id' => 20, 'name' => 'Linkedin Sales 1M', 'price' => 30, 'stock' => null, 'available' => true, 'unlimited_stock' => false],
                ['id' => 140, 'name' => 'Muse Ai', 'price' => 0.2, 'stock' => 0, 'available' => false, 'unlimited_stock' => false],
            ]]),
            'bot.test/api/balance' => Http::response(['ok' => true, 'balance' => 0.5, 'currency' => 'USDT']),
        ]);

        // Existing listing linked to product 2 with a % markup.
        $sp = $this->supplierProduct($supplier, ['external_id' => '2', 'cost' => 1.0]);
        $product = $this->listing([$sp], ['pricing_mode' => 'markup_percent', 'markup_value' => 50]);
        $this->assertSame(450.0, (float) $product->price);

        // A product that disappeared from the supplier.
        $gone = $this->supplierProduct($supplier, ['external_id' => '999']);

        $stats = app(SupplierSyncService::class)->sync($supplier);

        $this->assertSame(3, $stats['products']);
        $this->assertSame(1, $stats['updated_listings']);

        $chatgpt = SupplierProduct::where('external_id', '2')->first();
        $this->assertSame(21, $chatgpt->stock);
        $this->assertSame('1-HOUR', $chatgpt->warranty);

        $this->assertTrue(SupplierProduct::where('external_id', '20')->first()->unlimited_stock, 'null stock + available = unlimited');
        $this->assertFalse($gone->refresh()->is_available);

        $product->refresh();
        $this->assertSame(840.0, (float) $product->cost_price); // 2.8 * 300
        $this->assertSame(1260.0, (float) $product->price);     // +50%
        $this->assertSame(21, $product->available_stock);

        $this->assertSame('0.5000', (string) $supplier->refresh()->balance);
        $this->assertNotNull($supplier->balance_alerted_at, 'balance below threshold triggers alert');
        $this->assertNotNull($supplier->last_synced_at);
    }

    public function test_sync_failure_is_recorded(): void
    {
        $supplier = $this->supplier(['base_url' => 'https://down.test/api']);
        Http::fake(['down.test/*' => Http::response(['ok' => false, 'error' => 'Invalid API key'], 401)]);

        try {
            app(SupplierSyncService::class)->sync($supplier);
            $this->fail('Expected exception');
        } catch (SupplierAuthException) {
            // expected
        }

        $this->assertSame('Invalid API key', $supplier->refresh()->last_sync_error);
    }
}
