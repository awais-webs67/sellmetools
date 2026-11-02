<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockStatus;
use App\Exceptions\StoreException;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockItem;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        protected InventoryService $inventory,
    ) {}

    /**
     * Creates an order and reserves stock atomically.
     *
     * The product row is locked (SELECT ... FOR UPDATE) so two customers buying the last
     * unit at the same moment are serialised: the first gets it, the second gets "out of stock".
     *
     * @param  array{email: string, name?: ?string, phone?: ?string, ip?: ?string}  $customer
     */
    public function create(Product $product, int $quantity, array $customer, PaymentMethod $method, ?User $user = null): Order
    {
        if ($quantity < 1) {
            throw new StoreException('Quantity must be at least 1.');
        }

        $order = DB::transaction(function () use ($product, $quantity, $customer, $method, $user) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                throw new StoreException('This product is currently unavailable.');
            }

            if ($quantity > $locked->max_per_order) {
                throw new StoreException("You can buy at most {$locked->max_per_order} per order.");
            }

            if (! $method->is_active) {
                throw new StoreException('Selected payment method is unavailable.');
            }

            // 1) Reserve our own stock first (except for reusable 'instant_link' products where the same link is shared across all orders).
            $isReusableLink = $locked->delivery_type === 'instant_link';

            $ownItems = $isReusableLink
                ? collect()
                : StockItem::query()
                    ->where('product_id', $locked->id)
                    ->where('status', StockStatus::Available)
                    ->orderBy('id')
                    ->limit($quantity)
                    ->lockForUpdate()
                    ->get();

            $fromSupplier = $isReusableLink
                ? ($locked->stock_limit !== null ? $quantity : 0)
                : ($quantity - $ownItems->count());

            // 2) Remaining units must be covered by supplier stock not reserved by others.
            if ($fromSupplier > 0) {
                $stock = $this->inventory->calculate($locked);
                if (! $stock['unlimited'] && ($stock['supplier'] ?? 0) < $fromSupplier) {
                    $available = $ownItems->count() + (int) $stock['supplier'];
                    throw new StoreException($available > 0
                        ? "Only {$available} left in stock."
                        : 'Sorry, this product just went out of stock.');
                }
            }

            $unitPrice = $locked->priceFor($user);
            $total = round($unitPrice * $quantity, 2);
            $normalizedPhone = filled($customer['phone'] ?? null)
                ? format_whatsapp((string) $customer['phone'])
                : ($user?->phone ? format_whatsapp($user->phone) : null);

            $order = Order::create([
                'user_id' => $user?->id,
                'customer_name' => $customer['name'] ?? $user?->name,
                'email' => $customer['email'],
                'phone' => $normalizedPhone ?: null,
                'payment_method_id' => $method->id,
                'status' => OrderStatus::PendingPayment,
                'total' => $total,
                'currency' => 'PKR',
                'usdt_rate' => Setting::usdtRate(),
                'expires_at' => now()->addMinutes((int) Setting::get('order_expiry_minutes', 15)),
                'ip' => $customer['ip'] ?? null,
            ]);

            $item = $order->items()->create([
                'product_id' => $locked->id,
                'product_name' => $locked->name,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => $total,
                'reserved_supplier_qty' => $fromSupplier,
            ]);

            if ($ownItems->isNotEmpty()) {
                StockItem::whereIn('id', $ownItems->pluck('id'))->update([
                    'status' => StockStatus::Reserved,
                    'order_item_id' => $item->id,
                ]);
            }

            $this->inventory->refresh($locked);

            return $order;
        });

        return $order->load('items', 'paymentMethod');
    }

    /** Releases reservations of an unpaid order (or cancels & auto-refunds if paid). */
    public function release(Order $order, OrderStatus $status = OrderStatus::Expired): void
    {
        if (in_array($status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
            $this->cancelAndRefund($order, $status);

            return;
        }

        DB::transaction(function () use ($order, $status) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $order || ! in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::PaymentReview], true)) {
                return;
            }

            foreach ($order->items as $item) {
                StockItem::where('order_item_id', $item->id)
                    ->where('status', StockStatus::Reserved)
                    ->update(['status' => StockStatus::Available, 'order_item_id' => null]);

                $item->update(['reserved_supplier_qty' => 0]);
            }

            $order->update(['status' => $status]);
        });

        $order->refresh()->load('items.product');
        $this->inventory->refreshMany($order->items->pluck('product')->filter());
    }

    /**
     * Cancels or refunds an order in any state, releases reserved stock, and automatically
     * credits the order total back into the customer's Account Wallet if the order was paid
     * (or when $forceWalletRefund is true).
     *
     * @return array{refunded: bool, amount: float, user_email: ?string}
     */
    public function cancelAndRefund(
        Order $order,
        OrderStatus $targetStatus = OrderStatus::Cancelled,
        ?string $note = null,
        ?int $adminId = null,
        bool $forceWalletRefund = false
    ): array {
        $result = [
            'refunded' => false,
            'amount' => 0.0,
            'user_email' => null,
        ];

        DB::transaction(function () use ($order, $targetStatus, $note, $adminId, $forceWalletRefund, &$result) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $wasPaid = $locked->paid_at !== null
                || in_array($locked->status, [OrderStatus::Processing, OrderStatus::NeedsAttention, OrderStatus::Completed], true)
                || $locked->payments()->where('status', PaymentStatus::Approved)->exists()
                || WalletTransaction::query()->where('order_id', $locked->id)->where('type', 'order_payment')->exists();

            $alreadyRefunded = WalletTransaction::query()
                ->where('order_id', $locked->id)
                ->where('type', 'order_refund')
                ->exists();

            $refundAmount = round((float) $locked->total, 2);

            if (($wasPaid || $forceWalletRefund) && ! $alreadyRefunded && $refundAmount > 0) {
                $user = $locked->user ?: User::query()->where('email', $locked->email)->first();

                if ($user) {
                    $txNote = filled($note)
                        ? "Refund for Order #{$locked->number}: {$note}"
                        : "Automatic refund for Order #{$locked->number}";

                    $user->adjustWallet($refundAmount, 'order_refund', $txNote, $adminId, $locked->id);

                    if (! $locked->user_id) {
                        $locked->user_id = $user->id;
                    }

                    $result['refunded'] = true;
                    $result['amount'] = $refundAmount;
                    $result['user_email'] = $user->email;
                }
            }

            foreach ($locked->items as $item) {
                StockItem::where('order_item_id', $item->id)
                    ->where('status', StockStatus::Reserved)
                    ->update(['status' => StockStatus::Available, 'order_item_id' => null]);

                $item->update(['reserved_supplier_qty' => 0]);
            }

            // Reject any pending payments so they don't stay stuck in pending review
            $locked->payments()
                ->where('status', PaymentStatus::Pending)
                ->update([
                    'status' => PaymentStatus::Rejected,
                    'reject_reason' => $note ?: 'Order cancelled by Admin',
                    'reviewed_by' => $adminId,
                    'reviewed_at' => now(),
                ]);

            $noteParts = array_filter([
                $locked->admin_note,
                $result['refunded'] ? "Auto-refunded Rs {$result['amount']} to Wallet ({$result['user_email']})" : null,
                filled($note) ? "Note: {$note}" : null,
            ]);

            $locked->update([
                'user_id' => $locked->user_id,
                'status' => $targetStatus,
                'admin_note' => $noteParts !== [] ? implode(' | ', $noteParts) : $locked->admin_note,
            ]);
        });

        $order->refresh()->load('items.product');
        $this->inventory->refreshMany($order->items->pluck('product')->filter());

        return $result;
    }

    /** Expires unpaid orders past their deadline. Returns number expired. */
    public function expireStale(): int
    {
        $count = 0;

        Order::query()
            ->where('status', OrderStatus::PendingPayment)
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->each(function (Order $order) use (&$count) {
                $this->release($order, OrderStatus::Expired);
                $count++;
            });

        return $count;
    }
}
