<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use CreatesStore, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
    }

    public function test_public_pages_render(): void
    {
        $product = $this->listing([$this->supplierProduct($this->supplier())]);

        $this->get('/')->assertOk()->assertSee($product->name);
        $this->get(route('products.show', $product))->assertOk()->assertSee('Buy Now');
        $this->get(route('checkout.create', $product))->assertOk();
        $this->get(route('orders.track'))->assertOk();
        $this->get(route('login'))->assertOk();
        $this->get(route('register'))->assertOk();

        foreach (['terms', 'privacy', 'refund', 'contact', 'faq'] as $page) {
            $this->get(route('pages.show', $page))->assertOk();
        }
    }

    public function test_inactive_product_is_hidden(): void
    {
        $product = $this->listing([], ['is_active' => false]);

        $this->get(route('products.show', $product))->assertNotFound();
    }

    public function test_stock_endpoint_returns_live_quantities(): void
    {
        $product = $this->listing([$this->supplierProduct($this->supplier(), ['stock' => 7])]);

        $this->getJson(route('stock', ['ids' => $product->id]))
            ->assertOk()
            ->assertJsonPath("items.{$product->id}.qty", 7)
            ->assertJsonPath("items.{$product->id}.in_stock", true);
    }

    public function test_checkout_creates_order_and_order_page_requires_token(): void
    {
        $product = $this->listing([$this->supplierProduct($this->supplier())]);
        $method = $this->paymentMethod();

        $response = $this->post(route('checkout.store', $product), [
            'quantity' => 2,
            'email' => 'buyer@example.com',
            'payment_method_id' => $method->id,
            'agree' => '1',
        ]);

        $order = Order::firstOrFail();
        $response->assertRedirect($order->customerUrl());
        $this->assertSame(1100.0, (float) $order->total);

        $this->get($order->customerUrl())->assertOk()->assertSee('Complete your payment')->assertSee('03001234567');
        $this->get(route('orders.show', ['order' => $order->number, 'token' => 'wrong']))->assertOk(); // allowed in same guest session

        $this->flushSession();
        $this->get(route('orders.show', $order))->assertNotFound();
        $this->get(route('orders.show', ['order' => $order->number, 'token' => 'wrong']))->assertNotFound();

        $this->post(route('orders.payment', $order), [
            'token' => $order->access_token,
            'transaction_id' => 'ABC123456',
            'sender_number' => '03009998888',
        ])->assertRedirect($order->customerUrl());

        $this->get($order->customerUrl())->assertSee('Verifying your payment');
    }

    public function test_track_lookup_requires_matching_email(): void
    {
        $product = $this->listing([$this->supplierProduct($this->supplier())]);
        $order = app(OrderService::class)->create($product, 1, $this->customer(), $this->paymentMethod());

        $this->post(route('orders.lookup'), ['number' => $order->number, 'email' => 'other@example.com'])
            ->assertSessionHas('error');

        $this->post(route('orders.lookup'), ['number' => strtolower($order->number), 'email' => 'buyer@example.com'])
            ->assertRedirect($order->customerUrl());
    }
}
