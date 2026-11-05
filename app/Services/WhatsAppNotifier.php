<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends professional, emoji-rich alerts to Admin on WhatsApp (Green-API / CallMeBot / Webhook)
 * and ntfy Mobile Push App with 1-Tap Approve & Decline buttons, plus customer delivery messages!
 */
class WhatsAppNotifier
{
    public ?string $lastError = null;

    public ?int $lastHttpStatus = null;

    public static function formatPhone(string $raw): string
    {
        return format_whatsapp($raw);
    }

    public function hasCustomerWhatsAppGateway(): bool
    {
        if ((string) Setting::get('whatsapp_send_customer_receipt', '0') !== '1') {
            return false;
        }

        $greenId = trim((string) Setting::get('greenapi_instance_id', ''));
        $greenToken = trim((string) Setting::get('greenapi_api_token', ''));
        $webhookUrl = trim((string) Setting::get('whatsapp_admin_webhook_url', ''));

        return ($greenId !== '' && $greenToken !== '') || $webhookUrl !== '';
    }

    public static function buildCustomerReceiptMessage(Order $order): string
    {
        $order->loadMissing(['items.deliveries', 'paymentMethod']);
        $storeName = (string) Setting::get('store_name', 'SellMeTools');
        $items = $order->items->map(fn ($i) => "{$i->product_name} × {$i->quantity}")->implode(', ');
        $creds = $order->items
            ->flatMap(fn ($item) => $item->deliveries->pluck('content'))
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->implode("\n\n");

        if ($creds === '') {
            $creds = filled($order->customer_input)
                ? 'Activated on your submitted account: '.trim((string) $order->customer_input)
                : 'Order fulfilled and activated.';
        }

        $totalStr = (float) $order->total <= 0 ? 'Rs 0 (Free)' : money($order->total);

        return "*{$storeName} — Order Delivered Successfully!*\n\n"
            ."Please copy your product details below:\n"
            ."━━━━━━━━━━━━━━━━━━\n"
            ."*Order ID:* `{$order->number}`\n"
            ."*Product:* {$items}\n"
            ."*Total Paid:* {$totalStr}\n\n"
            ."*Your Credentials / Details:*\n{$creds}\n"
            ."━━━━━━━━━━━━━━━━━━\n"
            .'*Order & Receipt Link:* '.$order->customerUrl()."\n\n"
            .'Thank you for shopping with us!';
    }

    /**
     * 1. Manual Payment Submitted (Order at 85%: Awaiting Admin 1-Tap Approval)
     */
    public function notifyAdminPayment(Order $order, Payment $payment, string $approveUrl, string $rejectUrl): void
    {
        $adminPhone = admin_whatsapp_number();
        $greenId = trim((string) Setting::get('greenapi_instance_id', ''));
        $greenToken = trim((string) Setting::get('greenapi_api_token', ''));
        $ntfyTopic = trim((string) Setting::get('ntfy_topic', ''));
        $callMeBotKey = trim((string) Setting::get('whatsapp_callmebot_apikey', ''));
        $webhookUrl = trim((string) Setting::get('whatsapp_admin_webhook_url', ''));

        $items = $order->items->map(fn ($i) => "{$i->product_name} × {$i->quantity}")->implode(', ');
        $supplierSummary = $order->adminSupplierSummary(true);
        $methodName = $order->paymentMethod?->name ?? 'Manual Wallet';
        $quickOrderUrl = route('webhooks.order.quick', ['order' => $order->number]);

        $message = "🔔 *NEW PAYMENT TO VERIFY (85%)*\n"
            ."━━━━━━━━━━━━━━━━━━\n"
            ."🧾 *Order ID:* `{$order->number}`\n"
            ."📦 *Product:* {$items}\n"
            ."🏭 *Supplier:* {$supplierSummary}\n"
            ."💵 *Amount:* *{$payment->currency} {$payment->amount}* (Rs {$order->total})\n"
            ."💳 *Method:* {$methodName}\n"
            ."🔑 *TID:* `{$payment->transaction_id}`\n"
            .($payment->sender_number ? "📱 *Sender:* {$payment->sender_number}\n" : '')
            ."👤 *Customer:* {$order->email}".($order->phone ? " (+{$order->phone})" : '')."\n"
            ."━━━━━━━━━━━━━━━━━━\n"
            ."⚡ *1-Tap Approve / Decline (Secret Key Protected):*\n{$quickOrderUrl}";

        if ($greenId !== '' && $greenToken !== '' && $adminPhone !== '') {
            $this->sendGreenApi($greenId, $greenToken, $adminPhone, $message);
        }

        if ($ntfyTopic !== '') {
            try {
                Http::timeout(5)
                    ->withHeaders([
                        'Title' => "🔔 Verify Payment: {$order->number} ({$payment->currency} {$payment->amount})",
                        'Priority' => 'high',
                        'Tags' => 'moneybag,bell',
                        'Click' => $quickOrderUrl,
                        'Actions' => "view, ⚡ Open 1-Tap Approve / Decline, {$quickOrderUrl}, clear=true",
                    ])
                    ->withBody(
                        "📦 {$items}\n🏭 Supplier: {$supplierSummary}\n💳 {$methodName} | 🔑 TID: {$payment->transaction_id}\n👤 {$order->email}".($payment->sender_number ? " ({$payment->sender_number})" : ''),
                        'text/plain'
                    )
                    ->post('https://ntfy.sh/'.urlencode($ntfyTopic));
            } catch (Throwable $e) {
                Log::warning('ntfy push error: '.$e->getMessage());
            }
        }

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
     * 2. Order Delivered Successfully (Auto or 1-Tap Approved)
     */
    public function notifyOrderDelivered(Order $order): void
    {
        $order->load(['items.deliveries', 'paymentMethod', 'latestPayment', 'user']);

        $adminPhone = admin_whatsapp_number();
        $greenId = trim((string) Setting::get('greenapi_instance_id', ''));
        $greenToken = trim((string) Setting::get('greenapi_api_token', ''));
        $ntfyTopic = trim((string) Setting::get('ntfy_topic', ''));
        $callMeBotKey = trim((string) Setting::get('whatsapp_callmebot_apikey', ''));
        $webhookUrl = trim((string) Setting::get('whatsapp_admin_webhook_url', ''));

        $items = $order->items->map(fn ($i) => "{$i->product_name} × {$i->quantity}")->implode(', ');
        $supplierSummary = $order->adminSupplierSummary(true);
        $creds = $order->items
            ->flatMap(fn ($item) => $item->deliveries->pluck('content'))
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->implode("\n\n");
        $methodName = $order->paymentMethod?->name ?? 'Online';
        $profit = round((float) $order->total - (float) $order->total_cost, 2);

        $rawCustomerPhone = $order->phone ?: ($order->latestPayment?->sender_number ?: ($order->user?->phone ?? ''));
        $customerPhone = self::formatPhone((string) $rawCustomerPhone);
        if ($customerPhone !== '' && empty($order->phone)) {
            $order->updateQuietly(['phone' => $customerPhone]);
        }

        if ($ntfyTopic !== '') {
            try {
                Http::timeout(4)
                    ->withHeaders([
                        'Title' => "Order Delivered: {$order->number} (Rs {$order->total})",
                        'Tags' => 'white_check_mark,tada',
                    ])
                    ->withBody(
                        "Product: {$items}\nSupplier: {$supplierSummary}\nMethod: {$methodName} | Profit: Rs {$profit}\nCustomer: {$order->email}".($customerPhone !== '' ? " (+{$customerPhone})" : ''),
                        'text/plain'
                    )
                    ->post('https://ntfy.sh/'.urlencode($ntfyTopic));
            } catch (Throwable) {
            }
        }

        $customerWaStatusLine = '';
        $oneTapCustomerWaUrl = '';
        $autoSendCustomerReceipt = (string) Setting::get('whatsapp_send_customer_receipt', '0') === '1';
        $includeCustomerChatLink = (string) Setting::get('whatsapp_include_customer_chat_link', '0') === '1';

        // 1. Customer WhatsApp Receipt (Only auto-sent when whatsapp_send_customer_receipt toggle is ON)
        if ($customerPhone !== '') {
            $customerMsg = self::buildCustomerReceiptMessage($order);
            $oneTapCustomerWaUrl = "https://wa.me/{$customerPhone}?text=".urlencode($customerMsg);

            if ($autoSendCustomerReceipt) {
                $sentToCustomer = false;

                if ($greenId !== '' && $greenToken !== '') {
                    $sentToCustomer = $this->sendGreenApi($greenId, $greenToken, $customerPhone, $customerMsg);
                    if ($sentToCustomer) {
                        $customerWaStatusLine = "\n*Customer WhatsApp:* Sent to +{$customerPhone}";
                    } elseif ($this->lastHttpStatus === 466) {
                        $customerWaStatusLine = "\n*Customer WhatsApp:* Green-API Free Plan Limit (HTTP 466 — Free plan only messages 3 numbers/month)."
                            .($includeCustomerChatLink ? "\n*1-Tap Send to Customer:* {$oneTapCustomerWaUrl}" : '');
                    } else {
                        $errDetail = $this->lastError ? " ({$this->lastError})" : '';
                        $customerWaStatusLine = "\n*Customer WhatsApp:* Could not auto-send to +{$customerPhone}{$errDetail}."
                            .($includeCustomerChatLink ? "\n*1-Tap Send to Customer:* {$oneTapCustomerWaUrl}" : '');
                    }

                    // Pause 750ms before sending Admin alert so Green-API's 1-req/sec rate limit is respected
                    if (! app()->runningUnitTests()) {
                        usleep(750000);
                    }
                }

                if ($webhookUrl !== '') {
                    try {
                        Http::timeout(5)->post($webhookUrl, [
                            'event' => 'order_delivered',
                            'phone' => $customerPhone,
                            'order_number' => $order->number,
                            'product' => $items,
                            'credentials' => $creds,
                            'order_url' => $order->customerUrl(),
                            'message' => $customerMsg,
                        ]);
                    } catch (Throwable $e) {
                        Log::warning('Custom WhatsApp customer delivery webhook error: '.$e->getMessage());
                    }
                }
            } elseif ($includeCustomerChatLink) {
                $customerWaStatusLine = "\n*1-Tap Send Receipt to Customer:*\n{$oneTapCustomerWaUrl}";
            }
        }

        // 2. Send Admin Summary Alert
        $adminMsg = "*ORDER DELIVERED SUCCESSFULLY*\n"
            ."━━━━━━━━━━━━━━━━━━\n"
            ."*Order:* `{$order->number}`\n"
            ."*Product:* {$items}\n"
            ."*Supplier:* {$supplierSummary}\n"
            ."*Method:* {$methodName}\n"
            ."*Sale:* Rs {$order->total} | *Profit:* Rs {$profit}\n"
            ."*Customer:* {$order->email}".($customerPhone !== '' ? " (+{$customerPhone})" : '')
            .$customerWaStatusLine;

        if ($greenId !== '' && $greenToken !== '' && $adminPhone !== '') {
            $this->sendGreenApi($greenId, $greenToken, $adminPhone, $adminMsg);
        }

        if ($callMeBotKey !== '' && $adminPhone !== '') {
            try {
                Http::timeout(5)->get('https://api.callmebot.com/whatsapp.php', [
                    'phone' => $adminPhone,
                    'text' => $adminMsg,
                    'apikey' => $callMeBotKey,
                ]);
            } catch (Throwable) {
            }
        }
    }

    /**
     * 3. Payment Verified, BUT Supplier Balance is Low -> Order Put ON HOLD!
     */
    public function notifyOrderOnHoldLowBalance(Order $order, array $problems = []): void
    {
        $adminPhone = admin_whatsapp_number();
        $greenId = trim((string) Setting::get('greenapi_instance_id', ''));
        $greenToken = trim((string) Setting::get('greenapi_api_token', ''));
        $ntfyTopic = trim((string) Setting::get('ntfy_topic', ''));

        $items = $order->items->map(fn ($i) => "{$i->product_name} × {$i->quantity}")->implode(', ');
        $supplierSummary = $order->adminSupplierSummary(true);
        $reason = implode(' | ', array_unique($problems)) ?: 'Supplier balance is low';
        $quickOrderUrl = route('webhooks.order.quick', ['order' => $order->number]);

        if ($ntfyTopic !== '') {
            try {
                Http::timeout(5)
                    ->withHeaders([
                        'Title' => "⏸️⚠️ ORDER ON HOLD (Funds Low): {$order->number}",
                        'Priority' => 'urgent',
                        'Tags' => 'warning,rotating_light',
                        'Click' => $quickOrderUrl,
                        'Actions' => "view, ⚡ Deliver Manually Now, {$quickOrderUrl}, clear=true",
                    ])
                    ->withBody(
                        "Payment is VERIFIED (Rs {$order->total}), but Supplier Funds are low!\n📦 {$items}\n🏭 Supplier: {$supplierSummary}\n⚠️ {$reason}\nCustomer sees 'Order On Hold: Admin is checking'.",
                        'text/plain'
                    )
                    ->post('https://ntfy.sh/'.urlencode($ntfyTopic));
            } catch (Throwable) {
            }
        }

        if ($greenId !== '' && $greenToken !== '' && $adminPhone !== '') {
            $msg = "⏸️⚠️ *ORDER ON HOLD: SUPPLIER FUNDS LOW!*\n"
                ."━━━━━━━━━━━━━━━━━━\n"
                ."✅ *Payment Status:* VERIFIED (Rs {$order->total})\n"
                ."🧾 *Order ID:* `{$order->number}`\n"
                ."📦 *Product:* {$items}\n"
                ."🏭 *Supplier:* {$supplierSummary}\n"
                ."⚠️ *Issue:* {$reason}\n"
                ."👤 *Customer:* {$order->email}\n"
                ."━━━━━━━━━━━━━━━━━━\n"
                ."Customer screen is showing: *Order On Hold: Admin is checking your order.*\n"
                ."👉 *Top-up supplier or deliver manually here:*\n{$quickOrderUrl}";

            $this->sendGreenApi($greenId, $greenToken, $adminPhone, $msg);
        }
    }

    /**
     * 4. Product / Supplier Stock Depleted (0 Stock Alert to Admin on WhatsApp & ntfy)
     */
    public function notifyAdminOutOfStock(Product $product, ?string $supplierSummary = null): void
    {
        $adminPhone = admin_whatsapp_number();
        $greenId = trim((string) Setting::get('greenapi_instance_id', ''));
        $greenToken = trim((string) Setting::get('greenapi_api_token', ''));
        $ntfyTopic = trim((string) Setting::get('ntfy_topic', ''));
        $callMeBotKey = trim((string) Setting::get('whatsapp_callmebot_apikey', ''));
        $webhookUrl = trim((string) Setting::get('whatsapp_admin_webhook_url', ''));

        $supplierSummary = $supplierSummary ?: $product->adminSupplierSummary(true);
        $adminProductsUrl = url('/admin/products');
        $priceText = money($product->price);

        $message = "🚨📦 *OUT OF STOCK ALERT!*\n"
            ."━━━━━━━━━━━━━━━━━━\n"
            ."📦 *Product:* {$product->name}\n"
            ."🏭 *Supplier / Source:* {$supplierSummary}\n"
            ."📉 *Current Stock:* 0 (Sold Out)\n"
            ."💰 *Price:* {$priceText}\n"
            ."━━━━━━━━━━━━━━━━━━\n"
            ."⚠️ Please restock .TXT vault or check supplier stock in Admin Panel:\n{$adminProductsUrl}";

        if ($greenId !== '' && $greenToken !== '' && $adminPhone !== '') {
            $this->sendGreenApi($greenId, $greenToken, $adminPhone, $message);
        }

        if ($ntfyTopic !== '') {
            try {
                Http::timeout(5)
                    ->withHeaders([
                        'Title' => "🚨 Out of Stock: {$product->name}",
                        'Priority' => 'high',
                        'Tags' => 'package,warning',
                        'Click' => $adminProductsUrl,
                    ])
                    ->withBody(
                        "📦 {$product->name} is now SOLD OUT (0 Stock)!\n🏭 Supplier: {$supplierSummary}\n💰 Price: {$priceText}",
                        'text/plain'
                    )
                    ->post('https://ntfy.sh/'.urlencode($ntfyTopic));
            } catch (Throwable) {
            }
        }

        if ($callMeBotKey !== '' && $adminPhone !== '') {
            try {
                Http::timeout(5)->get('https://api.callmebot.com/whatsapp.php', [
                    'phone' => $adminPhone,
                    'text' => $message,
                    'apikey' => $callMeBotKey,
                ]);
            } catch (Throwable) {
            }
        }

        if ($webhookUrl !== '') {
            try {
                Http::timeout(5)->post($webhookUrl, [
                    'phone' => $adminPhone,
                    'event' => 'out_of_stock',
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'supplier' => $supplierSummary,
                    'message' => $message,
                ]);
            } catch (Throwable) {
            }
        }
    }

    /**
     * 5. Supplier Balance Low Alert to Admin on WhatsApp & ntfy
     */
    public function notifyAdminLowSupplierBalance(string $supplierName, float $balance, string $currency): void
    {
        $adminPhone = admin_whatsapp_number();
        $greenId = trim((string) Setting::get('greenapi_instance_id', ''));
        $greenToken = trim((string) Setting::get('greenapi_api_token', ''));
        $ntfyTopic = trim((string) Setting::get('ntfy_topic', ''));
        $callMeBotKey = trim((string) Setting::get('whatsapp_callmebot_apikey', ''));

        $message = "⚠️💳 *LOW SUPPLIER BALANCE ALERT*\n"
            ."━━━━━━━━━━━━━━━━━━\n"
            ."🏭 *Supplier:* {$supplierName}\n"
            ."💰 *Remaining Balance:* {$balance} {$currency}\n"
            ."━━━━━━━━━━━━━━━━━━\n"
            .'Please top up your supplier balance to keep auto-delivery running smoothly.';

        if ($greenId !== '' && $greenToken !== '' && $adminPhone !== '') {
            $this->sendGreenApi($greenId, $greenToken, $adminPhone, $message);
        }

        if ($ntfyTopic !== '') {
            try {
                Http::timeout(5)
                    ->withHeaders([
                        'Title' => "⚠️ Low Supplier Balance: {$supplierName} ({$balance} {$currency})",
                        'Priority' => 'high',
                        'Tags' => 'warning,credit_card',
                    ])
                    ->withBody($message, 'text/plain')
                    ->post('https://ntfy.sh/'.urlencode($ntfyTopic));
            } catch (Throwable) {
            }
        }

        if ($callMeBotKey !== '' && $adminPhone !== '') {
            try {
                Http::timeout(5)->get('https://api.callmebot.com/whatsapp.php', [
                    'phone' => $adminPhone,
                    'text' => $message,
                    'apikey' => $callMeBotKey,
                ]);
            } catch (Throwable) {
            }
        }
    }

    public function sendGreenApi(string $instanceId, string $apiToken, string $phoneDigits, string $message): bool
    {
        $this->lastError = null;
        $this->lastHttpStatus = null;

        $cleanDigits = self::formatPhone($phoneDigits);
        if ($cleanDigits === '') {
            $this->lastError = 'Empty phone number';

            return false;
        }

        $hosts = ['https://api.green-api.com'];
        if (preg_match('/^(\d{4})/', $instanceId, $m)) {
            $clusterHost = "https://{$m[1]}.api.greenapi.com";
            if (! in_array($clusterHost, $hosts, true)) {
                array_unshift($hosts, $clusterHost);
            }
        }

        foreach ($hosts as $host) {
            try {
                $response = Http::timeout(8)->post(
                    "{$host}/waInstance{$instanceId}/sendMessage/{$apiToken}",
                    [
                        'chatId' => "{$cleanDigits}@c.us",
                        'message' => $message,
                    ]
                );

                $this->lastHttpStatus = $response->status();

                if ($response->successful()) {
                    return true;
                }

                $bodyStr = mb_strimwidth(trim($response->body()), 0, 180, '...');
                $this->lastError = "HTTP {$response->status()} {$bodyStr}";
                Log::warning("Green-API non-200 ({$host}) to {$cleanDigits}: ".$this->lastError);

                // If 466 (Quota Exceeded) or 400 (Bad Request) or 401/403 (Auth), don't retry the same call on fallback host
                if (in_array($response->status(), [400, 401, 403, 466], true)) {
                    return false;
                }
            } catch (Throwable $e) {
                $this->lastError = $e->getMessage();
                Log::warning("Green-API WhatsApp error ({$host}): ".$e->getMessage());
            }
        }

        return false;
    }

    /**
     * Tests WhatsApp delivery configuration from Store Settings.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{success: bool, message: string}
     */
    public function sendTestMessage(string $rawPhone, array $overrides = []): array
    {
        $phone = self::formatPhone($rawPhone);
        if ($phone === '') {
            return [
                'success' => false,
                'message' => 'Please enter a valid WhatsApp number (e.g. 923001234567) in the Admin WhatsApp Number field first.',
            ];
        }

        $greenId = trim((string) ($overrides['greenapi_instance_id'] ?? Setting::get('greenapi_instance_id', '')));
        $greenToken = trim((string) ($overrides['greenapi_api_token'] ?? Setting::get('greenapi_api_token', '')));
        $webhookUrl = trim((string) ($overrides['whatsapp_admin_webhook_url'] ?? Setting::get('whatsapp_admin_webhook_url', '')));

        if ($greenId === '' || $greenToken === '') {
            if ($webhookUrl === '') {
                return [
                    'success' => false,
                    'message' => 'To send automatic WhatsApp receipts to customers, create a free account at green-api.com, scan your WhatsApp QR code, and paste your Green-API Instance ID & API Token here.',
                ];
            }
        }

        $storeName = (string) Setting::get('store_name', 'SellMeTools');
        $msg = "*{$storeName} — WhatsApp Auto-Delivery Active!*\n\n"
            ."Your WhatsApp gateway is connected. Customer order receipts & product credentials will now be delivered automatically to customers' WhatsApp numbers.";

        if ($greenId !== '' && $greenToken !== '') {
            $ok = $this->sendGreenApi($greenId, $greenToken, $phone, $msg);
            if ($ok) {
                return [
                    'success' => true,
                    'message' => "Test WhatsApp message sent to +{$phone} via Green-API!",
                ];
            }

            if ($this->lastHttpStatus === 466) {
                return [
                    'success' => false,
                    'message' => "Green-API Free Developer Plan Limit (HTTP 466): Green-API's free plan only allows messaging 3 phone numbers per month (which is why Admin alerts work, but new customer numbers get blocked). Upgrade your Green-API instance or use the 1-Tap Send Receipt on WhatsApp button.",
                ];
            }

            return [
                'success' => false,
                'message' => 'Green-API error: '.($this->lastError ?: 'Check that your Instance ID & Token are correct and your instance is Authorized in green-api.com.'),
            ];
        }

        try {
            $res = Http::timeout(6)->post($webhookUrl, [
                'event' => 'test_whatsapp',
                'phone' => $phone,
                'message' => $msg,
            ]);
            if ($res->successful()) {
                return [
                    'success' => true,
                    'message' => "Test WhatsApp payload sent to webhook for +{$phone}!",
                ];
            }
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Webhook error: '.$e->getMessage(),
            ];
        }

        return [
            'success' => false,
            'message' => 'WhatsApp webhook returned a non-200 response.',
        ];
    }
}
