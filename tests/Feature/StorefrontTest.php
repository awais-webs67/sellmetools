<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ResellerProductPrice;
use App\Models\User;
use App\Services\InventoryService;
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
        $this->get(route('checkout.create', $product))->assertOk()->assertSee('Cancel Checkout');
        $this->get(route('orders.track'))->assertOk();
        $this->get(route('login'))->assertOk();
        $this->get(route('register'))->assertOk();

        foreach (['terms', 'privacy', 'refund', 'contact', 'faq'] as $page) {
            $this->get(route('pages.show', $page))->assertOk();
        }

        $this->get(route('sitemap'))->assertOk()->assertSee(route('products.show', $product), false);
        $this->get(route('feed.google-shopping'))->assertOk()->assertSee($product->name);
        $this->get(route('llms'))->assertOk()->assertSee($product->name);
        $this->get(route('robots'))->assertOk()->assertSee('Sitemap:');
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
            'phone' => '0300-1234567',
            'payment_method_id' => $method->id,
            'agree' => '1',
        ]);

        $order = Order::firstOrFail();
        $response->assertRedirect($order->customerUrl());
        $this->assertSame(1100.0, (float) $order->total);
        $this->assertSame('923001234567', $order->phone);

        // WhatsApp button points directly to normalized Admin phone (wa.me/923001234567)
        $this->get($order->customerUrl())
            ->assertOk()
            ->assertSee('Complete your payment')
            ->assertSee('https://wa.me/923001234567?text=', false);

        $this->get(route('orders.show', ['order' => $order->number, 'token' => 'wrong']))->assertOk(); // allowed in same guest session

        $this->flushSession();
        $this->get(route('orders.show', $order))->assertNotFound();
        $this->get(route('orders.show', ['order' => $order->number, 'token' => 'wrong']))->assertNotFound();

        $this->post(route('orders.payment', $order), [
            'token' => $order->access_token,
            'transaction_id' => 'ABC1234567',
            'sender_number' => '03009998888',
        ])->assertRedirect($order->customerUrl());

        $this->get($order->customerUrl())->assertSee('Verifying your payment');

        // Duplicate TID (even with dashes or TID: prefix on a second order) must be blocked
        $order2 = app(OrderService::class)->create($product, 1, $this->customer(), $method);
        $this->post(route('orders.payment', $order2), [
            'token' => $order2->access_token,
            'transaction_id' => 'TID: ABC-123-4567',
            'sender_number' => '03009998888',
        ])->assertSessionHas('error');

        // 1-Tap WhatsApp link requires Admin Secret Key (admin_quick_pin), then approves directly once PIN is provided via POST
        $this->get(route('webhooks.order.quick', ['order' => $order->number, 'do' => 'approve']))
            ->assertOk()
            ->assertSee('Admin Secret Key');

        // Link preview GET request must NEVER auto-approve the order!
        $this->assertSame(OrderStatus::PaymentReview, $order->refresh()->status);

        $this->post(route('webhooks.order.quick', ['order' => $order->number, 'do' => 'approve']), [
            'pin' => '7860',
            'do' => 'approve',
        ])->assertOk()->assertSee('Order Approved &amp; Delivered!', false);

        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
    }

    public function test_one_tap_approve_directly_completes_order_even_with_low_supplier_balance(): void
    {
        $supplier = $this->supplier(['balance' => 0.10]); // Low supplier balance ($0.10 < $1.00 cost)
        $sp = $this->supplierProduct($supplier, ['cost' => 1.0, 'stock' => 5]);
        $product = $this->listing([$sp], ['fixed_price' => 550]);
        $method = $this->paymentMethod();

        $order = app(OrderService::class)->create($product, 1, $this->customer(), $method);

        $this->post(route('orders.payment', $order), [
            'token' => $order->access_token,
            'transaction_id' => 'TID99887766',
            'sender_number' => '03001234567',
        ])->assertRedirect($order->customerUrl());

        // Manual payment stays in PaymentReview until Admin approves via 1-Tap
        $this->assertSame(OrderStatus::PaymentReview, $order->refresh()->status);

        // Admin approves via 1-Tap with Secret Key & custom credentials -> goes directly to Completed (never stuck on NeedsAttention!)
        $this->post(route('webhooks.order.quick', ['order' => $order->number]), [
            'pin' => '7860',
            'do' => 'approve',
            'credentials' => 'manual_account@test.com:pass786',
        ])->assertOk()->assertSee('Order Approved &amp; Delivered!', false);

        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        $this->assertSame('manual_account@test.com:pass786', $order->deliveries()->first()->content);
    }

    public function test_already_confirmed_order_shows_warning_on_admin_quick_approve(): void
    {
        $product = $this->listing();
        $product->stockItems()->create(['content' => 'auto@test.com:secret123']);
        $method = $this->paymentMethod();

        $order = app(OrderService::class)->create($product, 1, $this->customer(), $method);
        $order->update(['status' => OrderStatus::Completed, 'completed_at' => now()]);

        $this->post(route('webhooks.order.quick', ['order' => $order->number, 'do' => 'approve']), [
            'pin' => '7860',
            'do' => 'approve',
        ])
            ->assertOk()
            ->assertSee('Already Confirmed &amp; Delivered!', false)
            ->assertSee('Duplicate Approval Prevented');
    }

    public function test_reseller_portal_and_wallet_instant_checkout(): void
    {
        $product = $this->listing([], ['fixed_price' => 1000, 'price' => 1000]);
        $product->stockItems()->createMany([
            ['content' => 'vip1@test.com:pass1'],
            ['content' => 'vip2@test.com:pass2'],
        ]);
        app(InventoryService::class)->refresh($product);

        $reseller = User::factory()->create([
            'name' => 'Ali Reseller',
            'email' => 'ali@reseller.com',
            'phone' => '03001112233',
            'is_reseller' => true,
            'reseller_slug' => 'ali',
            'reseller_discount_percent' => 20, // 20% off -> Rs 800
        ]);

        // Phone auto-formatted to 923001112233
        $this->assertSame('923001112233', $reseller->phone);

        // Top-up wallet balance
        $reseller->adjustWallet(2000, 'topup', 'Initial deposit');
        $this->assertSame(2000.0, (float) $reseller->refresh()->wallet_balance);

        // Guest visiting /ali sees the Secret VIP Reseller Login Screen
        $this->get('/ali')
            ->assertOk()
            ->assertSee('Secret VIP Reseller Portal')
            ->assertSee('/ali');

        // Reseller logs in and sees Rs 800 wholesale price (20% off 1000)
        $this->actingAs($reseller)
            ->get('/ali')
            ->assertOk()
            ->assertSee('Wholesale Portal')
            ->assertSee('Rs 800');

        // Per-product custom price override (Rs 650) takes priority over global %
        ResellerProductPrice::create([
            'user_id' => $reseller->id,
            'product_id' => $product->id,
            'custom_price' => 650,
        ]);

        $this->assertSame(650.0, $product->priceFor($reseller->refresh()));

        // 1-Click Instant Wallet Buy on /ali/wallet-buy/{product}
        $this->actingAs($reseller)
            ->post(route('reseller.wallet-buy', ['slug' => 'ali', 'product' => $product->slug]), [
                'quantity' => 1,
            ])
            ->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(650.0, (float) $order->total);
        $this->assertSame(1350.0, (float) $reseller->refresh()->wallet_balance);
        $this->assertSame('vip1@test.com:pass1', $order->deliveries()->first()->content);
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
