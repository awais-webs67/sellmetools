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
use App\Models\Voucher;
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

            // Check real available stock (own stock + supplier stock); stock is only deducted once paid/delivered.
            $stock = $this->inventory->calculate($locked);
            if (! $stock['unlimited'] && $stock['qty'] < $quantity) {
                $available = (int) $stock['qty'];
                throw new StoreException($available > 0
                    ? "Only {$available} left in stock."
                    : 'Sorry, this product just went out of stock.');
            }

            $unitPrice = $locked->priceFor($user);
            $subtotal = round($unitPrice * $quantity, 2);
            $discountAmount = 0.0;
            $voucher = null;

            $rawVoucherCode = strtoupper(trim((string) ($customer['voucher_code'] ?? '')));
            if ($rawVoucherCode !== '' && $subtotal > 0) {
                Voucher::ensureSchemaExists();
                /** @var Voucher|null $voucher */
                $voucher = Voucher::query()->where('code', $rawVoucherCode)->lockForUpdate()->first();
                if (! $voucher) {
                    throw new StoreException("Invalid voucher code: {$rawVoucherCode}");
                }
                $discountAmount = $voucher->validateAndCalculateDiscount($locked, $subtotal, $customer['email'] ?? null, $user?->id);
            }

            $total = max(0.0, round($subtotal - $discountAmount, 2));
            $effectiveUnitPrice = $quantity > 0 ? round($total / $quantity, 2) : $unitPrice;
            $normalizedPhone = filled($customer['phone'] ?? null)
                ? format_whatsapp((string) $customer['phone'])
                : ($user?->phone ? format_whatsapp($user->phone) : null);

            $order = Order::create([
                'user_id' => $user?->id,
                'customer_name' => $customer['name'] ?? $user?->name,
                'email' => $customer['email'],
                'phone' => $normalizedPhone ?: null,
                'payment_method_id' => $method->id,
                'voucher_id' => $voucher?->id,
                'voucher_code' => $voucher?->code,
                'discount_amount' => $discountAmount,
                'status' => OrderStatus::PendingPayment,
                'total' => $total,
                'currency' => 'PKR',
                'usdt_rate' => Setting::usdtRate(),
                'expires_at' => now()->addMinutes((int) Setting::get('order_expiry_minutes', 15)),
                'ip' => $customer['ip'] ?? null,
            ]);

            if ($voucher) {
                $voucher->increment('used_count');
            }

            $order->items()->create([
                'product_id' => $locked->id,
                'product_name' => $locked->name,
                'unit_price' => $effectiveUnitPrice,
                'quantity' => $quantity,
                'line_total' => $total,
                'reserved_supplier_qty' => 0,
            ]);

            if ($locked->available_stock !== $stock['qty'] || $locked->stock_unlimited !== $stock['unlimited']) {
                $locked->forceFill([
                    'available_stock' => $stock['qty'],
                    'stock_unlimited' => $stock['unlimited'],
                ])->saveQuietly();
            }

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

            if ($order->voucher_id) {
                Voucher::query()->whereKey($order->voucher_id)->where('used_count', '>', 0)->decrement('used_count');
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
        bool $forceWalletRefund = false,
        bool $creditWallet = true
    ): array {
        $result = [
            'refunded' => false,
            'manual_refund' => ! $creditWallet,
            'amount' => 0.0,
            'user_email' => null,
        ];

        DB::transaction(function () use ($order, $targetStatus, $note, $adminId, $forceWalletRefund, $creditWallet, &$result) {
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

            if ($creditWallet && ($wasPaid || $forceWalletRefund) && ! $alreadyRefunded && $refundAmount > 0) {
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
            } elseif (! $creditWallet) {
                $result['amount'] = $refundAmount;
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
                (! $creditWallet) ? "Manual external refund Rs {$refundAmount} (not credited to Wallet)" : null,
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

    /**
     * Creates an already-paid, completed Manual Order recorded directly in the Sales & Profit Ledger,
     * optionally dispatching the standard Order Delivered email (and WhatsApp receipt if configured).
     *
     * @param  array{
     *     product_id?: ?int,
     *     product_name: string,
     *     quantity?: int,
     *     paid_price: float|int|string,
     *     cost?: float|int|string,
     *     payment_method_id?: ?int,
     *     delivered_credentials?: ?string,
     *     customer_email?: ?string,
     *     customer_phone?: ?string,
     *     customer_name?: ?string,
     *     admin_note?: ?string,
     *     send_email?: bool
     * }  $payload
     * @return array{order: Order, email_sent: bool, has_valid_email: bool}
     */
    public function createManualCompletedOrder(array $payload): array
    {
        Product::ensureFreeAndStockColumnsExist();
        Voucher::ensureSchemaExists();

        $quantity = max(1, (int) ($payload['quantity'] ?? 1));
        $paidPrice = round(max(0.0, (float) ($payload['paid_price'] ?? 0)), 2);
        $cost = round(max(0.0, (float) ($payload['cost'] ?? 0)), 2);
        $unitPrice = round($paidPrice / $quantity, 2);

        $productId = ! empty($payload['product_id']) ? (int) $payload['product_id'] : null;
        $product = $productId ? Product::find($productId) : null;
        $productName = trim((string) ($payload['product_name'] ?? ''));
        if ($productName === '' && $product) {
            $productName = $product->name;
        }
        if ($productName === '') {
            throw new StoreException('Product name is required.');
        }

        $rawEmail = strtolower(trim((string) ($payload['customer_email'] ?? '')));
        $hasValidEmail = $rawEmail !== '' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL) !== false;

        $customerName = trim((string) ($payload['customer_name'] ?? '')) ?: null;
        $rawPhone = trim((string) ($payload['customer_phone'] ?? ''));
        $normalizedPhone = $rawPhone !== '' ? (format_whatsapp($rawPhone) ?: $rawPhone) : null;

        $storedEmail = $hasValidEmail
            ? $rawEmail
            : ($customerName
                ? "{$customerName} (Manual)"
                : ($normalizedPhone ? "WhatsApp +{$normalizedPhone}" : 'Manual Customer'));

        $matchedUser = $hasValidEmail ? User::query()->where('email', $rawEmail)->first() : null;
        $paymentMethodId = ! empty($payload['payment_method_id']) ? (int) $payload['payment_method_id'] : null;
        $credentials = trim((string) ($payload['delivered_credentials'] ?? ''));
        $adminNote = trim((string) ($payload['admin_note'] ?? ''));
        $sendEmail = (bool) ($payload['send_email'] ?? true);

        $order = DB::transaction(function () use (
            $matchedUser,
            $customerName,
            $storedEmail,
            $normalizedPhone,
            $paymentMethodId,
            $paidPrice,
            $cost,
            $adminNote,
            $product,
            $productName,
            $unitPrice,
            $quantity,
            $credentials
        ): Order {
            $now = now();

            $order = Order::create([
                'user_id' => $matchedUser?->id,
                'customer_name' => $customerName ?? $matchedUser?->name,
                'email' => $storedEmail,
                'phone' => $normalizedPhone,
                'payment_method_id' => $paymentMethodId,
                'status' => OrderStatus::Completed,
                'total' => $paidPrice,
                'total_cost' => $cost,
                'currency' => 'PKR',
                'usdt_rate' => Setting::usdtRate(),
                'paid_at' => $now,
                'completed_at' => $now,
                'admin_note' => $adminNote !== '' ? "Manual Order · {$adminNote}" : 'Manual Order created by Admin',
            ]);

            $item = $order->items()->create([
                'product_id' => $product?->id,
                'product_name' => $productName,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => $paidPrice,
                'reserved_supplier_qty' => 0,
                'delivered_qty' => $quantity,
                'cost' => $cost,
                'status' => 'delivered',
            ]);

            $deliveryText = $credentials !== ''
                ? $credentials
                : "Order fulfilled and activated (#{$order->number}).";

            $item->deliveries()->create([
                'content' => $deliveryText,
                'source' => 'manual',
            ]);

            if ($product) {
                $product->increment('sold_count', $quantity);
            }

            return $order;
        });

        $order->load(['items.deliveries', 'paymentMethod']);

        $emailSent = false;
        if ($hasValidEmail && $sendEmail) {
            $emailSent = app(EmailNotifier::class)->sendOrderDelivered($order, immediate: true);
        }

        if ($normalizedPhone !== null) {
            $notifyWa = function () use ($order): void {
                app(WhatsAppNotifier::class)->notifyOrderDelivered($order);
            };
            if (app()->runningUnitTests()) {
                $notifyWa();
            } else {
                defer($notifyWa);
            }
        }

        return [
            'order' => $order,
            'email_sent' => $emailSent,
            'has_valid_email' => $hasValidEmail,
        ];
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
