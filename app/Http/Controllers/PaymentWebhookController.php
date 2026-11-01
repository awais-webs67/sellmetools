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
 *  - GET|POST /webhooks/payment/{payment}/{action} (Signed 1-Tap Approve / Decline from Auto-WhatsApp / ntfy)
 *  - GET|POST /quick-order/{order:number}   (1-Tap Approve / Decline from WhatsApp Receipt Message)
 *
 * IMPORTANT SECURITY RULE:
 *  GET requests NEVER approve or reject an order! Why? Because WhatsApp, Telegram, and ntfy
 *  link-preview crawlers automatically fetch GET URLs in messages to generate link previews.
 *  Approving or rejecting ALWAYS requires a POST submission (or Telegram callback_query POST).
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

        $order = $payment->order;
        if ($order && $order->status === OrderStatus::Completed) {
            $telegram->answerCallback($callbackId, "⚠️ Order {$order->number} is ALREADY Confirmed & Delivered! No action needed.");
            if ($messageId > 0) {
                $telegram->editMessage(
                    $chatId,
                    $messageId,
                    "⚠️ <b>ALREADY CONFIRMED &amp; DELIVERED</b>\n".
                    "Order: <b>{$order->number}</b>\n".
                    'TID: <code>'.TelegramNotifier::e($payment->transaction_id)."</code>\n".
                    'Status: <b>Completed (Auto-Delivered)</b>'
                );
            }

            return response()->json(['ok' => true]);
        }

        if ($payment->status !== PaymentStatus::Pending) {
            $telegram->answerCallback($callbackId, "⚠️ Payment is already {$payment->status->value}!");

            return response()->json(['ok' => true]);
        }

        try {
            if ($action === 'approve') {
                $order = $payments->approve($payment);
                $statusTitle = $order->status === OrderStatus::Completed
                    ? '✅ APPROVED &amp; DELIVERED'
                    : '⏸️ APPROVED — PAUSED (NEEDS MANUAL DELIVERY)';
                $toast = $order->status === OrderStatus::Completed
                    ? "✅ Order {$order->number} Approved & Delivered!"
                    : "⏸️ Order {$order->number} Approved, but Supplier Balance is low! Check Admin Panel.";

                $telegram->answerCallback($callbackId, $toast);
                if ($messageId > 0) {
                    $telegram->editMessage(
                        $chatId,
                        $messageId,
                        "{$statusTitle}\n".
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

    /**
     * Signed 1-click Approve or Reject link sent to Admin via ntfy / Auto-WhatsApp.
     * GET displays the confirmation card (safe against link-preview bots!).
     * POST executes the approval/rejection.
     */
    public function quickAction(Request $request, Payment $payment, string $action, PaymentService $payments): View
    {
        abort_unless($request->hasValidSignature(), 403);

        $order = $payment->order()->with(['items.deliveries', 'paymentMethod'])->firstOrFail();
        $message = null;
        $actionResult = 'view';

        if ($order->status === OrderStatus::Completed || $payment->status === PaymentStatus::Approved) {
            $actionResult = 'already_completed';
            $message = "⚠️ ALREADY CONFIRMED & DELIVERED! Order {$order->number} was already paid and delivered automatically. Do not deliver twice.";
        } elseif ($payment->status !== PaymentStatus::Pending) {
            $message = "Payment ({$payment->transaction_id}) was already {$payment->status->value}.";
        } elseif ($request->isMethod('post')) {
            try {
                if ($action === 'approve') {
                    $order = $payments->approve($payment);
                    $order->load(['items.deliveries', 'paymentMethod']);
                    $actionResult = 'approve';
                    $message = $order->status === OrderStatus::Completed
                        ? "✅ Order {$order->number} has been approved and delivered automatically!"
                        : "⏸️ Payment approved, but automatic supplier delivery paused ({$order->admin_note}). Please deliver manually.";
                } elseif ($action === 'reject') {
                    $payments->reject($payment, 'Declined by Admin — Transaction ID could not be verified');
                    $order->refresh()->load(['items.deliveries', 'paymentMethod']);
                    $actionResult = 'reject';
                    $message = "❌ Payment {$payment->transaction_id} for Order {$order->number} has been declined.";
                } else {
                    abort(400);
                }
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
        } else {
            $message = 'Tap the button below to confirm this action.';
        }

        return view('store.quick-action', [
            'order' => $order,
            'payment' => $payment->refresh(),
            'actionResult' => $actionResult,
            'message' => $message,
            'needsPin' => false,
            'requestedAction' => $action,
            'signedFormUrl' => $request->fullUrl(),
        ]);
    }

    /**
     * 1-Tap WhatsApp Approve / Decline endpoint (`/quick-order/{order:number}?do=approve|reject`).
     * Uses a 365-day encrypted Admin Cookie (`smt_admin_quick_auth`) or Admin Login session.
     * IMPORTANT: Only executes state changes on POST so WhatsApp Link Preview bots NEVER auto-approve orders!
     */
    public function quickOrder(Request $request, Order $order, PaymentService $payments): View|RedirectResponse
    {
        $do = strtolower((string) ($request->input('do') ?? $request->query('do') ?? 'approve'));
        $expectedToken = $this->adminQuickCookieToken();

        $hasCookieOrAdmin = ($request->user() && $request->user()->is_admin)
            || hash_equals($expectedToken, (string) $request->cookie('smt_admin_quick_auth', ''));

        // If Admin is submitting their PIN on POST, verify PIN and queue 1-year cookie, then execute the action right away!
        if ($request->isMethod('post') && $request->filled('pin')) {
            $pin = trim((string) $request->input('pin'));
            $configuredPin = trim((string) Setting::get('admin_quick_pin', '7860'));

            if ($configuredPin === '' || ! hash_equals($configuredPin, $pin)) {
                return back()->with('error', 'Invalid Admin Quick PIN.');
            }

            Cookie::queue('smt_admin_quick_auth', $expectedToken, 60 * 24 * 365);
            $hasCookieOrAdmin = true;
        }

        $order->load(['items.deliveries', 'paymentMethod', 'latestPayment']);

        if (! $hasCookieOrAdmin) {
            return view('store.quick-action', [
                'order' => $order,
                'payment' => $order->latestPayment,
                'actionResult' => 'locked',
                'message' => 'Enter your Admin 1-Tap PIN once to unlock & confirm this order on your phone.',
                'needsPin' => true,
                'requestedAction' => in_array($do, ['approve', 'reject'], true) ? $do : 'approve',
            ]);
        }

        $payment = $order->latestPayment;
        $message = null;
        $actionResult = 'view';

        // Check BEFORE approving if order was already auto-confirmed & delivered
        if ($order->status === OrderStatus::Completed || ($payment && $payment->status === PaymentStatus::Approved && $order->status !== OrderStatus::NeedsAttention)) {
            $actionResult = 'already_completed';
            $message = "⚠️ ALREADY CONFIRMED & DELIVERED! Order {$order->number} was already verified and delivered automatically (".($order->completed_at?->diffForHumans() ?? 'earlier').'). No manual action needed.';

            return view('store.quick-action', [
                'order' => $order,
                'payment' => $payment,
                'actionResult' => $actionResult,
                'message' => $message,
                'needsPin' => false,
                'requestedAction' => $do,
            ]);
        }

        // Only mutate state on POST (never on GET link preview!)
        if ($request->isMethod('post') && in_array($do, ['approve', 'reject'], true)) {
            try {
                if ($do === 'approve') {
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
                        $actionResult = 'approve';
                        $message = $order->status === OrderStatus::Completed
                            ? "✅ Order {$order->number} Approved & Delivered automatically!"
                            : '⏸️ Payment approved, but supplier balance is low. Please deliver credentials manually.';
                    } else {
                        $actionResult = 'already_completed';
                        $message = "⚠️ Order {$order->number} is already {$order->status->label()}.";
                    }
                } elseif ($do === 'reject') {
                    if ($payment && $payment->status === PaymentStatus::Pending) {
                        $payments->reject($payment, 'Declined by Admin via WhatsApp 1-Tap', $request->user());
                        $order->refresh()->load(['items.deliveries', 'paymentMethod']);
                        $actionResult = 'reject';
                        $message = "❌ Payment for Order {$order->number} has been declined.";
                    } else {
                        $order->update(['status' => OrderStatus::Cancelled]);
                        $actionResult = 'reject';
                        $message = "❌ Order {$order->number} has been declined/cancelled.";
                    }
                }
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
        } else {
            $message = 'Verify payment receipt and tap Approve or Decline below.';
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
