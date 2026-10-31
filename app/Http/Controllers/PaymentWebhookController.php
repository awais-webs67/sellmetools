<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\AutoPaymentVerifier;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Endpoints for automatic payment gateways:
 *  - POST /api/webhooks/payment-sms  (Android SMS/Notification Forwarder for Easypaisa, JazzCash, NayaPay, SadaPay)
 *  - GET  /webhooks/payment/{payment}/{action} (Signed 1-click Approve / Reject from Telegram admin alert)
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

        // Support both raw SMS text (`message` / `text` / `body`) and structured fields (`tid`, `amount`)
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

    /** Signed 1-click Approve or Reject link sent to Admin Telegram. */
    public function quickAction(Request $request, Payment $payment, string $action, PaymentService $payments): Response
    {
        abort_unless($request->hasValidSignature(), 403);

        if ($payment->status !== PaymentStatus::Pending) {
            return response("Payment #{$payment->id} ({$payment->transaction_id}) was already {$payment->status->value}.", 200);
        }

        if ($action === 'approve') {
            $order = $payments->approve($payment);

            return response("✅ Approved! Order {$order->number} is now {$order->status->label()}.", 200);
        }

        if ($action === 'reject') {
            $payments->reject($payment, 'Invalid Transaction ID — could not be verified');

            return response("❌ Rejected payment {$payment->transaction_id} for Order {$payment->order->number}.", 200);
        }

        abort(400);
    }
}
