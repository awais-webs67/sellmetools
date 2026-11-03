<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\StoreException;
use App\Models\IncomingPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\TelegramNotifier as T;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class PaymentService
{
    public function __construct(
        protected FulfillmentService $fulfillment,
        protected T $telegram,
    ) {}

    /**
     * Normalizes a Transaction ID (TID / Order ID / TxHash) so users cannot bypass
     * duplicate checks by adding spaces, dashes, or "TID:" prefixes.
     */
    public static function normalizeTid(string $raw): string
    {
        $clean = trim($raw);
        $clean = preg_replace('/^(?:tid|trx\s*id|tx\s*id|trans(?:action)?\s*id|order\s*id|ref(?:\s*no|\s*id)?)\s*[:#]\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/[^A-Z0-9]/', '', strtoupper($clean)) ?? '';

        return $clean;
    }

    /**
     * Pays for an order immediately using the user's Account Wallet Balance and auto-delivers credentials.
     */
    public function payWithWallet(Order $order, User $user): Order
    {
        $payment = DB::transaction(function () use ($order, $user) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::PaymentReview], true)) {
                throw new StoreException('Order cannot be paid via wallet.');
            }

            $amount = round((float) $order->total, 2);

            try {
                $tx = $user->adjustWallet(
                    -$amount,
                    'order_payment',
                    "Paid for Order #{$order->number}",
                    null,
                    $order->id
                );
            } catch (RuntimeException) {
                throw new StoreException('Insufficient wallet balance to pay for this order.');
            }

            $payment = $order->payments()->create([
                'payment_method_id' => $order->payment_method_id,
                'amount' => $amount,
                'currency' => 'PKR',
                'transaction_id' => 'WALLET-'.$order->number.'-'.$tx->id,
                'sender_number' => $user->phone ?: $user->email,
                'status' => PaymentStatus::Approved,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            $order->update(['status' => OrderStatus::Processing, 'paid_at' => now()]);

            return $payment;
        });

        $order->refresh()->load(['items.product.activeSources.supplier', 'paymentMethod']);
        $notifyWallet = function () use ($order, $payment, $user): void {
            $this->telegram->notifyAdmin(
                "👛✨ <b>INSTANT WALLET PAYMENT CONFIRMED!</b>\n".
                "━━━━━━━━━━━━━━━━━━\n".
                "🧾 <b>Order:</b> <code>{$order->number}</code>\n".
                $order->items->map(fn ($i) => '📦 '.T::e($i->product_name)." × {$i->quantity}")->implode("\n")."\n".
                '🏭 <b>Supplier:</b> '.T::e($order->adminSupplierSummary(true))."\n".
                "💵 <b>Paid:</b> <b>PKR {$payment->amount}</b> (Wallet Balance)\n".
                '👤 <b>Customer:</b> '.T::e($user->email).($user->is_reseller ? ' 🌟 <b>[VIP RESELLER]</b>' : '')
            );
        };

        if (app()->runningUnitTests()) {
            $notifyWallet();
        } else {
            defer($notifyWallet);
        }

        return $this->fulfillment->fulfill($order);
    }

    /**
     * Instantly approves and fulfills a 0-PKR free order without requiring any payment or Transaction ID.
     */
    public function completeFreeOrder(Order $order): Order
    {
        DB::transaction(function () use ($order) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ((float) $order->total > 0) {
                throw new StoreException('Only free (0 PKR) orders can be claimed without payment.');
            }

            if (! in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::PaymentReview], true)) {
                return;
            }

            $order->payments()->create([
                'payment_method_id' => $order->payment_method_id,
                'amount' => 0,
                'currency' => 'PKR',
                'transaction_id' => 'FREE-'.$order->number,
                'sender_number' => $order->phone ?: $order->email,
                'status' => PaymentStatus::Approved,
                'reviewed_by' => $order->user_id,
                'reviewed_at' => now(),
            ]);

            $order->update(['status' => OrderStatus::Processing, 'paid_at' => now()]);
        });

        $order->refresh()->load(['items.product.activeSources.supplier', 'paymentMethod']);
        $notifyFree = function () use ($order): void {
            $this->telegram->notifyAdmin(
                "🎁✨ <b>FREE PRODUCT CLAIMED!</b>\n".
                "━━━━━━━━━━━━━━━━━━\n".
                "🧾 <b>Order:</b> <code>{$order->number}</code>\n".
                $order->items->map(fn ($i) => '📦 '.T::e($i->product_name)." × {$i->quantity}")->implode("\n")."\n".
                '🏭 <b>Supplier:</b> '.T::e($order->adminSupplierSummary(true))."\n".
                "💵 <b>Price:</b> <b>FREE (PKR 0)</b>\n".
                '👤 <b>Customer:</b> '.T::e($order->email).($order->phone ? ' (+'.T::e($order->phone).')' : '')
            );
        };

        if (app()->runningUnitTests()) {
            $notifyFree();
        } else {
            defer($notifyFree);
        }

        return $this->fulfillment->fulfill($order);
    }

    /** Customer submits a transaction ID for verification. */
    public function submit(Order $order, string $transactionId, ?string $senderNumber = null, ?UploadedFile $screenshot = null): Payment
    {
        $transactionId = self::normalizeTid($transactionId);
        $senderNumber = filled($senderNumber) ? (format_whatsapp($senderNumber) ?: trim($senderNumber)) : null;

        if (strlen($transactionId) < 3 || in_array($transactionId, ['PAID', 'DONE', 'TEST', 'NONE', 'NULL', '00000', '11111'], true)) {
            throw new StoreException('Please enter a valid, real Transaction ID (TID / Order ID) from your payment receipt.');
        }

        $payment = DB::transaction(function () use ($order, $transactionId, $senderNumber, $screenshot) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->isExpired()) {
                throw new StoreException('This order has expired. Please place a new order.');
            }

            if (! $order->canSubmitPayment()) {
                throw new StoreException('Payment has already been submitted for this order.');
            }

            // Global duplicate TID check across ALL payment methods & orders
            $usedInPayments = Payment::query()
                ->where('transaction_id', $transactionId)
                ->lockForUpdate()
                ->exists();

            $usedInIncoming = IncomingPayment::query()
                ->where('transaction_id', $transactionId)
                ->whereNotNull('matched_payment_id')
                ->exists();

            if ($usedInPayments || $usedInIncoming) {
                throw new StoreException('This Transaction ID (TID) has already been used. Duplicate TIDs are strictly blocked.');
            }

            $method = $order->paymentMethod;

            $payment = $order->payments()->create([
                'payment_method_id' => $order->payment_method_id,
                'amount' => $method ? $method->amountFor($order) : $order->total,
                'currency' => $method?->currency ?? 'PKR',
                'transaction_id' => $transactionId,
                'sender_number' => $senderNumber,
                'screenshot' => $screenshot?->store('payments', 'local'),
                'status' => PaymentStatus::Pending,
            ]);

            // Reset the 15-minute verification window so the customer sees the 15-min verification countdown at 85%!
            $order->update([
                'status' => OrderStatus::PaymentReview,
                'expires_at' => now()->addMinutes((int) Setting::get('order_expiry_minutes', 15)),
            ]);

            return $payment;
        });

        $order->refresh()->load('items', 'paymentMethod');

        // Attempt instant automatic verification (if gateway is in Auto Mode / Binance API / SMS Webhook)
        if (app(AutoPaymentVerifier::class)->tryAutoVerify($payment)) {
            return $payment->refresh();
        }

        $approveUrl = URL::signedRoute('webhooks.payment.quick-action', [
            'payment' => $payment->id,
            'action' => 'approve',
        ]);
        $rejectUrl = URL::signedRoute('webhooks.payment.quick-action', [
            'payment' => $payment->id,
            'action' => 'reject',
        ]);

        // Manual Mode (85%): Notify Admin on Telegram (with In-Chat 1-Tap buttons) + ntfy App + WhatsApp
        $notifySubmitted = function () use ($order, $payment, $approveUrl, $rejectUrl): void {
            $this->telegram->notifyAdmin(
                "🔔💰 <b>NEW PAYMENT TO VERIFY (85% Hold)</b>\n".
                "━━━━━━━━━━━━━━━━━━\n".
                "🧾 <b>Order:</b> <code>{$order->number}</code>\n".
                $order->items->map(fn ($i) => '📦 '.T::e($i->product_name)." × {$i->quantity}")->implode("\n")."\n".
                '🏭 <b>Supplier:</b> '.T::e($order->adminSupplierSummary(true))."\n".
                '💳 <b>Method:</b> '.T::e($order->paymentMethod?->name)."\n".
                "💵 <b>Amount:</b> <b>{$payment->currency} {$payment->amount}</b> (Rs {$order->total})\n".
                '🔑 <b>TID:</b> <code>'.T::e($payment->transaction_id).'</code>'.
                ($payment->sender_number ? "\n📱 <b>Sender:</b> ".T::e($payment->sender_number) : '').
                (filled($order->customer_input) ? "\n📝 <b>Activation Data:</b> <code>".T::e($order->customer_input).'</code>' : '').
                "\n👤 <b>Customer:</b> ".T::e($order->email).($order->phone ? ' (+'.T::e($order->phone).')' : '').
                "\n⏳ <i>Customer is waiting at 85%: Tap below to Approve or Decline:</i>",
                [
                    [
                        ['text' => '✅ Approve & Deliver', 'callback_data' => "approve:{$payment->id}"],
                        ['text' => '❌ Decline Payment', 'callback_data' => "reject:{$payment->id}"],
                    ],
                ]
            );

            app(WhatsAppNotifier::class)->notifyAdminPayment($order, $payment, $approveUrl, $rejectUrl);
        };

        if (app()->runningUnitTests()) {
            $notifySubmitted();
        } else {
            defer($notifySubmitted);
        }

        return $payment;
    }

    /**
     * Approves a payment and immediately starts delivery.
     * Works even if the 15-minute verification timer has already elapsed!
     */
    public function approve(Payment $payment, ?User $admin = null): Order
    {
        $order = DB::transaction(function () use ($payment, $admin) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== PaymentStatus::Pending) {
                throw new StoreException('Payment was already reviewed.');
            }

            $payment->update([
                'status' => PaymentStatus::Approved,
                'reviewed_by' => $admin?->id,
                'reviewed_at' => now(),
            ]);

            $order = $payment->order()->lockForUpdate()->first();

            if (! in_array($order->status, [OrderStatus::PaymentReview, OrderStatus::PendingPayment, OrderStatus::Processing, OrderStatus::NeedsAttention, OrderStatus::Expired], true)) {
                throw new StoreException("Order is {$order->status->label()} and cannot be paid.");
            }

            // Ensure no other payment record on the same order stays stuck in Pending status
            $order->payments()
                ->where('status', PaymentStatus::Pending)
                ->update([
                    'status' => PaymentStatus::Approved,
                    'reviewed_by' => $admin?->id,
                    'reviewed_at' => now(),
                ]);

            $order->update(['status' => OrderStatus::Processing, 'paid_at' => $order->paid_at ?? now()]);

            return $order;
        });

        $order->load(['items.product.activeSources.supplier', 'paymentMethod']);
        $isAuto = $order->paymentMethod?->isAuto() ?? false;

        $notifyApproval = function () use ($order, $payment, $isAuto): void {
            $this->telegram->notifyAdmin(
                ($isAuto ? '⚡✅ <b>AUTO-PAYMENT VERIFIED!</b>' : '💳✅ <b>PAYMENT APPROVED BY ADMIN!</b>')."\n".
                "━━━━━━━━━━━━━━━━━━\n".
                "🧾 <b>Order:</b> <code>{$order->number}</code>\n".
                $order->items->map(fn ($i) => '📦 '.T::e($i->product_name)." × {$i->quantity}")->implode("\n")."\n".
                '🏭 <b>Supplier:</b> '.T::e($order->adminSupplierSummary(true))."\n".
                '💳 <b>Gateway:</b> '.T::e($order->paymentMethod?->name ?? 'Online')."\n".
                "💵 <b>Paid:</b> <b>{$payment->currency} {$payment->amount}</b> (Rs {$order->total})\n".
                '🔑 <b>TID:</b> <code>'.T::e($payment->transaction_id)."</code>\n".
                '👤 <b>Customer:</b> '.T::e($order->email)
            );
        };

        if (app()->runningUnitTests()) {
            $notifyApproval();
        } else {
            defer($notifyApproval);
        }

        return $this->fulfillment->fulfill($order);
    }

    /** Rejects a payment; the customer gets a fresh window to submit a correct TID. */
    public function reject(Payment $payment, string $reason, ?User $admin = null): void
    {
        DB::transaction(function () use ($payment, $reason, $admin) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== PaymentStatus::Pending) {
                throw new StoreException('Payment was already reviewed.');
            }

            $payment->update([
                'status' => PaymentStatus::Rejected,
                'reject_reason' => $reason,
                'reviewed_by' => $admin?->id,
                'reviewed_at' => now(),
            ]);

            $order = $payment->order;
            if (in_array($order->status, [OrderStatus::PaymentReview, OrderStatus::NeedsAttention], true)) {
                $order->update([
                    'status' => OrderStatus::PendingPayment,
                    'expires_at' => now()->addMinutes((int) Setting::get('order_expiry_minutes', 15)),
                ]);
            }
        });
    }

    /**
     * Self-healing sync: ensures any Payment record whose Order was already Completed / Processing / NeedsAttention
     * via 1-Tap or Auto-Verify is marked Approved (never left stuck in Pending in the Admin Panel).
     */
    public static function syncOrphanedPendingPayments(): void
    {
        static $synced = false;
        if ($synced) {
            return;
        }
        $synced = true;

        try {
            // Clean up any orphaned child records if orders were manually deleted in phpMyAdmin
            \App\Models\OrderItem::query()->whereDoesntHave('order')->delete();
            Payment::query()->whereDoesntHave('order')->delete();
            \App\Models\StockItem::query()
                ->where('status', \App\Enums\StockStatus::Reserved)
                ->whereNotNull('order_item_id')
                ->whereDoesntHave('orderItem')
                ->update([
                    'status' => \App\Enums\StockStatus::Available,
                    'order_item_id' => null,
                ]);

            Payment::query()
                ->where('status', PaymentStatus::Pending)
                ->whereHas('order', fn ($q) => $q->whereIn('status', [
                    OrderStatus::Completed,
                    OrderStatus::Processing,
                    OrderStatus::NeedsAttention,
                ])->orWhereNotNull('paid_at'))
                ->update([
                    'status' => PaymentStatus::Approved,
                    'reviewed_at' => now(),
                ]);

            Payment::query()
                ->where('status', PaymentStatus::Pending)
                ->whereHas('order', fn ($q) => $q->whereIn('status', [
                    OrderStatus::Cancelled,
                    OrderStatus::Refunded,
                ]))
                ->update([
                    'status' => PaymentStatus::Rejected,
                    'reject_reason' => 'Order cancelled/refunded',
                    'reviewed_at' => now(),
                ]);
        } catch ( RuntimeException|\Throwable) {
        }
    }
}
