<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends automatic WhatsApp alerts to Admin with 1-Tap Approve & Decline links.
 * Supports free CallMeBot WhatsApp API and any custom WhatsApp bot webhook.
 */
class WhatsAppNotifier
{
    public function notifyAdminPayment(Order $order, Payment $payment, string $approveUrl, string $rejectUrl): void
    {
        $phone = preg_replace('/\D/', '', (string) Setting::get('support_whatsapp', ''));
        $callMeBotKey = trim((string) Setting::get('whatsapp_callmebot_apikey', ''));
        $webhookUrl = trim((string) Setting::get('whatsapp_admin_webhook_url', ''));

        if ($callMeBotKey === '' && $webhookUrl === '') {
            return;
        }

        $items = $order->items->map(fn ($i) => "{$i->product_name} x{$i->quantity}")->implode(', ');
        $methodName = $order->paymentMethod?->name ?? 'Manual';

        $message = "💰 *New Payment Submitted*\n"
            ."🧾 *Order:* {$order->number}\n"
            ."📦 *Product:* {$items}\n"
            ."💵 *Amount:* {$payment->currency} {$payment->amount}\n"
            ."💳 *Method:* {$methodName}\n"
            ."🔑 *TID:* {$payment->transaction_id}\n"
            .($payment->sender_number ? "📱 *Sender:* {$payment->sender_number}\n" : '')
            ."📧 *Customer:* {$order->email}\n\n"
            ."✅ *1-Tap Approve & Deliver:*\n{$approveUrl}\n\n"
            ."❌ *1-Tap Decline Payment:*\n{$rejectUrl}";

        if (app()->runningInConsole() || app()->runningUnitTests()) {
            $this->dispatchSend($phone, $callMeBotKey, $webhookUrl, $message, $order, $payment, $approveUrl, $rejectUrl);

            return;
        }

        dispatch(fn () => $this->dispatchSend($phone, $callMeBotKey, $webhookUrl, $message, $order, $payment, $approveUrl, $rejectUrl))->afterResponse();
    }

    protected function dispatchSend(
        string $phone,
        string $callMeBotKey,
        string $webhookUrl,
        string $message,
        Order $order,
        Payment $payment,
        string $approveUrl,
        string $rejectUrl
    ): void {
        if ($callMeBotKey !== '' && $phone !== '') {
            try {
                Http::timeout(8)->get('https://api.callmebot.com/whatsapp.php', [
                    'phone' => $phone,
                    'text' => $message,
                    'apikey' => $callMeBotKey,
                ]);
            } catch (Throwable $e) {
                Log::warning('CallMeBot WhatsApp send error: '.$e->getMessage());
            }
        }

        if ($webhookUrl !== '') {
            try {
                Http::timeout(8)->post($webhookUrl, [
                    'phone' => $phone,
                    'order_number' => $order->number,
                    'tid' => $payment->transaction_id,
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'message' => $message,
                    'approve_url' => $approveUrl,
                    'reject_url' => $rejectUrl,
                ]);
            } catch (Throwable $e) {
                Log::warning('Custom WhatsApp webhook error: '.$e->getMessage());
            }
        }
    }
}
