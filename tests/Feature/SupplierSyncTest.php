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

    public function test_prodseller_finite_stock_fields_and_unavailable_flags_do_not_produce_false_infinity(): void
    {
        $supplier = $this->supplier([
            'name' => 'ProdSeller',
            'base_url' => 'https://prodseller.com/v1',
            'api_key' => 'psk_test_123',
        ]);

        Http::fake([
            'prodseller.com/v1/products' => Http::response([
                'products' => [
                    ['productId' => 'ps-1', 'name' => 'CapCut Pro', 'finalPrice' => 1.5, 'stockQuantity' => 14, 'inStock' => true],
                    ['productId' => 'ps-2', 'name' => 'Canva Pro', 'finalPrice' => 0.8, 'stock_quantity' => 0, 'inStock' => false],
                    ['productId' => 'ps-3', 'name' => 'Cursor Pro', 'finalPrice' => 4.0, 'inventory' => ['available' => 6], 'inStock' => true],
                    ['productId' => 'ps-4', 'name' => 'Stale Flag Item', 'finalPrice' => 2.0, 'unlimited_stock' => true, 'inStock' => false],
                    ['productId' => 'ps-5', 'name' => 'Missing Flags Item', 'finalPrice' => 3.0],
                ],
            ]),
            'prodseller.com/v1/balance' => Http::response(['balanceUsd' => 25.0, 'currency' => 'USDT']),
        ]);

        app(SupplierSyncService::class)->sync($supplier);

        $ps1 = SupplierProduct::where('external_id', 'ps-1')->firstOrFail();
        $this->assertSame(14, $ps1->stock);
        $this->assertFalse($ps1->unlimited_stock);
        $this->assertTrue($ps1->is_available);

        $ps2 = SupplierProduct::where('external_id', 'ps-2')->firstOrFail();
        $this->assertSame(0, $ps2->stock);
        $this->assertFalse($ps2->unlimited_stock);
        $this->assertFalse($ps2->is_available);

        $ps3 = SupplierProduct::where('external_id', 'ps-3')->firstOrFail();
        $this->assertSame(6, $ps3->stock);
        $this->assertFalse($ps3->unlimited_stock);
        $this->assertTrue($ps3->is_available);

        $ps4 = SupplierProduct::where('external_id', 'ps-4')->firstOrFail();
        $this->assertFalse($ps4->unlimited_stock);
        $this->assertFalse($ps4->is_available);
        $this->assertFalse($ps4->isSellable());

        $ps5 = SupplierProduct::where('external_id', 'ps-5')->firstOrFail();
        $this->assertSame(0, $ps5->stock);
        $this->assertFalse($ps5->unlimited_stock);
        $this->assertFalse($ps5->is_available);
    }
}
