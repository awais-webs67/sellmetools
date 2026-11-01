<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends automatic WhatsApp & Mobile Push alerts to Admin with 1-Tap Approve & Decline buttons,
 * and automatically sends delivered credentials to the customer's WhatsApp when Green-API is active!
 *
 * Supported Free Providers (No CallMeBot slot limits!):
 *  1. Green-API (green-api.com — 100% Free Forever Developer Plan via WhatsApp QR)
 *  2. ntfy.sh   (ntfy.sh — 100% Free, Zero-Signup Mobile Push App with 1-Tap Action Buttons)
 *  3. Custom Webhook / CallMeBot
 */
class WhatsAppNotifier
{
    public static function formatPhone(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($digits, '03') && strlen($digits) === 11) {
            return '92'.substr($digits, 1);
        }
        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        return $digits;
    }

    public function notifyAdminPayment(Order $order, Payment $payment, string $approveUrl, string $rejectUrl): void
    {
        $adminPhone = self::formatPhone((string) Setting::get('support_whatsapp', ''));
        $greenId = trim((string) Setting::get('greenapi_instance_id', ''));
        $greenToken = trim((string) Setting::get('greenapi_api_token', ''));
        $ntfyTopic = trim((string) Setting::get('ntfy_topic', ''));
        $callMeBotKey = trim((string) Setting::get('whatsapp_callmebot_apikey', ''));
        $webhookUrl = trim((string) Setting::get('whatsapp_admin_webhook_url', ''));

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

        // 1. Green-API Free Forever WhatsApp (green-api.com)
        if ($greenId !== '' && $greenToken !== '' && $adminPhone !== '') {
            $this->sendGreenApi($greenId, $greenToken, $adminPhone, $message);
        }

        // 2. ntfy.sh Free Zero-Signup Push Notification with 1-Tap Action Buttons
        if ($ntfyTopic !== '') {
            try {
                Http::timeout(5)
                    ->withHeaders([
                        'Title' => "New Payment: {$order->number} ({$payment->currency} {$payment->amount})",
                        'Priority' => 'high',
                        'Tags' => 'moneybag,shopping_cart',
                        'Actions' => "view, Approve & Deliver, {$approveUrl}, clear=true; view, Decline, {$rejectUrl}",
                    ])
                    ->withBody(
                        "Product: {$items}\nMethod: {$methodName}\nTID: {$payment->transaction_id}\nCustomer: {$order->email}",
                        'text/plain'
                    )
                    ->post('https://ntfy.sh/'.urlencode($ntfyTopic));
            } catch (Throwable $e) {
                Log::warning('ntfy push error: '.$e->getMessage());
            }
        }

        // 3. CallMeBot (if configured)
        if ($callMeBotKey !== '' && $adminPhone !== '') {
            try {
                Http::timeout(5)->get('https://api.callmebot.com/whatsapp.php', [
                    'phone' => $adminPhone,
                    'text' => $message,
                    'apikey' => $callMeBotKey,
                ]);
            } catch (Throwable $e) {
                Log::warning('CallMeBot WhatsApp send error: '.$e->getMessage());
            }
        }

        // 4. Custom WhatsApp Webhook (if configured)
        if ($webhookUrl !== '') {
            try {
                Http::timeout(5)->post($webhookUrl, [
                    'phone' => $adminPhone,
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

    /**
     * Automatically sends delivered credentials to Customer's WhatsApp (and Admin) if Green-API is configured!
     */
    public function notifyOrderDelivered(Order $order): void
    {
        $greenId = trim((string) Setting::get('greenapi_instance_id', ''));
        $greenToken = trim((string) Setting::get('greenapi_api_token', ''));
        $ntfyTopic = trim((string) Setting::get('ntfy_topic', ''));

        $items = $order->items->map(fn ($i) => "{$i->product_name} x{$i->quantity}")->implode(', ');
        $creds = $order->items->flatMap(fn ($item) => $item->deliveries->pluck('content'))->implode("\n");

        if ($ntfyTopic !== '') {
            try {
                Http::timeout(4)
                    ->withHeaders([
                        'Title' => "Order Delivered: {$order->number}",
                        'Tags' => 'white_check_mark',
                    ])
                    ->withBody("Delivered {$items} to {$order->email} (Rs {$order->total})", 'text/plain')
                    ->post('https://ntfy.sh/'.urlencode($ntfyTopic));
            } catch (Throwable) {
            }
        }

        if ($greenId === '' || $greenToken === '') {
            return;
        }

        $customerPhone = self::formatPhone((string) ($order->phone ?? ''));
        if ($customerPhone !== '') {
            $storeName = (string) Setting::get('store_name', 'SellMeTools');
            $customerMsg = "✅ *{$storeName} – Order Delivered!*\n\n"
                ."🧾 *Order ID:* {$order->number}\n"
                ."📦 *Product:* {$items}\n\n"
                ."🔑 *Your Credentials / Activation:*\n{$creds}\n\n"
                .'🔗 *Order Link:* '.$order->customerUrl()."\n\n"
                .'Thank you for shopping with us!';

            $this->sendGreenApi($greenId, $greenToken, $customerPhone, $customerMsg);
        }
    }

    public function sendGreenApi(string $instanceId, string $apiToken, string $phoneDigits, string $message): bool
    {
        try {
            $response = Http::timeout(6)->post(
                "https://api.green-api.com/waInstance{$instanceId}/sendMessage/{$apiToken}",
                [
                    'chatId' => "{$phoneDigits}@c.us",
                    'message' => $message,
                ]
            );

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('Green-API WhatsApp error: '.$e->getMessage());

            return false;
        }
    }
}
