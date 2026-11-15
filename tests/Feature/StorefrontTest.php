<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\ResellerProductPrice;
use App\Models\Setting;
use App\Models\User;
use App\Models\Voucher;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ProductSeoOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
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
        $this->get(route('password.request'))->assertOk();

        foreach (['terms', 'privacy', 'refund', 'contact', 'faq'] as $page) {
            $this->get(route('pages.show', $page))->assertOk();
        }

        $this->get(route('sitemap'))->assertOk()->assertSee(route('products.show', $product), false);
        $this->get(route('feed.google-shopping'))->assertOk()->assertSee($product->name);
        $this->get(route('llms'))->assertOk()->assertSee($product->name);
        $this->get(route('robots'))->assertOk()->assertSee('Sitemap:');

        // www to non-www 301 permanent redirect for canonical SEO
        $this->get('https://www.sellmetools.com/shop?q=ai')
            ->assertStatus(301)
            ->assertRedirect('https://sellmetools.com/shop?q=ai');
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
        $product = $this->listing();
        $product->importStockItems("acc1@example.com:pass1\n---\nacc2@example.com:pass2", 'separator');
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
        // Before payment is verified, order & invoice must show Unpaid / Amount Due (never Total Paid)
        $this->get($order->customerUrl())
            ->assertOk()
            ->assertSee('Complete your payment')
            ->assertSee('Amount Due')
            ->assertDontSee('Total Paid')
            ->assertSee('https://wa.me/923001234567?text=', false);

        $this->get(route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token]))
            ->assertOk()
            ->assertSee('Order Invoice')
            ->assertSee('Unpaid')
            ->assertSee('Amount Due')
            ->assertSee('images/logo.png', false)
            ->assertDontSee('Total Paid');

        $this->get(route('orders.show', ['order' => $order->number, 'token' => 'wrong']))->assertOk(); // allowed in same guest session

        $this->flushSession();
        $this->get(route('orders.show', $order))->assertNotFound();
        $this->get(route('orders.show', ['order' => $order->number, 'token' => 'wrong']))->assertNotFound();

        $this->post(route('orders.payment', $order), [
            'token' => $order->access_token,
            'transaction_id' => 'ABC1234567',
            'sender_number' => '03009998888',
        ])->assertRedirect($order->customerUrl());

        $this->get($order->customerUrl())->assertSee('Admin is Verifying Your Payment');

        // If 15 minutes pass without Admin verifying yet, status endpoint reports review_timeout = true (Order On Hold)
        $this->travel(16)->minutes();
        $this->getJson(route('orders.status', ['order' => $order->number, 'token' => $order->access_token]))
            ->assertOk()
            ->assertJsonPath('review_timeout', true);

        // Duplicate TID (even with dashes or TID: prefix on a second order) must be blocked
        $product->importStockItems('acc3@example.com:pass3');
        $order2 = app(OrderService::class)->create($product->refresh(), 1, $this->customer(), $method);
        $this->post(route('orders.payment', $order2), [
            'token' => $order2->access_token,
            'transaction_id' => 'TID: ABC-123-4567',
            'sender_number' => '03009998888',
        ])->assertSessionHas('error');

        // 1-Tap WhatsApp link requires Admin Secret Key (admin_quick_pin), and works EVEN AFTER the 15-minute window has passed!
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
        $this->assertSame(PaymentStatus::Approved, $order->latestPayment->refresh()->status);
        $this->assertSame(0, $order->payments()->where('status', PaymentStatus::Pending)->count());
        $this->assertCount(2, $order->deliveries);

        // Logged-in customer and admin can both view the order page cleanly without any Admin Only banner on the customer page
        $customerUser = User::factory()->create();
        $adminUser = User::factory()->create(['is_admin' => true]);
        $this->actingAs($customerUser)->get($order->customerUrl())
            ->assertOk()
            ->assertSee('Delivered Access Credentials')
            ->assertSee('Total Paid')
            ->assertDontSee('Admin Only')
            ->assertDontSee('Supplier / Source');
        $this->actingAs($adminUser)->get($order->customerUrl())
            ->assertOk()
            ->assertDontSee('Admin Only')
            ->assertDontSee('Supplier / Source');

        $this->get(route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token]))
            ->assertOk()
            ->assertSee('Payment Receipt')
            ->assertSee('Total Paid')
            ->assertSee('images/logo.png', false);

        $pdfResponse = $this->get(route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token, 'download' => 'pdf']));
        $pdfResponse->assertOk();
        $this->assertStringContainsString('PAYMENT RECEIPT', $pdfResponse->getContent());
        $this->assertStringContainsString('TOTAL PAID:', $pdfResponse->getContent());
        $this->assertStringContainsString('/Im1', $pdfResponse->getContent());
    }

    public function test_one_tap_approve_holds_order_when_supplier_balance_is_low_and_delivers_when_admin_pastes_credentials(): void
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

        // 1) Admin approves payment WITHOUT custom credentials when supplier balance is low ->
        // Payment is confirmed, SupplierOrder attempt is recorded as Failed, and Order stays on NeedsAttention (never delivered empty!)
        $this->post(route('webhooks.order.quick', ['order' => $order->number]), [
            'pin' => '7860',
            'do' => 'approve',
        ])->assertOk()->assertSee('Payment Confirmed: ⚠️ Delivery On Hold', false);

        $this->assertSame(OrderStatus::NeedsAttention, $order->refresh()->status);
        $this->assertCount(0, $order->deliveries);

        // 2) Admin pastes custom credentials + manual cost on the 1-Tap page -> Order completes immediately as Admin Manual!
        $this->post(route('webhooks.order.quick', ['order' => $order->number]), [
            'pin' => '7860',
            'do' => 'retry',
            'credentials' => 'manual_account@test.com:pass786',
            'manual_cost' => '150',
        ])->assertOk()->assertSee('Order Approved &amp; Delivered!', false);

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame('manual_account@test.com:pass786', $order->deliveries()->first()->content);
        $this->assertSame('Admin Manual', $order->adminSupplierSummary(false));
        $this->assertSame('Admin Manual (Cost: Rs 150)', $order->adminSupplierSummary(true));
        $this->assertSame(150.0, (float) $order->total_cost);
        $this->assertSame(400.0, (float) $order->profit());
    }

    public function test_txt_separator_extracts_exact_pcs_and_never_resells_sold_stock(): void
    {
        $product = $this->listing([], ['fixed_price' => 500, 'price' => 500]);
        $txtContent = <<<'TXT'
        Email: a@gmail.com
        Pass: passA
        Pin: 1111
        ---
        Email: b@gmail.com
        Pass: passB
        Pin: 2222
        ---
        Email: c@gmail.com
        Pass: passC
        Pin: 3333
        TXT;

        $added = $product->importStockItems($txtContent, 'auto');
        $this->assertSame(3, $added);
        $this->assertSame(3, $product->refresh()->available_stock);

        $method = $this->paymentMethod();

        // Customer 1 buys 2 PCs
        $order1 = app(OrderService::class)->create($product, 2, $this->customer(), $method);
        $payment1 = app(PaymentService::class)->submit($order1, 'TIDTXT0001', '03001112222');
        $order1 = app(PaymentService::class)->approve($payment1);

        $this->assertSame(OrderStatus::Completed, $order1->status);
        $delivered1 = $order1->deliveries()->pluck('content')->all();
        $this->assertCount(2, $delivered1);
        $this->assertStringContainsString('a@gmail.com', $delivered1[0]);
        $this->assertStringContainsString('Pin: 1111', $delivered1[0]);
        $this->assertStringContainsString('b@gmail.com', $delivered1[1]);

        // Only 1 PC remains available; sold items are never sold again
        $this->assertSame(1, $product->refresh()->available_stock);

        // Customer 2 buys 1 PC -> gets Account C (never A or B)
        $order2 = app(OrderService::class)->create($product, 1, $this->customer(), $method);
        $payment2 = app(PaymentService::class)->submit($order2, 'TIDTXT0002', '03001112222');
        $order2 = app(PaymentService::class)->approve($payment2);

        $this->assertSame(OrderStatus::Completed, $order2->status);
        $delivered2 = $order2->deliveries()->pluck('content')->all();
        $this->assertCount(1, $delivered2);
        $this->assertStringContainsString('c@gmail.com', $delivered2[0]);
        $this->assertSame(0, $product->refresh()->available_stock);
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

    public function test_customer_can_reset_password(): void
    {
        $user = User::factory()->create([
            'email' => 'resetuser@example.com',
            'password' => bcrypt('oldpassword123'),
        ]);

        $this->get(route('password.request'))->assertOk();

        $token = Password::broker()->createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee($user->email);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'newpassword786',
            'password_confirmation' => 'newpassword786',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('newpassword786', $user->refresh()->password));
    }

    public function test_deleting_or_disabling_supplier_detaches_sources_and_zeros_product_stock(): void
    {
        $supplier = $this->supplier(['balance' => 50]);
        $sp = $this->supplierProduct($supplier, ['cost' => 1.0, 'stock' => 15]);
        $product = $this->listing([$sp], ['fixed_price' => 500]);

        $this->assertSame(15, $product->refresh()->available_stock);
        $this->assertCount(1, $product->activeSources);

        // Disabling the supplier immediately sets linked product stock to 0
        $supplier->update(['is_active' => false]);
        $this->assertSame(0, $product->refresh()->available_stock);

        // Re-enabling restores stock to 15
        $supplier->update(['is_active' => true]);
        $this->assertSame(15, $product->refresh()->available_stock);

        // Deleting the supplier detaches product_sources and sets product stock to 0
        $supplier->delete();
        $product->refresh();
        $this->assertSame(0, $product->available_stock);
        $this->assertCount(0, $product->sources()->get());
    }

    public function test_voucher_system_and_admin_order_id_routing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $productA = $this->listing([], ['fixed_price' => 1000, 'price' => 1000]);
        $productA->importStockItems("credA1\ncredA2\ncredA3", 'line');
        $productB = $this->listing([], ['fixed_price' => 2000, 'price' => 2000]);
        $productB->importStockItems("credB1\ncredB2", 'line');
        $method = $this->paymentMethod();

        // 1. Create a 25% OFF voucher restricted to Product A with max_uses = 1
        $voucher = Voucher::create([
            'code' => 'SAVE25',
            'title' => 'Product A 25% Promo',
            'discount_type' => 'percentage',
            'discount_value' => 25,
            'scope' => 'specific',
            'product_ids' => [$productA->id],
            'max_uses' => 1,
            'is_active' => true,
        ]);

        // 2. Fails on Product B (scope mismatch)
        $this->postJson(route('checkout.voucher', $productB), [
            'code' => 'SAVE25',
            'quantity' => 1,
            'email' => 'customer@example.com',
        ])->assertStatus(422)->assertJson(['ok' => false]);

        // 3. Succeeds on Product A (25% of Rs 1000 = Rs 250 discount, final total = Rs 750)
        $this->postJson(route('checkout.voucher', $productA), [
            'code' => 'SAVE25',
            'quantity' => 1,
            'email' => 'customer@example.com',
        ])->assertOk()->assertJson([
            'ok' => true,
            'code' => 'SAVE25',
            'discount_pkr' => 250.0,
            'final_total_pkr' => 750.0,
        ]);

        // 4. Checkout with voucher applies discount & increments used_count
        $this->post(route('checkout.store', $productA), [
            'quantity' => 1,
            'email' => 'customer@example.com',
            'name' => 'Test Buyer',
            'phone' => '03001234567',
            'voucher_code' => 'save25',
            'payment_method_id' => $method->id,
            'agree' => '1',
        ])->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('SAVE25', $order->voucher_code);
        $this->assertSame(250.0, (float) $order->discount_amount);
        $this->assertSame(750.0, (float) $order->total);
        $this->assertSame(1, $voucher->refresh()->used_count);

        // 5. Second attempt fails because max_uses = 1 is reached
        $this->postJson(route('checkout.voucher', $productA), [
            'code' => 'SAVE25',
            'quantity' => 1,
            'email' => 'another@example.com',
        ])->assertStatus(422)->assertJson(['ok' => false]);

        // 6. Both /admin/orders/{number} AND /admin/orders/{id} resolve 200 OK without 404, and /admin/vouchers renders
        $this->actingAs($admin)->get('/admin/orders/'.$order->number)->assertOk();
        $this->actingAs($admin)->get('/admin/orders/'.$order->id)->assertOk();
        $this->actingAs($admin)->get('/admin/vouchers')->assertOk()->assertSee('SAVE25');
        $this->actingAs($admin)->get('/admin/vouchers/'.$voucher->id.'/edit')->assertOk()->assertSee($order->number);
    }

    public function test_product_title_and_seo_title_expand_shorthand_durations_and_strip_bot_codes(): void
    {
        $cases = [
            'Surfshark 2M Acc' => 'Surfshark 2 Months Account',
            'Surfshark VPN 2M Coupon' => 'Surfshark VPN 2 Months Coupon',
            'Lovable Pro 2M Link' => 'Lovable Pro 2 Months Link',
            'Freepik Premium 1 M Acc' => 'Freepik Premium 1 Month Account',
            'CapCut Pro 6Months FW' => 'CapCut Pro 6 Months',
            'Chatgpt Plus 1m 1hw' => 'ChatGPT Plus 1 Month',
            'HeyGen Creator 1Month 20DW' => 'HeyGen Creator 1 Month',
            'Higgsfield Creator 1M 6000 Credits W25D' => 'Higgsfield Creator 1 Month 6000 Credits',
            'Kling Standard 1Month 1000 CreditsW1D' => 'Kling Standard 1 Month 1000 Credits',
            'Claude API 10M Token 3D' => 'Claude API 10M Token 3 Days',
            'Claude API 100Million Token 3D' => 'Claude API 100 Million Token 3 Days',
            'Super Duolingo 12M Link' => 'Super Duolingo 12 Months Link',
            'Figma Edu 2Year 1MW' => 'Figma Edu 2 Years',
            '[KEY] HMA Android/PC 30 Days' => 'HMA VPN Android & PC 30 Days Key',
            'MIcrosoft Office365 (lifetime plan)' => 'Microsoft Office 365 Lifetime Plan',
            'Ms365 Admin 1 Year Family' => 'Microsoft 365 Admin 1 Year Family',
            'Higgsfield Genjutsu 30-day limit - 24H warranty' => 'Higgsfield Genjutsu 30 Days',
            'Antigravity Slot x20 Ultra 1 Month ( 0 Credit )' => 'Antigravity Slot x20 Ultra 1 Month',
        ];

        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, ProductSeoOptimizer::cleanProductTitle($raw));
        }

        $product = $this->listing([], [
            'name' => 'Surfshark 2M Acc (FW)',
            'meta_title' => 'Buy Surfshark 2m Acc | Best Price in Pakistan',
        ]);

        $this->assertSame('Surfshark 2 Months Account', $product->name);
        $this->assertSame('Buy Surfshark 2 Months Account | Best Price in Pakistan', $product->seoTitle());
    }

    public function test_clean_product_slugs_and_301_redirects_preserve_seo_equity(): void
    {
        $this->assertSame('chatgpt-plus-1-month', ProductSeoOptimizer::resolveCleanSlug('ChatGPT Plus 1 Month', 'chatgpt-plus-1m-1hw'));
        $this->assertSame('capcut-pro-6-months', ProductSeoOptimizer::resolveCleanSlug('CapCut Pro 6 Months', 'capcut-pro-6months-fw'));
        $this->assertSame('microsoft-365-admin-1-year-family', ProductSeoOptimizer::resolveCleanSlug('Microsoft 365 Admin 1 Year Family', 'ms365-admin-1-year-family-fw'));
        $this->assertSame('chatgpt-plus-1-month-full-warranty', ProductSeoOptimizer::resolveCleanSlug('ChatGPT Plus 1 Month', 'chatgpt-plus-1-month-full-warranty'));

        $product = $this->listing([$this->supplierProduct($this->supplier(), ['stock' => 5])], [
            'name' => 'ChatGPT Plus 1m 1hw',
            'slug' => 'chatgpt-plus-1m-1hw',
        ]);

        // Run optimizer and verify slug cleanup + legacy_slugs recording + 301 redirects
        $this->assertTrue(ProductSeoOptimizer::syncCatalogNow());
        $product->refresh();
        $this->assertSame('chatgpt-plus-1-month', $product->slug);
        $this->assertContains('chatgpt-plus-1m-1hw', $product->legacy_slugs ?? []);

        $this->get('/products/chatgpt-plus-1m-1hw')
            ->assertStatus(301)
            ->assertRedirect(route('products.show', $product));

        $this->get('/checkout/chatgpt-plus-1m-1hw')
            ->assertStatus(301)
            ->assertRedirect(route('checkout.create', $product));

        $this->get('/products/test-reseller-api-elite')
            ->assertStatus(301)
            ->assertRedirect(route('shop'));

        $this->get('/?seed=239527&page=2')
            ->assertStatus(301)
            ->assertRedirect(route('home'));
    }

    public function test_search_api_always_returns_real_time_stock_without_stale_cache(): void
    {
        $product = $this->listing([], [
            'name' => 'ElevenLabs Creator 3 Months',
            'available_stock' => 0,
            'stock_unlimited' => false,
        ]);

        // 1. Initially 0 stock -> search API shows in_stock = false, qty = 0
        $res1 = $this->getJson(route('search.suggest', ['q' => 'elevenlabs']))->assertOk()->json('products.0');
        $this->assertFalse($res1['in_stock']);
        $this->assertSame(0, $res1['qty']);

        $cat1 = collect($this->getJson(route('search.suggest', ['catalog' => 1]))->assertOk()->json('products'))
            ->firstWhere('id', $product->id);
        $this->assertFalse($cat1['in_stock']);

        // 2. Stock is added (e.g. via supplier sync or stock items) -> search API immediately reflects in_stock = true
        $product->stockItems()->createMany([
            ['content' => 'el1@test.com:pass1'],
            ['content' => 'el2@test.com:pass2'],
            ['content' => 'el3@test.com:pass3'],
        ]);
        app(InventoryService::class)->refresh($product);

        $res2 = $this->getJson(route('search.suggest', ['q' => 'elevenlabs']))->assertOk()->json('products.0');
        $this->assertTrue($res2['in_stock']);
        $this->assertSame(3, $res2['qty']);

        $cat2 = collect($this->getJson(route('search.suggest', ['catalog' => 1]))->assertOk()->json('products'))
            ->firstWhere('id', $product->id);
        $this->assertTrue($cat2['in_stock']);
        $this->assertSame(3, $cat2['qty']);
    }

    public function test_home_shows_centered_view_all_products_cta_without_old_catalog_banner(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->listing([$this->supplierProduct($this->supplier(), ['stock' => 3])], [
                'name' => "Tool Item {$i}",
                'slug' => "tool-item-{$i}",
            ]);
        }

        $response = $this->get(route('home'))->assertOk();
        $response->assertSee('View All Products');
        $response->assertSee(route('shop'), false);
        $response->assertDontSee('Want to Browse Our Entire Catalog?');
    }

    public function test_shop_renders_load_more_and_ajax_pagination_controls(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->listing([$this->supplierProduct($this->supplier(), ['stock' => 2])], [
                'name' => sprintf('Catalog Tool %02d', $i),
                'slug' => sprintf('catalog-tool-%02d', $i),
            ]);
        }

        $page1 = $this->get(route('shop'))->assertOk();
        $page1->assertSee('Load More Products');
        $page1->assertSee('data-ajax-pagination', false);
        $page1->assertSee('loadMoreGrid(', false);
        $page1->assertSee('page=2', false);

        $page2Ajax = $this->withHeaders(['X-SMT-Filter' => '1'])
            ->get(route('shop', ['page' => 2]))
            ->assertOk();
        $page2Ajax->assertSee('id="shop-results"', false);
        $page2Ajax->assertSee('data-ajax-pagination', false);
    }

    public function test_new_curated_product_seo_updates_only_target_products_with_valid_lengths(): void
    {
        $customExisting = $this->listing([], [
            'name' => 'Custom Unrelated Product',
            'slug' => 'custom-unrelated-product',
            'description' => '<p>Keep untouched custom description</p>',
            'meta_title' => 'Keep Untouched Meta Title',
        ]);

        $newProducts = [
            $this->listing([], ['name' => 'GEMINI PLUS 6 Months', 'slug' => 'gemini-plus-6-months']),
            $this->listing([], ['name' => 'Proton VPN 1 Month', 'slug' => 'proton-vpn-1-month']),
            $this->listing([], ['name' => 'Google Flow Omni + Veo 1 Month', 'slug' => 'google-flow-omni-veo-1-month']),
            $this->listing([], ['name' => 'YouTube Premium 3 Months Voucher', 'slug' => 'youtube-premium-3-months-voucher']),
        ];

        $this->assertTrue(ProductSeoOptimizer::syncNewCuratedNow());

        foreach ($newProducts as $product) {
            $product->refresh();
            $this->assertStringContainsString('<h3>Product Overview</h3>', (string) $product->description);
            $this->assertStringContainsString('<h3>What You Get &amp; Key Features</h3>', (string) $product->description);
            $this->assertNotEmpty($product->meta_title);
            $this->assertNotEmpty($product->meta_description);
            $this->assertLessThanOrEqual(240, mb_strlen((string) $product->meta_keywords));
            $this->assertStringNotContainsString("\u{2014}", (string) $product->description);
            $this->assertStringNotContainsString("\u{2013}", (string) $product->description);
        }

        $customExisting->refresh();
        $this->assertSame('<p>Keep untouched custom description</p>', $customExisting->description);
        $this->assertSame('Keep Untouched Meta Title', $customExisting->meta_title);
    }

    public function test_active_categories_cache_uses_plain_arrays_safe_without_serializable_classes(): void
    {
        $cat = Category::create(['name' => 'AI Tools', 'slug' => 'ai-tools', 'is_active' => true]);
        $this->listing([$this->supplierProduct($this->supplier(), ['stock' => 2])], [
            'category_id' => $cat->id,
        ]);

        // Force real database cache driver (serializable_classes = false) across two requests
        config()->set('cache.default', 'database');
        Cache::forget('smt_active_categories_v2');

        $this->get(route('home'))->assertOk()->assertSee('AI Tools');
        // Second request must unserialize cleanly without __PHP_Incomplete_Class
        $this->get(route('home'))->assertOk()->assertSee('AI Tools');
        $this->get(route('shop', ['category' => 'ai-tools']))->assertOk()->assertSee('AI Tools');

        $cached = Cache::get('smt_active_categories_v2');
        $this->assertIsArray($cached);
        $this->assertNotEmpty($cached);
        $this->assertIsArray($cached[0]);
        $this->assertSame('ai-tools', $cached[0]['slug']);
    }

    public function test_seo_audit_fixes_robots_ard_meta_descriptions_and_product_a11y(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));
        $this->assertNotFalse($htaccess);
        $this->assertStringContainsString('RewriteRule ^robots\.txt$ index.php [L]', $htaccess);

        $robots = $this->get('/robots.txt');
        $robots->assertOk();
        $robotsContent = $robots->getContent();
        $this->assertStringContainsString('Content-Signal: search=yes, ai-input=yes, ai-train=yes', $robotsContent);
        $this->assertStringContainsString('Sitemap: ', $robotsContent);
        $this->assertMatchesRegularExpression('/User-agent:\s*GPTBot[\s\S]*?Content-Signal:\s*search=yes/', $robotsContent);

        $emptyCategory = Category::create(['name' => 'Design Studio', 'slug' => 'design-studio', 'is_active' => true]);
        $aiCategory = Category::create(['name' => 'AI Tools', 'slug' => 'ai-tools', 'is_active' => true]);
        $product = $this->listing([$this->supplierProduct($this->supplier(), ['stock' => 3])], [
            'category_id' => $aiCategory->id,
            'image' => null,
            'name' => 'Claude Pro Workspace',
            'slug' => 'claude-pro-workspace',
        ]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(route('categories.show', $emptyCategory->slug), false);

        foreach (['/.well-known/ard.json', '/.well-known/ai-catalog.json'] as $ardUrl) {
            $this->get($ardUrl)
                ->assertOk()
                ->assertJsonPath('specVersion', '1.0')
                ->assertJsonStructure([
                    'specVersion',
                    'entries' => [
                        ['identifier', 'displayName', 'type', 'url', 'representativeQueries'],
                    ],
                ]);
        }

        $this->get(route('home'))->assertOk()->assertSee('rel="ard"', false);

        $this->get('/api/agent-catalog.json')
            ->assertOk()
            ->assertJsonStructure(['schema_version', 'name', 'url', 'description', 'discovery', 'capabilities', 'categories']);

        foreach ([
            route('home'),
            route('shop'),
            route('categories.show', $aiCategory->slug),
            route('products.show', $product->slug),
            route('pages.show', 'about'),
            route('pages.show', 'contact'),
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame(1, preg_match('/<meta\s+name="description"\s+content="([^"]*)"/i', $html, $matches), "Missing meta description on {$url}");
            $desc = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertStringNotContainsString('..', $desc, "Truncated meta description on {$url}: {$desc}");
            $this->assertLessThanOrEqual(155, mb_strlen($desc), "Meta description too long on {$url}: {$desc}");
        }

        Setting::set('support_whatsapp', '923001234567');

        $productResponse = $this->get(route('products.show', $product->slug));
        $productResponse->assertOk()
            ->assertSee('<label for="rating"', false)
            ->assertSee('aria-label="Rating"', false)
            ->assertSee('images/og-image.png', false)
            ->assertSee('hidden sm:flex', false);

        $productHtml = $productResponse->getContent();
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $productHtml, $jsonLdMatches);
        $jsonLdCombined = implode("\n", $jsonLdMatches[1] ?? []);
        $this->assertStringNotContainsString('ReturnByMail', $jsonLdCombined);
        $this->assertStringNotContainsString('Verified Buyer', $jsonLdCombined);
        $this->assertStringContainsString('images/og-image.png', $jsonLdCombined);
    }

    public function test_mobile_performance_headers_fonts_and_lazy_assets(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));
        $this->assertNotFalse($htaccess);
        $this->assertStringContainsString('mod_brotli.c', $htaccess);
        $this->assertStringContainsString('application/x-javascript', $htaccess);
        $this->assertStringContainsString('font/woff', $htaccess);
        $this->assertStringContainsString('max-age=31536000, immutable', $htaccess);

        $fontsManifest = json_decode((string) file_get_contents(public_path('build/fonts-manifest.json')), true);
        $this->assertIsArray($fontsManifest);
        $familyStyles = implode("\n", $fontsManifest['style']['familyStyles'] ?? []);
        $this->assertStringContainsString('.woff2', $familyStyles);
        $this->assertStringNotContainsString('format("woff")', $familyStyles);

        $cat = Category::create(['name' => 'Productivity', 'slug' => 'productivity', 'is_active' => true]);
        $supplier = $this->supplier();
        foreach ([1, 2, 3] as $n) {
            $this->listing([$this->supplierProduct($supplier, ['stock' => 5])], [
                'category_id' => $cat->id,
                'name' => "Speed Tool {$n}",
                'slug' => "speed-tool-{$n}",
                'image' => "gallery/speed-tool-{$n}.webp",
            ]);
        }

        Setting::set('google_analytics_id', 'G-TEST12345');

        $homeHtml = $this->get(route('home'))
            ->assertOk()
            ->assertSee('width="40" height="40" loading="lazy"', false)
            ->assertDontSee('<script async src="https://www.googletagmanager.com/gtag/js', false)
            ->assertDontSee('style="background-image: url(', false)
            ->getContent();

        $this->assertStringContainsString('loading="lazy"', $homeHtml);
        $this->assertStringContainsString('https:\/\/www.googletagmanager.com\/gtag\/js?id=G-TEST12345', $homeHtml);
    }
}
