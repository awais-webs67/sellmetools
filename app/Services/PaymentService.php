<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\StoreException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\TelegramNotifier as T;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class PaymentService
{
    public function __construct(
        protected FulfillmentService $fulfillment,
        protected T $telegram,
    ) {}

    /** Customer submits a transaction ID for manual / semi-automatic verification. */
    public function submit(Order $order, string $transactionId, ?string $senderNumber = null, ?UploadedFile $screenshot = null): Payment
    {
        $transactionId = strtoupper(trim($transactionId));

        $payment = DB::transaction(function () use ($order, $transactionId, $senderNumber, $screenshot) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->isExpired()) {
                throw new StoreException('This order has expired. Please place a new order.');
            }

            if (! $order->canSubmitPayment()) {
                throw new StoreException('Payment has already been submitted for this order.');
            }

            $used = Payment::query()
                ->where('payment_method_id', $order->payment_method_id)
                ->where('transaction_id', $transactionId)
                ->exists();

            if ($used) {
                throw new StoreException('This transaction ID has already been used.');
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

        $this->telegram->notifyAdmin(
            "💰 <b>New payment to verify</b>\n".
            "Order: <b>{$order->number}</b>\n".
            $order->items->map(fn ($i) => '• '.T::e($i->product_name)." × {$i->quantity}")->implode("\n")."\n".
            'Method: '.T::e($order->paymentMethod?->name)."\n".
            "Amount: <b>{$payment->currency} {$payment->amount}</b>\n".
            'TID: <code>'.T::e($payment->transaction_id).'</code>'.
            ($payment->sender_number ? "\nSender: ".T::e($payment->sender_number) : ''),
            [
                [
                    ['text' => '✅ Approve & Auto-Deliver', 'url' => $approveUrl],
                    ['text' => '❌ Reject TID', 'url' => $rejectUrl],
                ],
            ]
        );

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
