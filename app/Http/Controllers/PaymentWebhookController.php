<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\AutoPaymentVerifier;
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
 *  - GET  /webhooks/payment/{payment}/{action} (Signed 1-Tap Approve / Decline from Telegram & Auto-WhatsApp)
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
    public function telegram(Request $request, PaymentService $payments, TelegramNotifier $telegram): JsonResponse
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

        if ($payment->status !== PaymentStatus::Pending) {
            $telegram->answerCallback($callbackId, "Already {$payment->status->value}!");

            return response()->json(['ok' => true]);
        }

        try {
            if ($action === 'approve') {
                $order = $payments->approve($payment);
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
                $payments->reject($payment, 'Declined by Admin via Telegram');
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

    /** Signed 1-click Approve or Reject link sent to Admin Telegram / Auto-WhatsApp. */
    public function quickAction(Request $request, Payment $payment, string $action, PaymentService $payments): View
    {
        abort_unless($request->hasValidSignature(), 403);

        $order = $payment->order()->with(['items.deliveries', 'paymentMethod'])->firstOrFail();
        $message = null;
        $actionResult = $action;

        if ($payment->status !== PaymentStatus::Pending) {
            $message = "Payment ({$payment->transaction_id}) was already {$payment->status->value}.";
        } else {
            try {
                if ($action === 'approve') {
                    $order = $payments->approve($payment);
                    $order->load(['items.deliveries', 'paymentMethod']);
                    $message = "Order {$order->number} has been approved and delivered automatically!";
                } elseif ($action === 'reject') {
                    $payments->reject($payment, 'Declined by Admin — Transaction ID could not be verified');
                    $order->refresh()->load(['items.deliveries', 'paymentMethod']);
                    $message = "Payment {$payment->transaction_id} for Order {$order->number} has been declined.";
                } else {
                    abort(400);
                }
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
        }

        return view('store.quick-action', [
            'order' => $order,
            'payment' => $payment->refresh(),
            'actionResult' => $actionResult,
            'message' => $message,
            'needsPin' => false,
            'requestedAction' => $action,
        ]);
    }

    /**
     * 1-Tap WhatsApp Approve / Decline endpoint (`/quick-order/{order:number}?do=approve|reject`).
     * Uses a 365-day encrypted Admin Cookie (`smt_admin_quick_auth`) or Admin Login session so:
     *  - On Admin's phone: executes in 1 single tap!
     *  - On Customer's phone: prompts for Admin PIN, preventing customers from approving their own orders.
     */
    public function quickOrder(Request $request, Order $order, PaymentService $payments): View|RedirectResponse
    {
        $do = strtolower((string) ($request->input('do') ?? $request->query('do') ?? ''));
        $expectedToken = $this->adminQuickCookieToken();

        // Check if Admin is submitting their PIN once to unlock 1-Tap on this phone
        if ($request->isMethod('post') && $request->filled('pin')) {
            $pin = trim((string) $request->input('pin'));
            $configuredPin = trim((string) Setting::get('admin_quick_pin', '7860'));

            if ($configuredPin !== '' && hash_equals($configuredPin, $pin)) {
                Cookie::queue('smt_admin_quick_auth', $expectedToken, 60 * 24 * 365);

                return redirect()->to(route('webhooks.order.quick', ['order' => $order->number, 'do' => $do ?: 'approve', 'unlocked' => 1]));
            }

            return back()->with('error', 'Invalid Admin Quick PIN.');
        }

        $isAdmin = ($request->user() && $request->user()->is_admin)
            || hash_equals($expectedToken, (string) $request->cookie('smt_admin_quick_auth', ''))
            || $request->boolean('unlocked');

        $order->load(['items.deliveries', 'paymentMethod', 'latestPayment']);

        if (! $isAdmin) {
            return view('store.quick-action', [
                'order' => $order,
                'payment' => $order->latestPayment,
                'actionResult' => 'locked',
                'message' => 'Enter your Admin 1-Tap PIN once to unlock instant WhatsApp approvals on this phone.',
                'needsPin' => true,
                'requestedAction' => in_array($do, ['approve', 'reject'], true) ? $do : 'approve',
            ]);
        }

        $payment = $order->latestPayment;
        $message = null;
        $actionResult = $do ?: 'view';

        if (in_array($do, ['approve', 'reject'], true)) {
            try {
                if ($do === 'approve') {
                    // If customer sent payment on WhatsApp without submitting TID form on website, create pending payment record automatically
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

                    if ($payment->status === PaymentStatus::Pending) {
                        $order = $payments->approve($payment, $request->user());
                        $order->load(['items.deliveries', 'paymentMethod']);
                        $message = "✅ Order {$order->number} Approved & Delivered automatically!";
                    } else {
                        $message = "Order {$order->number} is already {$order->status->label()}.";
                    }
                } elseif ($do === 'reject') {
                    if ($payment && $payment->status === PaymentStatus::Pending) {
                        $payments->reject($payment, 'Declined by Admin via WhatsApp 1-Tap', $request->user());
                        $order->refresh()->load(['items.deliveries', 'paymentMethod']);
                        $message = "❌ Payment for Order {$order->number} has been declined.";
                    } else {
                        $order->update(['status' => OrderStatus::Cancelled]);
                        $message = "❌ Order {$order->number} has been declined/cancelled.";
                    }
                }
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
        }

        return view('store.quick-action', [
            'order' => $order,
            'payment' => $payment?->refresh(),
            'actionResult' => $actionResult,
            'message' => $message,
            'needsPin' => false,
            'requestedAction' => $do,
        ]);
    }

    private function adminQuickCookieToken(): string
    {
        $pin = trim((string) Setting::get('admin_quick_pin', '7860'));

        return hash_hmac('sha256', 'smt_quick_pin:'.$pin, (string) config('app.key'));
    }
}
