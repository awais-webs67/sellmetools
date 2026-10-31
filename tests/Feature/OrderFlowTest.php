<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockStatus;
use App\Enums\SupplierOrderStatus;
use App\Exceptions\StoreException;
use App\Models\SupplierOrder;
use App\Services\FulfillmentService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use CreatesStore, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
    }

    public function test_last_unit_can_only_be_reserved_once(): void
    {
        $sp = $this->supplierProduct($this->supplier(), ['stock' => 1]);
        $product = $this->listing([$sp]);
        $method = $this->paymentMethod();
        $orders = app(OrderService::class);

        $orders->create($product, 1, $this->customer(), $method);
        $this->assertSame(0, $product->refresh()->available_stock, 'others see zero immediately');

        $this->expectException(StoreException::class);
        $orders->create($product, 1, $this->customer(), $method);
    }

    public function test_own_stock_is_reserved_first_and_released_on_expiry(): void
    {
        $product = $this->listing();
        $product->stockItems()->createMany([['content' => 'a@x.com:pass1'], ['content' => 'b@x.com:pass2']]);
        app(InventoryService::class)->refresh($product);
        $this->assertSame(2, $product->refresh()->available_stock);

        $order = app(OrderService::class)->create($product, 2, $this->customer(), $this->paymentMethod());

        $this->assertSame(2, $product->stockItems()->where('status', StockStatus::Reserved)->count());
        $this->assertSame(0, $order->items->first()->reserved_supplier_qty);
        $this->assertSame(0, $product->refresh()->available_stock);

        $this->travel(16)->minutes();
        $this->assertSame(1, app(OrderService::class)->expireStale());

        $this->assertSame(OrderStatus::Expired, $order->refresh()->status);
        $this->assertSame(2, $product->refresh()->available_stock);
    }

    public function test_full_flow_payment_approval_delivers_from_supplier(): void
    {
        $supplier = $this->supplier(['base_url' => 'https://bot.test/api']);
        $sp = $this->supplierProduct($supplier, ['external_id' => '72', 'cost' => 1.0, 'stock' => 5]);
        $product = $this->listing([$sp], ['fixed_price' => 550]);
        $method = $this->paymentMethod();

        Http::fake(['bot.test/api/order' => Http::response(['ok' => true, 'order' => [
            'order_id' => 'ORD123', 'product_id' => 72, 'quantity' => 1, 'unit_price' => 1.0,
            'total' => 1.0, 'balance_after' => 9.0, 'items' => ['user@outlook.com:secret'],
        ]])]);

        $order = app(OrderService::class)->create($product, 1, $this->customer(), $method);
        $payment = app(PaymentService::class)->submit($order, 'tid123456', '03001112222');

        $this->assertSame(OrderStatus::PaymentReview, $order->refresh()->status);
        $this->assertSame('TID123456', $payment->transaction_id);

        $order = app(PaymentService::class)->approve($payment);

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame('user@outlook.com:secret', $order->deliveries()->first()->content);
        $this->assertSame(300.0, (float) $order->total_cost);
        $this->assertSame(250.0, $order->profit());
        $this->assertSame(1, $product->refresh()->sold_count);
        $this->assertSame(0, $order->items()->first()->reserved_supplier_qty);

        Http::assertSent(fn (Request $r) => $r['product_id'] === 72
            && $r['quantity'] === 1
            && str_starts_with($r['request_id'], $order->number)
            && $r->header('x-api-key')[0] === $supplier->api_key);
    }

    public function test_out_of_stock_supplier_fails_over_to_next_cheapest(): void
    {
        $cheap = $this->supplierProduct($this->supplier(['base_url' => 'https://cheap.test/api']), ['cost' => 1.0]);
        $backup = $this->supplierProduct($this->supplier(['base_url' => 'https://backup.test/api']), ['cost' => 1.2]);
        $product = $this->listing([$cheap, $backup]);

        Http::fake([
            'cheap.test/*' => Http::response(['ok' => false, 'error' => 'Out of stock'], 409),
            'backup.test/*' => Http::response(['ok' => true, 'order' => ['order_id' => 'B1', 'total' => 1.2, 'items' => ['backup-item']]]),
        ]);

        $order = app(OrderService::class)->create($product, 1, $this->customer(), $this->paymentMethod());
        $order = app(PaymentService::class)->approve(app(PaymentService::class)->submit($order, 'TID1', '0300'));

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame('backup-item', $order->deliveries()->first()->content);
        $this->assertFalse($cheap->refresh()->is_available);
        $this->assertSame(360.0, (float) $order->total_cost);
    }

    public function test_unknown_supplier_result_never_fails_over(): void
    {
        $first = $this->supplierProduct($this->supplier(['base_url' => 'https://flaky.test/api']), ['cost' => 1.0]);
        $second = $this->supplierProduct($this->supplier(['base_url' => 'https://other.test/api']), ['cost' => 1.1]);
        $product = $this->listing([$first, $second]);

        Http::fake([
            'flaky.test/*' => Http::response('Bad gateway', 502),
            'other.test/*' => Http::response(['ok' => true, 'order' => ['items' => ['x']]]),
        ]);

        $order = app(OrderService::class)->create($product, 1, $this->customer(), $this->paymentMethod());
        $order = app(PaymentService::class)->approve(app(PaymentService::class)->submit($order, 'TID2', '0300'));

        $this->assertSame(OrderStatus::NeedsAttention, $order->status);
        $this->assertSame(SupplierOrderStatus::Unknown, SupplierOrder::first()->status);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'other.test'));
    }

    public function test_source_that_would_cause_a_loss_is_skipped(): void
    {
        $sp = $this->supplierProduct($this->supplier(), ['cost' => 1.0]);
        $product = $this->listing([$sp], ['fixed_price' => 550]);
        $order = app(OrderService::class)->create($product, 1, $this->customer(), $this->paymentMethod());

        $sp->update(['cost' => 3.0]); // cost now Rs 900 > sold at Rs 550
        Http::fake();

        $order = app(PaymentService::class)->approve(app(PaymentService::class)->submit($order, 'TID3', '0300'));

        $this->assertSame(OrderStatus::NeedsAttention, $order->status);
        Http::assertNothingSent();
    }

    public function test_transaction_id_cannot_be_reused(): void
    {
        $product = $this->listing([$this->supplierProduct($this->supplier())]);
        $method = $this->paymentMethod();
        $orders = app(OrderService::class);
        $payments = app(PaymentService::class);

        $payments->submit($orders->create($product, 1, $this->customer(), $method), 'SAME-TID', '0300');

        $this->expectExceptionMessage('already been used');
        $payments->submit($orders->create($product, 1, $this->customer(), $method), 'same-tid', '0300');
    }

    public function test_rejected_payment_reopens_order_for_new_tid(): void
    {
        $product = $this->listing([$this->supplierProduct($this->supplier())]);
        $order = app(OrderService::class)->create($product, 1, $this->customer(), $this->paymentMethod());
        $payment = app(PaymentService::class)->submit($order, 'WRONG1', '0300');

        app(PaymentService::class)->reject($payment, 'TID not found');

        $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertTrue($order->canSubmitPayment());
    }

    public function test_manual_delivery_completes_order(): void
    {
        $sp = $this->supplierProduct($this->supplier(['base_url' => 'https://x.test/api']));
        $product = $this->listing([$sp]);
        Http::fake(['x.test/*' => Http::response(['ok' => false, 'error' => 'Insufficient balance'], 402)]);

        $order = app(OrderService::class)->create($product, 1, $this->customer(), $this->paymentMethod());
        $order = app(PaymentService::class)->approve(app(PaymentService::class)->submit($order, 'TID4', '0300'));
        $this->assertSame(OrderStatus::NeedsAttention, $order->status);

        $order = app(FulfillmentService::class)->deliverManually($order->items()->first(), ['manual@x.com:pw']);

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame('manual', $order->deliveries()->first()->source);
    }
}
