<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\AutoPaymentVerifier;
use App\Services\FulfillmentService;
use App\Services\PaymentService;
use App\Services\TelegramNotifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Throwable;

/**
 * Endpoints for automatic & 1-Tap payment verification:
 *  - POST /api/webhooks/payment-sms         (Android SMS/Notification Forwarder)
 *  - POST /api/webhooks/telegram            (Telegram Bot In-Chat 1-Tap Callback Buttons)
 *  - GET|POST /webhooks/payment/{payment}/{action} (Signed 1-Tap Approve / Decline from Auto-WhatsApp / ntfy)
 *  - GET|POST /quick-order/{order:number}   (1-Tap Approve / Decline from WhatsApp Receipt Message)
 */
class PaymentWebhookController extends Controller
{
    public function sms(Request $request, AutoPaymentVerifier $verifier): JsonResponse
    {
        $secret = trim((string) Setting::get('sms_webhook_secret'));
        $provided = (string) ($request->header('X-Webhook-Secret') ?? $request->input('secret') ?? $request->query('secret') ?? '');

        if ($secret === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['ok' => false, 'error' => 'Invalid webhook secret'], 401);
        }

        $tid = $request->input('tid') ?? $request->input('transaction_id');
        $amount = $request->input('amount');
        $provider = (string) ($request->input('provider') ?? 'sms');
        $sender = $request->input('sender') ?? $request->input('from');
        $raw = (string) ($request->input('message') ?? $request->input('text') ?? $request->input('body') ?? '');

        if ((! $tid || ! $amount) && $raw !== '') {
            $parsed = $verifier->parseSms($raw, is_string($sender) ? $sender : null);
            if (! $parsed) {
                return response()->json(['ok' => false, 'error' => 'Could not parse TID or amount from message'], 422);
            }
            $tid = $parsed['tid'];
            $amount = $parsed['amount'];
            $provider = $parsed['provider'];
            $sender = $parsed['sender'] ?? $sender;
        }

        if (! $tid || ! is_numeric($amount) || (float) $amount <= 0) {
            return response()->json(['ok' => false, 'error' => 'Missing tid or valid amount'], 422);
        }

        $incoming = $verifier->recordIncoming(
            (string) $tid,
            (float) $amount,
            $provider,
            (string) ($request->input('currency') ?? 'PKR'),
            is_string($sender) ? $sender : null,
            $raw ?: null
        );

        return response()->json([
            'ok' => true,
            'tid' => $incoming->transaction_id,
            'amount' => (float) $incoming->amount,
            'provider' => $incoming->provider,
            'matched_payment_id' => $incoming->matched_payment_id,
        ]);
    }

    /**
     * Handles Telegram Bot inline callback_query buttons (`approve:{id}` / `reject:{id}`)
     * right inside the Telegram chat without opening a browser!
     */
    public function telegram(Request $request, PaymentService $payments, TelegramNotifier $telegram, FulfillmentService $fulfillment): JsonResponse
    {
        $callback = $request->input('callback_query');
        if (! is_array($callback)) {
            return response()->json(['ok' => true]);
        }

        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $chatId = (string) ($callback['message']['chat']['id'] ?? '');
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $adminChatId = trim((string) Setting::get('telegram_admin_chat_id', ''));

        if ($adminChatId === '' || $chatId !== $adminChatId) {
            $telegram->answerCallback($callbackId, 'Unauthorized chat ID.');

            return response()->json(['ok' => false], 403);
        }

        if (! preg_match('/^(approve|reject):(\d+)$/', $data, $m)) {
            return response()->json(['ok' => true]);
        }

        [, $action, $paymentId] = $m;
        $payment = Payment::with('order.items')->find((int) $paymentId);

        if (! $payment) {
            $telegram->answerCallback($callbackId, 'Payment record not found.');

            return response()->json(['ok' => true]);
        }

        $order = $payment->order;
        if ($order && $order->status === OrderStatus::Completed) {
            $telegram->answerCallback($callbackId, "⚠️ Order {$order->number} is ALREADY Confirmed & Delivered!");
            if ($messageId > 0) {
                $telegram->editMessage(
                    $chatId,
                    $messageId,
                    "⚠️ <b>ALREADY CONFIRMED &amp; DELIVERED</b>\n".
                    "Order: <b>{$order->number}</b>\n".
                    'TID: <code>'.TelegramNotifier::e($payment->transaction_id)."</code>\n".
                    'Status: <b>Completed</b>'
                );
            }

            return response()->json(['ok' => true]);
        }

        try {
            if ($action === 'approve') {
                $order = $this->forceApproveAndComplete($order, $payment, $payments, $fulfillment);
                $telegram->answerCallback($callbackId, "✅ Order {$order->number} Approved & Delivered!");
                if ($messageId > 0) {
                    $telegram->editMessage(
                        $chatId,
                        $messageId,
                        "✅ <b>APPROVED &amp; DELIVERED</b>\n".
                        "Order: <b>{$order->number}</b>\n".
                        'TID: <code>'.TelegramNotifier::e($payment->transaction_id)."</code>\n".
                        "Status: <b>{$order->status->label()}</b>"
                    );
                }
            } else {
                if ($payment->status === PaymentStatus::Pending) {
                    $payments->reject($payment, 'Declined by Admin via Telegram');
                }
                $telegram->answerCallback($callbackId, "❌ Payment {$payment->transaction_id} Declined.");
                if ($messageId > 0) {
                    $telegram->editMessage(
                        $chatId,
                        $messageId,
                        "❌ <b>PAYMENT DECLINED</b>\n".
                        "Order: <b>{$payment->order->number}</b>\n".
                        'TID: <code>'.TelegramNotifier::e($payment->transaction_id).'</code>'
                    );
                }
            }
        } catch (Throwable $e) {
            $telegram->answerCallback($callbackId, 'Error: '.$e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Signed 1-click Approve or Reject link sent to Admin via ntfy / Auto-WhatsApp.
     * Redirects into the unified secure 1-Tap controller (`quickOrder`) so the Admin Secret Key is always verified!
     */
    public function quickAction(Request $request, Payment $payment, string $action, PaymentService $payments, FulfillmentService $fulfillment): View|RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $request->merge(['do' => $action]);

        return $this->quickOrder($request, $payment->order, $payments, $fulfillment);
    }

    /**
     * 1-Tap WhatsApp Approve / Decline endpoint (`/quick-order/{order:number}?do=approve|reject`).
     * Secured by the Admin Secret Key (`admin_quick_pin` saved in Admin Panel -> Store Settings).
     * Never executes state changes on GET so WhatsApp Link Preview bots cannot auto-approve orders.
     */
    public function quickOrder(Request $request, Order $order, PaymentService $payments, FulfillmentService $fulfillment): View|RedirectResponse
    {
        $do = strtolower((string) ($request->input('do') ?? $request->query('do') ?? 'approve'));
        if (! in_array($do, ['approve', 'reject'], true)) {
            $do = 'approve';
        }

        $expectedToken = $this->adminQuickCookieToken();
        $configuredPin = trim((string) Setting::get('admin_quick_pin', '7860'));

        $isUnlockedByCookie = hash_equals($expectedToken, (string) $request->cookie('smt_admin_quick_auth', ''));

        // If Admin submitted the Secret Key on POST, validate it!
        if ($request->isMethod('post') && ! $isUnlockedByCookie) {
            $pin = trim((string) $request->input('pin', ''));
            if ($configuredPin === '' || ! hash_equals($configuredPin, $pin)) {
                return back()->withInput()->with('error', 'Invalid Admin Secret Key! Enter the exact key saved in Admin Panel → Store Settings.');
            }

            Cookie::queue('smt_admin_quick_auth', $expectedToken, 60 * 24 * 365);
            $isUnlockedByCookie = true;
        }

        $order->load(['items.deliveries', 'paymentMethod', 'latestPayment']);
        $payment = $order->latestPayment;

        // If not unlocked by Secret Key cookie yet, show the Secret Key + Approve/Decline screen
        if (! $isUnlockedByCookie) {
            return view('store.quick-action', [
                'order' => $order,
                'payment' => $payment,
                'actionResult' => 'locked',
                'message' => 'Enter your Admin Secret Key (saved in Admin Panel → Store Settings) to Approve or Decline this order.',
                'needsPin' => true,
                'requestedAction' => $do,
            ]);
        }

        $message = null;
        $actionResult = 'view';

        // If order was ALREADY Completed earlier, always show the Duplicate Approval Prevention warning!
        if ($order->status === OrderStatus::Completed) {
            return view('store.quick-action', [
                'order' => $order,
                'payment' => $payment,
                'actionResult' => 'already_completed',
                'message' => "⚠️ ALREADY CONFIRMED & DELIVERED! Order {$order->number} was already verified and delivered (".($order->completed_at?->diffForHumans() ?? 'earlier').').',
                'needsPin' => false,
                'requestedAction' => $do,
            ]);
        }

        // Execute Approve or Decline on POST
        if ($request->isMethod('post')) {
            try {
                if ($do === 'approve') {
                    $customCredentials = trim((string) $request->input('credentials', ''));
                    $order = $this->forceApproveAndComplete($order, $payment, $payments, $fulfillment, $customCredentials);
                    $order->load(['items.deliveries', 'paymentMethod', 'latestPayment']);
                    $payment = $order->latestPayment;
                    $actionResult = 'approve';
                    $message = "✅ Order {$order->number} has been Approved & Completed!";
                } elseif ($do === 'reject') {
                    if ($payment && $payment->status === PaymentStatus::Pending) {
                        $payments->reject($payment, 'Declined by Admin via WhatsApp 1-Tap', $request->user());
                    } else {
                        $order->update(['status' => OrderStatus::Cancelled]);
                    }
                    $order->refresh()->load(['items.deliveries', 'paymentMethod', 'latestPayment']);
                    $payment = $order->latestPayment;
                    $actionResult = 'reject';
                    $message = "❌ Order {$order->number} has been Declined.";
                }
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
        } else {
            $message = 'Tap Approve or Decline below to process this order immediately.';
        }

        return view('store.quick-action', [
            'order' => $order,
            'payment' => $payment,
            'actionResult' => $actionResult,
            'message' => $message,
            'needsPin' => false,
            'requestedAction' => $do,
        ]);
    }

    /**
     * Approves the payment and guarantees the order transitions directly to Completed
     * when the Admin manually approves via 1-Tap WhatsApp / Telegram (never stuck on NeedsAttention!).
     */
    protected function forceApproveAndComplete(
        Order $order,
        ?Payment $payment,
        PaymentService $payments,
        FulfillmentService $fulfillment,
        string $customCredentials = ''
    ): Order {
        if (! $payment) {
            $method = $order->paymentMethod;
            $payment = $order->payments()->create([
                'payment_method_id' => $order->payment_method_id,
                'amount' => $method ? $method->amountFor($order) : $order->total,
                'currency' => $method?->currency ?? 'PKR',
                'transaction_id' => 'WA-'.$order->number,
                'sender_number' => $order->phone,
                'status' => PaymentStatus::Pending,
            ]);
            $order->update(['status' => OrderStatus::PaymentReview]);
        }

        // If Admin pasted custom credentials on the 1-Tap screen, deliver those lines first
        if ($customCredentials !== '') {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $customCredentials) ?: [])));
            if ($lines !== [] && ($firstItem = $order->items()->first())) {
                foreach ($lines as $line) {
                    $firstItem->deliveries()->create(['content' => $line, 'source' => 'manual']);
                    $firstItem->delivered_qty++;
                }
                $firstItem->status = 'delivered';
                $firstItem->save();
            }
        }

        if ($payment->status === PaymentStatus::Pending) {
            $order = $payments->approve($payment);
        } elseif ($order->status !== OrderStatus::Completed) {
            $order->update(['status' => OrderStatus::Processing, 'paid_at' => $order->paid_at ?? now()]);
            $order = $fulfillment->fulfill($order);
        }

        $order->refresh()->load('items.deliveries');

        // Guarantee: When Admin manually approves via 1-Tap, never leave it stuck on NeedsAttention!
        if ($order->status !== OrderStatus::Completed) {
            foreach ($order->items as $item) {
                if ($item->deliveries->isEmpty()) {
                    $item->deliveries()->create([
                        'content' => 'Order Approved & Confirmed by Admin (WhatsApp Verified)',
                        'source' => 'manual',
                    ]);
                }
                $item->update([
                    'delivered_qty' => max($item->quantity, $item->delivered_qty),
                    'reserved_supplier_qty' => 0,
                    'status' => 'delivered',
                ]);
            }

            $order->update([
                'status' => OrderStatus::Completed,
                'completed_at' => now(),
                'paid_at' => $order->paid_at ?? now(),
            ]);
        }

        return $order->refresh();
    }

    private function adminQuickCookieToken(): string
    {
        $pin = trim((string) Setting::get('admin_quick_pin', '7860'));

        return hash_hmac('sha256', 'smt_quick_pin:'.$pin, (string) config('app.key'));
    }
}
