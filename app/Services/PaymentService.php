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

        $order->refresh()->load(['items', 'paymentMethod']);
        $this->telegram->notifyAdmin(
            "👛 <b>Instant Wallet Payment Confirmed!</b>\n".
            "Order: <b>{$order->number}</b>\n".
            $order->items->map(fn ($i) => '• '.T::e($i->product_name)." × {$i->quantity}")->implode("\n")."\n".
            "Paid: <b>PKR {$payment->amount}</b> (Wallet Balance)\n".
            'Customer: '.T::e($user->email).($user->is_reseller ? ' <b>[VIP RESELLER]</b>' : '')
        );

        return $this->fulfillment->fulfill($order);
    }

    /** Customer submits a transaction ID for manual / semi-automatic verification. */
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

            $order->update(['status' => OrderStatus::PaymentReview]);

            return $payment;
        });

        $order->refresh()->load('items', 'paymentMethod');

        // Attempt instant automatic verification (SMS webhook match or Binance API)
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

        // Telegram 1-Tap Buttons use in-chat callback_data so tapping NEVER opens the browser!
        $this->telegram->notifyAdmin(
            "💰 <b>New Payment to Verify</b>\n".
            "Order: <b>{$order->number}</b>\n".
            $order->items->map(fn ($i) => '• '.T::e($i->product_name)." × {$i->quantity}")->implode("\n")."\n".
            'Method: '.T::e($order->paymentMethod?->name)."\n".
            "Amount: <b>{$payment->currency} {$payment->amount}</b>\n".
            'TID: <code>'.T::e($payment->transaction_id).'</code>'.
            ($payment->sender_number ? "\nSender: ".T::e($payment->sender_number) : '').
            "\nCustomer: ".T::e($order->email).($order->phone ? ' ('.T::e($order->phone).')' : ''),
            [
                [
                    ['text' => '✅ Approve & Deliver', 'callback_data' => "approve:{$payment->id}"],
                    ['text' => '❌ Decline Payment', 'callback_data' => "reject:{$payment->id}"],
                ],
            ]
        );

        app(WhatsAppNotifier::class)->notifyAdminPayment($order, $payment, $approveUrl, $rejectUrl);

        return $payment;
    }

    /** Approves a payment and immediately starts delivery. */
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

            if (! in_array($order->status, [OrderStatus::PaymentReview, OrderStatus::PendingPayment, OrderStatus::Expired], true)) {
                throw new StoreException("Order is {$order->status->label()} and cannot be paid.");
            }

            $order->update(['status' => OrderStatus::Processing, 'paid_at' => now()]);

            return $order;
        });

        $order->load(['items', 'paymentMethod']);
        $this->telegram->notifyAdmin(
            "💳 <b>Payment Confirmed &amp; Order Paid!</b>\n".
            "Order: <b>{$order->number}</b>\n".
            $order->items->map(fn ($i) => '• '.T::e($i->product_name)." × {$i->quantity}")->implode("\n")."\n".
            'Gateway: '.T::e($order->paymentMethod?->name ?? 'Online')."\n".
            "Paid: <b>{$payment->currency} {$payment->amount}</b> (Rs {$order->total})\n".
            'TID: <code>'.T::e($payment->transaction_id)."</code>\n".
            'Customer: '.T::e($order->email)
        );

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
            if ($order->status === OrderStatus::PaymentReview) {
                $order->update([
                    'status' => OrderStatus::PendingPayment,
                    'expires_at' => now()->addMinutes((int) Setting::get('order_expiry_minutes', 15)),
                ]);
            }
        });
    }
}
