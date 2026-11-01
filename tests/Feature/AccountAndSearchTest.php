<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
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
