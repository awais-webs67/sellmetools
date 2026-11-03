<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesStore;
use Tests\TestCase;

class AccountAndSearchTest extends TestCase
{
    use CreatesStore, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
    }

    public function test_search_suggestions_match_as_you_type(): void
    {
        $this->listing([], ['name' => 'ChatGPT Plus 1 Month']);
        $this->listing([], ['name' => 'Canva Pro 1 Year']);

        $this->getJson(route('search.suggest', ['q' => 'chat plus']))
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'ChatGPT Plus 1 Month');

        $this->getJson(route('search.suggest', ['q' => 'c']))
            ->assertOk()
            ->assertJsonCount(2, 'products');

        $this->getJson(route('search.suggest', ['q' => '']))
            ->assertOk()
            ->assertJsonCount(0, 'products');
    }

    public function test_order_history_filters_and_groups_by_date(): void
    {
        $user = User::factory()->create();
        $method = $this->paymentMethod();

        $recent = $this->orderFor($user, $method->id, now());
        $old = $this->orderFor($user, $method->id, now()->subDays(40));

        $this->actingAs($user)->get(route('account.orders'))
            ->assertOk()
            ->assertSee($recent->number)
            ->assertSee($old->number)
            ->assertSee('Today');

        $this->actingAs($user)->get(route('account.orders', ['range' => '30d']))
            ->assertOk()
            ->assertSee($recent->number)
            ->assertDontSee($old->number);

        $this->actingAs($user)->get(route('account.orders', [
            'range' => 'custom',
            'from' => now()->subDays(45)->toDateString(),
            'to' => now()->subDays(35)->toDateString(),
        ]))
            ->assertOk()
            ->assertSee($old->number)
            ->assertDontSee($recent->number);
    }

    public function test_shop_page_filters_and_newly_created_categories_are_visible(): void
    {
        $newCategory = Category::create([
            'name' => 'Streaming VIP',
            'slug' => 'streaming-vip',
            'icon' => '🎬',
            'is_active' => true,
        ]);

        $cheap = $this->listing([], ['name' => 'Canva Pro 1 Month', 'fixed_price' => 300, 'price' => 300]);
        $dear = $this->listing([], ['name' => 'ChatGPT Plus Private', 'fixed_price' => 3500, 'price' => 3500]);

        $this->get(route('shop'))
            ->assertOk()
            ->assertSee('Streaming VIP')
            ->assertSee($cheap->name)
            ->assertSee($dear->name);

        $this->get(route('shop', ['sort' => 'price_asc', 'q' => 'Canva']))
            ->assertOk()
            ->assertSee($cheap->name)
            ->assertDontSee($dear->name);

        $this->get(route('categories.show', $newCategory))->assertOk();
    }

    public function test_about_and_contact_form_submission_work(): void
    {
        $this->get(route('pages.show', 'about'))
            ->assertOk()
            ->assertSee('Who We Are');

        $this->post(route('pages.contact.submit'), [
            'name' => 'Ali Khan',
            'email' => 'ali@example.com',
            'phone' => '03001234567',
            'order_number' => 'SMT-100200',
            'subject' => 'Order & Payment Verification',
            'message' => 'Please verify my Easypaisa payment for Order SMT-100200.',
        ])
            ->assertRedirect(route('pages.show', 'contact'))
            ->assertSessionHas('contact_sent');
    }

    private function orderFor(User $user, int $methodId, $createdAt): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'customer_name' => $user->name,
            'email' => $user->email,
            'phone' => '03000000000',
            'payment_method_id' => $methodId,
            'status' => OrderStatus::Completed,
            'total' => 500,
            'currency' => 'PKR',
            'usdt_rate' => 300,
        ]);
        $order->forceFill(['created_at' => $createdAt])->save();

        return $order->refresh();
    }
}
