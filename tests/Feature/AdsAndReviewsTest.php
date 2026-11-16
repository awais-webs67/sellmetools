<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Pages\StoreSettings;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class AdsAndReviewsTest extends TestCase
{
    use CreatesStore, RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
        $this->paymentMethod();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => User::ROLE_SUPER_ADMIN,
        ]);

        $category = Category::create([
            'name' => 'AI Tools',
            'slug' => 'ai-tools',
            'is_active' => true,
            'sort' => 1,
        ]);

        $this->product = $this->listing(
            [$this->supplierProduct($this->supplier())],
            [
                'category_id' => $category->id,
                'name' => 'Claude Pro 1 Month Subscription',
                'slug' => 'claude-pro-1-month',
                'fixed_price' => 2500,
                'price' => 2500,
            ]
        );
    }

    public function test_ads_pixels_are_configurable_and_fire_ecommerce_conversion_events_only_when_enabled(): void
    {
        // Disabled by default: Meta/TikTok/Google Ads IDs must not appear.
        $this->get(route('products.show', $this->product))
            ->assertOk()
            ->assertDontSee('connect.facebook.net/en_US/fbevents.js', false);

        $this->actingAs($this->admin);
        Livewire::test(StoreSettings::class)
            ->fillForm([
                'ads_tracking_enabled' => true,
                'meta_pixel_id' => '987654321098765',
                'google_ads_id' => 'AW-112233445',
                'google_ads_purchase_label' => 'BuyConvLbl99',
                'tiktok_pixel_id' => 'CTTPIXEL001',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1', Setting::get('ads_tracking_enabled'));
        $this->assertSame('987654321098765', Setting::get('meta_pixel_id'));
        $this->assertSame('AW-112233445', Setting::get('google_ads_id'));

        $this->get(route('products.show', $this->product))
            ->assertOk()
            ->assertSee('987654321098765')
            ->assertSee('AW-112233445')
            ->assertSee('CTTPIXEL001')
            ->assertSee("smtTrackEvent('ViewContent'", false);

        $this->get(route('checkout.create', $this->product->slug))
            ->assertOk()
            ->assertSee("smtTrackEvent('InitiateCheckout'", false);

        $order = Order::create([
            'number' => 'SMT-ADS-1001',
            'email' => 'buyer@example.com',
            'phone' => '923001234567',
            'customer_name' => 'Bilal',
            'status' => OrderStatus::Completed,
            'total' => 2500,
            'access_token' => 'tok-ads-1001',
            'paid_at' => now(),
            'completed_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 1,
            'unit_price' => 2500,
            'line_total' => 2500,
        ]);

        $this->get($order->customerUrl())
            ->assertOk()
            ->assertSee("smtTrackEvent('Purchase'", false)
            ->assertSee('SMT-ADS-1001')
            ->assertSee('BuyConvLbl99');
    }

    public function test_completed_order_customer_can_submit_single_verified_review_with_order_id(): void
    {
        $order = Order::create([
            'number' => 'SMT-REV-2002',
            'email' => 'customer@example.com',
            'phone' => '923001112233',
            'customer_name' => 'Usman Tariq',
            'status' => OrderStatus::PendingPayment,
            'total' => 2500,
            'access_token' => 'tok-rev-2002',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 1,
            'unit_price' => 2500,
            'line_total' => 2500,
        ]);

        // Unauthorized token -> 404.
        $this->post(route('orders.review.store', $order), [
            'token' => 'wrong-token',
            'product_id' => $this->product->id,
            'rating' => 5,
            'comment' => 'Great service!',
        ])->assertNotFound();

        // Pending payment -> blocked from reviewing.
        $this->post(route('orders.review.store', $order), [
            'token' => 'tok-rev-2002',
            'product_id' => $this->product->id,
            'rating' => 5,
            'comment' => 'Great service!',
        ])->assertRedirect();

        $this->assertDatabaseMissing('reviews', ['order_id' => $order->id]);

        // Complete the order.
        $order->update(['status' => OrderStatus::Completed, 'completed_at' => now()]);

        $this->get($order->customerUrl())
            ->assertOk()
            ->assertSee('Submit Verified Review');

        $this->post(route('orders.review.store', $order), [
            'token' => 'tok-rev-2002',
            'product_id' => $this->product->id,
            'customer_name' => 'Usman Tariq',
            'rating' => 5,
            'comment' => 'Activated smoothly in under 2 minutes!',
        ])->assertRedirect($order->customerUrl().'#review');

        $this->assertDatabaseHas('reviews', [
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'customer_name' => 'Usman Tariq',
            'rating' => 5,
            'comment' => 'Activated smoothly in under 2 minutes!',
            'is_approved' => 1,
        ]);

        // Duplicate submission on same order is ignored.
        $this->post(route('orders.review.store', $order), [
            'token' => 'tok-rev-2002',
            'product_id' => $this->product->id,
            'rating' => 1,
            'comment' => 'Duplicate attempt',
        ])->assertRedirect($order->customerUrl().'#review');

        $this->assertSame(1, Review::query()->where('order_id', $order->id)->count());

        $this->get($order->customerUrl())
            ->assertOk()
            ->assertSee('Verified Review Published!');
    }
}

