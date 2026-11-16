<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PricingMode;
use App\Enums\StockStatus;
use App\Enums\SupplierOrderStatus;
use App\Filament\Pages\StoreSettings;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\SourcesRelationManager;
use App\Filament\Resources\Products\RelationManagers\StockItemsRelationManager;
use App\Filament\Resources\SupplierOrders\Pages\ListSupplierOrders;
use App\Filament\Resources\SupplierProducts\Pages\ListSupplierProducts;
use App\Filament\Resources\SupplierProducts\SupplierProductResource;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\Listings;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use CreatesStore, RefreshDatabase;

    protected User $admin;

    protected Supplier $supplierModel;

    protected SupplierProduct $supplierItem;

    protected Product $product;

    protected Order $order;

    protected Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
        Storage::fake('local');
        Storage::fake('public');

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();

        $category = Category::create(['name' => 'AI Tools', 'slug' => 'ai-tools']);
        $this->supplierModel = $this->supplier(['balance' => 12.5]);
        $this->supplierItem = $this->supplierProduct($this->supplierModel, ['cost' => 2.0, 'warranty' => '30 days']);
        $this->product = $this->listing([$this->supplierItem], [
            'category_id' => $category->id,
            'pricing_mode' => PricingMode::MarkupPercent,
            'markup_value' => 25,
        ]);
        $this->product->stockItems()->create(['content' => 'own@example.com:secret', 'status' => StockStatus::Available]);
        Listings::refresh($this->product);

        $method = $this->paymentMethod();
        $this->order = Order::create([
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.com',
            'phone' => '03000000000',
            'payment_method_id' => $method->id,
            'status' => OrderStatus::PaymentReview,
            'total' => 750,
            'currency' => 'PKR',
            'usdt_rate' => 300,
        ]);
        $item = $this->order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'unit_price' => 750,
            'quantity' => 2,
            'line_total' => 1500,
        ]);
        $item->deliveries()->create(['content' => 'delivered@example.com:pass', 'source' => 'manual']);
        SupplierOrder::create([
            'supplier_id' => $this->supplierModel->id,
            'order_item_id' => $item->id,
            'supplier_product_id' => $this->supplierItem->id,
            'request_id' => 'req-1',
            'quantity' => 1,
            'status' => SupplierOrderStatus::Failed,
            'error' => 'Out of stock',
        ]);

        Storage::disk('local')->put('payments/shot.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $this->payment = $this->order->payments()->create([
            'payment_method_id' => $method->id,
            'amount' => 750,
            'currency' => 'PKR',
            'transaction_id' => 'TID123456',
            'sender_number' => '03001112222',
            'screenshot' => 'payments/shot.png',
            'status' => PaymentStatus::Pending,
        ]);
    }

    public function test_every_admin_page_renders(): void
    {
        $this->actingAs($this->admin);

        $urls = [
            '/admin',
            '/admin/settings',
            CategoryResource::getUrl('index'),
            CategoryResource::getUrl('create'),
            CategoryResource::getUrl('edit', ['record' => Category::first()]),
            SupplierResource::getUrl('index'),
            SupplierResource::getUrl('create'),
            SupplierResource::getUrl('edit', ['record' => $this->supplierModel]),
            SupplierProductResource::getUrl('index'),
            ProductResource::getUrl('index'),
            ProductResource::getUrl('create'),
            ProductResource::getUrl('edit', ['record' => $this->product]),
            OrderResource::getUrl('index'),
            OrderResource::getUrl('view', ['record' => $this->order]),
            PaymentResource::getUrl('index'),
            PaymentMethodResource::getUrl('index'),
            PaymentMethodResource::getUrl('create'),
            PaymentMethodResource::getUrl('edit', ['record' => $this->order->paymentMethod]),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(OrderResource::getUrl('view', ['record' => $this->order]))
            ->assertSee('delivered@example.com:pass')
            ->assertSee('TID123456')
            ->assertSee('Out of stock')
            ->assertSee('data:image/png;base64,', false);
    }

    public function test_non_admin_users_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_create_listing_from_supplier_product(): void
    {
        $this->actingAs($this->admin);
        $fresh = $this->supplierProduct($this->supplierModel, ['cost' => 1.0, 'name' => 'Canva Pro']);

        Livewire::test(ListSupplierProducts::class)
            ->callAction(TestAction::make('createListing')->table($fresh), [
                'name' => 'Canva Pro 1 Month',
                'pricing_mode' => PricingMode::MarkupAmount->value,
                'markup_value' => 95,
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $product = Product::where('name', 'Canva Pro 1 Month')->firstOrFail();
        // 1 USDT × 300 + 95 = 395 → rounded up to 400.
        $this->assertSame(400.0, (float) $product->price);
        $this->assertSame(300.0, (float) $product->cost_price);
        $this->assertSame(10, $product->available_stock);
        $this->assertTrue($product->sources->contains($fresh));
    }

    public function test_bulk_create_listings(): void
    {
        $this->actingAs($this->admin);
        $a = $this->supplierProduct($this->supplierModel, ['cost' => 1.0]);
        $b = $this->supplierProduct($this->supplierModel, ['cost' => 3.0]);

        Livewire::test(ListSupplierProducts::class)
            ->selectTableRecords([$a->id, $b->id, $this->supplierItem->id])
            ->callAction(TestAction::make('createListings')->table()->bulk(), [
                'pricing_mode' => PricingMode::MarkupPercent->value,
                'markup_value' => 10,
                'skip_listed' => true,
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(1, $a->listings()->count());
        $this->assertSame(1, $b->listings()->count());
        $this->assertSame(1, $this->supplierItem->listings()->count(), 'already-listed item is skipped');
    }

    public function test_bulk_add_stock_updates_inventory(): void
    {
        $this->actingAs($this->admin);
        $before = $this->product->fresh()->available_stock;

        Livewire::test(StockItemsRelationManager::class, [
            'ownerRecord' => $this->product,
            'pageClass' => EditProduct::class,
        ])
            ->callAction(TestAction::make('bulkAdd')->table(), ['items' => "a@x.com:1\n\n  b@x.com:2  \nc@x.com:3"])
            ->assertHasNoFormErrors();

        $this->assertSame(4, $this->product->stockItems()->count());
        $this->assertSame($before + 3, $this->product->fresh()->available_stock);
    }

    public function test_toggle_source_reprices_product(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(SourcesRelationManager::class, [
            'ownerRecord' => $this->product,
            'pageClass' => EditProduct::class,
        ])
            ->assertCanSeeTableRecords([$this->supplierItem])
            ->callAction(TestAction::make('toggleSource')->table($this->supplierItem));

        $this->assertFalse((bool) $this->product->sources()->first()->pivot->is_active);
        $this->assertNull($this->product->fresh()->cost_price);
    }

    public function test_product_create_runs_pricing(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Netflix Premium',
                'pricing_mode' => PricingMode::Fixed->value,
                'fixed_price' => 999,
                'max_per_order' => 3,
                'sort' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('name', 'Netflix Premium')->firstOrFail();
        $this->assertSame('netflix-premium', $product->slug);
        $this->assertSame(999.0, (float) $product->price);
    }

    public function test_supplier_api_key_is_kept_when_left_blank(): void
    {
        $this->actingAs($this->admin);
        $originalKey = $this->supplierModel->api_key;

        Livewire::test(EditSupplier::class, ['record' => $this->supplierModel->getRouteKey()])
            ->fillForm(['name' => 'Renamed supplier', 'api_key' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $supplier = $this->supplierModel->fresh();
        $this->assertSame('Renamed supplier', $supplier->name);
        $this->assertSame($originalKey, $supplier->api_key);
    }

    public function test_payment_can_be_rejected(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListPayments::class)
            ->assertCanSeeTableRecords([$this->payment])
            ->callAction(TestAction::make('rejectPayment')->table($this->payment), ['reason' => 'Not received'])
            ->assertHasNoFormErrors();

        $payment = $this->payment->fresh();
        $this->assertSame(PaymentStatus::Rejected, $payment->status);
        $this->assertSame('Not received', $payment->reject_reason);
        $this->assertSame($this->admin->id, $payment->reviewed_by);
    }

    public function test_order_can_be_cancelled(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ViewOrder::class, ['record' => $this->order->getRouteKey()])
            ->callAction('cancelOrder');

        $this->assertSame(OrderStatus::Cancelled, $this->order->fresh()->status);
    }

    public function test_order_can_be_cancelled_and_refunded_manually_without_crediting_store_wallet(): void
    {
        $this->actingAs($this->admin);

        $customer = User::factory()->create([
            'email' => 'manualrefund@example.com',
            'wallet_balance' => 100.00,
        ]);

        $this->order->update([
            'user_id' => $customer->id,
            'email' => $customer->email,
            'status' => OrderStatus::Completed,
            'paid_at' => now(),
            'completed_at' => now(),
        ]);

        Livewire::test(ViewOrder::class, ['record' => $this->order->getRouteKey()])
            ->callAction('cancelAndRefundManually', [
                'target_status' => OrderStatus::Refunded->value,
                'refund_account' => 'Easypaisa 03001234567',
                'refund_reference' => 'TID-778899',
                'note' => 'Refunded directly to customer wallet app',
            ])
            ->assertHasNoFormErrors();

        $freshOrder = $this->order->fresh();
        $this->assertSame(OrderStatus::Refunded, $freshOrder->status);
        $this->assertStringContainsString('Manual external refund', (string) $freshOrder->admin_note);
        $this->assertStringContainsString('Easypaisa 03001234567', (string) $freshOrder->admin_note);
        $this->assertStringContainsString('TID-778899', (string) $freshOrder->admin_note);
        $this->assertSame(100.0, (float) $customer->fresh()->wallet_balance);
        $this->assertDatabaseMissing('wallet_transactions', [
            'order_id' => $this->order->id,
            'type' => 'order_refund',
        ]);
    }

    public function test_orders_tabs_and_view_order_approve_work(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$this->order])
            ->set('activeTab', 'review')
            ->assertCanSeeTableRecords([$this->order]);

        Livewire::test(ViewOrder::class, ['record' => $this->order->getRouteKey()])
            ->callAction('approvePayment')
            ->assertHasNoFormErrors();

        $this->assertSame(PaymentStatus::Approved, $this->payment->fresh()->status);
    }

    public function test_settings_save_reprices_sourced_products(): void
    {
        $this->actingAs($this->admin);
        // 2 USDT × 300 × 1.25 = 750
        $this->assertSame(750.0, (float) $this->product->fresh()->price);

        Livewire::test(StoreSettings::class)
            ->assertSchemaStateSet(['usdt_rate' => '300'], 'form')
            ->fillForm(['usdt_rate' => 280, 'store_name' => 'My Store'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('My Store', Setting::get('store_name'));
        // 2 × 280 × 1.25 = 700
        $this->assertSame(700.0, (float) $this->product->fresh()->price);
    }

    public function test_attach_supplier_allows_second_variant_from_same_supplier_and_searches_supplier_name(): void
    {
        $this->actingAs($this->admin);

        // Create > 50 cheaper filler items on another supplier to ensure >50 items don't hide higher-cost variants.
        $otherSupplier = $this->supplier(['name' => 'Alpha Bulk']);
        for ($i = 1; $i <= 55; $i++) {
            $this->supplierProduct($otherSupplier, [
                'external_id' => 'filler-'.$i,
                'name' => 'Filler Tool '.$i,
                'cost' => 0.05,
                'stock' => 2,
            ]);
        }

        $secondVariant = $this->supplierProduct($this->supplierModel, [
            'external_id' => 'var-25dw',
            'name' => 'ChatGPT Plus 1M (25DW)',
            'cost' => 6.50,
            'stock' => 9,
        ]);

        // Mark first variant out of stock so only the 2nd variant has stock
        $this->supplierItem->update(['stock' => 0, 'is_available' => false]);

        $component = Livewire::test(SourcesRelationManager::class, [
            'ownerRecord' => $this->product,
            'pageClass' => EditProduct::class,
        ])->mountAction(TestAction::make('attach')->table());

        $select = $component
            ->instance()
            ->getMountedTableActionForm()
            ->getComponent('recordId');

        $results = $select->getSearchResults(strtolower($this->supplierModel->name).' 25dw');

        $this->assertArrayHasKey($secondVariant->id, $results);
        $this->assertArrayNotHasKey($this->supplierItem->id, $results);
        $this->assertStringContainsString("[#{$secondVariant->id} | Ext: var-25dw]", $results[$secondVariant->id]);

        $idResults = $select->getSearchResults('#'.$secondVariant->id);
        $this->assertArrayHasKey($secondVariant->id, $idResults);

        $component
            ->fillForm(['recordId' => $secondVariant->id])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertCanSeeTableRecords([$this->supplierItem, $secondVariant]);

        // 1 own stock item + 0 from 1st variant + 9 from 2nd variant
        $this->assertSame(10, $this->product->fresh()->available_stock);
    }

    public function test_create_listing_with_long_name_caps_generated_meta_keywords(): void
    {
        $source = $this->supplierProduct($this->supplierModel, [
            'external_id' => 'yt-3m-voucher',
            'name' => 'YouTube Premium 3 Months Voucher',
            'cost' => 1.5,
            'stock' => 10,
        ]);

        $product = Listings::createFromSupplierProduct($source, [
            'name' => 'YouTube Premium 3 Months Voucher',
            'pricing_mode' => PricingMode::MarkupPercent,
            'markup_value' => 130,
        ]);

        $this->assertNotEmpty($product->meta_keywords);
        $this->assertLessThanOrEqual(240, mb_strlen((string) $product->meta_keywords));
        $this->assertLessThanOrEqual(250, mb_strlen((string) $product->meta_title));
        $this->assertLessThanOrEqual(495, mb_strlen((string) $product->meta_description));
        $this->assertLessThanOrEqual(495, mb_strlen((string) $product->short_description));
    }

    public function test_vendor_order_details_modal_wraps_delivered_credentials_with_copy_buttons(): void
    {
        $this->actingAs($this->admin);

        $longToken = 'https://accounts.google.com/redeem?token='.str_repeat('AbCdEfGh1234567890', 6);
        $secondCred = 'user_alpha@example.com:StrongPass!999 | Recovery: backup@example.com';

        $supplierOrder = SupplierOrder::query()->create([
            'supplier_id' => $this->supplierModel->id,
            'supplier_product_id' => $this->supplierItem->id,
            'request_id' => 'dir-test-copy-01',
            'external_order_id' => 'ext-9001',
            'quantity' => 2,
            'cost' => 4.0,
            'status' => SupplierOrderStatus::Success,
            'response' => [
                'direct_order' => true,
                'delivered_items' => [$longToken, $secondCred],
            ],
        ]);

        Livewire::test(ListSupplierOrders::class)
            ->mountAction(TestAction::make('viewResponse')->table($supplierOrder))
            ->assertMountedActionModalSee('Delivered Credentials / Items')
            ->assertMountedActionModalSee('2 Delivered Items')
            ->assertMountedActionModalSee('Copy All')
            ->assertMountedActionModalSee('Copy JSON')
            ->assertMountedActionModalSeeHtml('smt-cred-pre')
            ->assertMountedActionModalSeeHtml('window.smtCopyFromBox(this)')
            ->assertMountedActionModalSee($longToken)
            ->assertMountedActionModalSee($secondCred)
            ->assertMountedActionModalSeeHtml('fi-in-entry-label fi-sr-only');

        $this->get('/admin/vendor-orders')
            ->assertOk()
            ->assertSee('.smt-cred-pre', false)
            ->assertSee('window.smtCopyFromBox', false);
    }

    public function test_footer_contact_email_and_address_can_be_configured_and_toggled(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(StoreSettings::class)
            ->fillForm([
                'support_whatsapp' => '03001234567',
                'footer_phone_enabled' => true,
                'support_email' => 'hello@sellmetools.com',
                'footer_email_enabled' => true,
                'footer_address' => 'Office 12, Gulberg III, Lahore',
                'footer_address_enabled' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('923001234567', Setting::get('support_whatsapp'));
        $this->assertSame('1', Setting::get('footer_phone_enabled'));
        $this->assertSame('hello@sellmetools.com', Setting::get('support_email'));
        $this->assertSame('1', Setting::get('footer_email_enabled'));
        $this->assertSame('Office 12, Gulberg III, Lahore', Setting::get('footer_address'));
        $this->assertSame('1', Setting::get('footer_address_enabled'));

        $this->get('/')
            ->assertOk()
            ->assertSee('tel:+923001234567', false)
            ->assertSee('+923001234567')
            ->assertSee('mailto:hello@sellmetools.com', false)
            ->assertSee('hello@sellmetools.com')
            ->assertSee('Office 12, Gulberg III, Lahore');

        Livewire::test(StoreSettings::class)
            ->fillForm([
                'footer_phone_enabled' => false,
                'footer_email_enabled' => false,
                'footer_address_enabled' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('0', Setting::get('footer_phone_enabled'));
        $this->assertSame('0', Setting::get('footer_email_enabled'));
        $this->assertSame('0', Setting::get('footer_address_enabled'));

        $response = $this->get('/');
        $response->assertOk();
        $response->assertDontSee('tel:+923001234567', false);
        $response->assertDontSee('mailto:hello@sellmetools.com', false);
        $response->assertDontSee('Office 12, Gulberg III, Lahore');
    }
}
