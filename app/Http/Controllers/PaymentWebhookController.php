<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\StoreException;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Setting;
use App\Services\AutoPaymentVerifier;
use App\Services\FulfillmentService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramNotifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Throwable;

/**
 * Endpoints for automatic & 1-Tap payment verification:
 *  - POST /api/webhooks/payment-sms         (Android SMS/Notification Forwarder)
 *  - POST /api/webhooks/telegram            (Telegram Bot In-Chat 1-Tap Callback Buttons)
 *  - GET|POST /webhooks/payment/{payment}/{action} (Signed 1-Tap Approve / Decline from Auto-WhatsApp / ntfy)
 *  - GET|POST /quick-order/{order:number}   (1-Tap Approve / Decline / Manual Deliver from WhatsApp / Telegram / ntfy)
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
     * Handles:
     * 1) Admin 1-Tap inline callback_query buttons (`approve:{id}` / `reject:{id}`)
     * 2) Live Synchronized Telegram Store Bot (`store:*` callbacks, `/start`, `/shop`, search queries, and Order ID tracking)
     */
    public function telegram(Request $request, PaymentService $payments, TelegramNotifier $telegram, FulfillmentService $fulfillment): JsonResponse
    {
        $botEnabled = (string) Setting::get('telegram_store_bot_enabled', '1') === '1';

        // Handle customer text messages (/start, /shop, product search, TID submission, or Order ID lookup)
        $message = $request->input('message');
        if (is_array($message)) {
            try {
                if ($botEnabled) {
                    $this->handleStoreBotMessage($message, $telegram, $payments);
                } else {
                    $token = TelegramNotifier::cleanToken((string) Setting::get('telegram_bot_token', ''));
                    $chatId = (string) ($message['chat']['id'] ?? '');
                    $adminChatId = trim((string) Setting::get('telegram_admin_chat_id', ''));
                    if ($token !== '' && $chatId !== '' && $chatId !== $adminChatId) {
                        $telegram->send(
                            $token,
                            $chatId,
                            "⏸️ <b>Telegram Bot Shop is Currently Paused</b>\nYou can browse all tools and order with instant delivery on our official website:",
                            [[['text' => '🛍️ Open Official Store', 'url' => route('shop')]]]
                        );
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }

            return response()->json(['ok' => true]);
        }

        $callback = $request->input('callback_query');
        if (! is_array($callback)) {
            return response()->json(['ok' => true]);
        }

        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $chatId = (string) ($callback['message']['chat']['id'] ?? '');
        $messageId = (int) ($callback['message']['message_id'] ?? 0);

        // Handle customer Store Bot navigation & buying callbacks (store:*)
        if (str_starts_with($data, 'store:')) {
            try {
                if ($botEnabled) {
                    $this->handleStoreBotCallback($callback, $callbackId, $chatId, $messageId, $data, $telegram, $payments);
                } else {
                    $telegram->answerCallback($callbackId, 'Telegram Bot Shop is currently paused. Please order on our website.', true);
                }
            } catch (Throwable $e) {
                report($e);
            }

            return response()->json(['ok' => true]);
        }

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
        $supplierLine = $order ? '🏭 <b>Supplier:</b> '.TelegramNotifier::e($order->adminSupplierSummary(true))."\n" : '';
        $itemsLine = $order ? $order->items->map(fn ($i) => '📦 '.TelegramNotifier::e($i->product_name)." × {$i->quantity}")->implode("\n")."\n" : '';

        if ($order && $order->status === OrderStatus::Completed) {
            $telegram->answerCallback($callbackId, "⚠️ Order {$order->number} is ALREADY Confirmed & Delivered!");
            if ($messageId > 0) {
                $telegram->editMessage(
                    $chatId,
                    $messageId,
                    "⚠️ <b>ALREADY CONFIRMED &amp; DELIVERED</b>\n".
                    "Order: <b>{$order->number}</b>\n".
                    $itemsLine.
                    $supplierLine.
                    'TID: <code>'.TelegramNotifier::e($payment->transaction_id)."</code>\n".
                    'Status: <b>Completed</b>'
                );
            }

            return response()->json(['ok' => true]);
        }

        try {
            if ($action === 'approve') {
                $order = $this->approveAndFulfill($order, $payment, $payments, $fulfillment);

                if ($order->status === OrderStatus::Completed) {
                    $telegram->answerCallback($callbackId, "✅ Order {$order->number} Approved & Delivered!");
                    if ($messageId > 0) {
                        $telegram->editMessage(
                            $chatId,
                            $messageId,
                            "✅ <b>APPROVED &amp; DELIVERED</b>\n".
                            "Order: <b>{$order->number}</b>\n".
                            $itemsLine.
                            $supplierLine.
                            'TID: <code>'.TelegramNotifier::e($payment->transaction_id)."</code>\n".
                            "Status: <b>{$order->status->label()}</b>"
                        );
                    }
                } else {
                    $reason = $order->admin_note ?: 'Awaiting stock/supplier delivery';
                    $adminUrl = url('/admin/orders/'.$order->id);
                    $quickUrl = route('webhooks.order.quick', ['order' => $order->number, 'do' => 'approve']);
                    $telegram->answerCallback($callbackId, "✅ Payment Confirmed! ⚠️ Delivery On Hold: {$reason}");
                    if ($messageId > 0) {
                        $telegram->editMessage(
                            $chatId,
                            $messageId,
                            "✅ <b>PAYMENT APPROVED: ⚠️ DELIVERY ON HOLD</b>\n".
                            "Order: <b>{$order->number}</b>\n".
                            $itemsLine.
                            $supplierLine.
                            'TID: <code>'.TelegramNotifier::e($payment->transaction_id)."</code>\n".
                            '⚠️ <b>Reason:</b> '.TelegramNotifier::e($reason)."\n".
                            "🔗 <a href=\"{$adminUrl}\">Open in Admin Panel</a> · <a href=\"{$quickUrl}\">1-Tap Manual Deliver</a>"
                        );
                    }
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
                        $itemsLine.
                        $supplierLine.
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
     * 1-Tap WhatsApp Approve / Decline / Manual Deliver endpoint (`/quick-order/{order:number}?do=approve|reject`).
     * Secured by the Admin Secret Key (`admin_quick_pin` saved in Admin Panel -> Store Settings).
     * Never executes state changes on GET so WhatsApp Link Preview bots cannot auto-approve orders.
     */
    public function quickOrder(Request $request, Order $order, PaymentService $payments, FulfillmentService $fulfillment): View|RedirectResponse
    {
        $do = strtolower((string) ($request->input('do') ?? $request->query('do') ?? 'approve'));
        if (! in_array($do, ['approve', 'reject', 'retry', 'whatsapp_delivered'], true)) {
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

        $order->load(['items.deliveries', 'items.product', 'paymentMethod', 'latestPayment']);
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

        // Execute Approve, Retry, WhatsApp Handover, or Decline on POST
        if ($request->isMethod('post')) {
            try {
                if (in_array($do, ['approve', 'retry'], true)) {
                    $customCredentials = trim((string) $request->input('credentials', ''));
                    $order = $this->approveAndFulfill($order, $payment, $payments, $fulfillment, $customCredentials);
                    $order->load(['items.deliveries', 'items.product', 'paymentMethod', 'latestPayment']);
                    $payment = $order->latestPayment;

                    if ($order->status === OrderStatus::Completed) {
                        $actionResult = 'approve';
                        $message = "✅ Order {$order->number} Payment Confirmed & Credentials Delivered!";
                    } else {
                        $actionResult = 'needs_attention';
                        $message = '✅ Payment is Approved, BUT Delivery is On Hold: '.($order->admin_note ?: 'Check supplier balance or paste credentials below to deliver manually.');
                    }
                } elseif ($do === 'whatsapp_delivered') {
                    $customNote = trim((string) $request->input('credentials', ''));
                    if ($payment && $payment->status === PaymentStatus::Pending) {
                        $payment->update([
                            'status' => PaymentStatus::Approved,
                            'reviewed_at' => now(),
                        ]);
                        $order->update(['paid_at' => $order->paid_at ?? now()]);
                    }
                    $order = $fulfillment->markDeliveredViaWhatsApp($order, $customNote ?: null);
                    $order->load(['items.deliveries', 'items.product', 'paymentMethod', 'latestPayment']);
                    $payment = $order->latestPayment;
                    $actionResult = 'approve';
                    $message = "✅ Order {$order->number} Marked as Delivered via WhatsApp!";
                } elseif ($do === 'reject') {
                    if ($payment && $payment->status === PaymentStatus::Pending) {
                        $payments->reject($payment, 'Declined by Admin via 1-Tap Control', $request->user());
                        $message = "❌ Order {$order->number} payment has been Declined.";
                    } else {
                        $res = app(\App\Services\OrderService::class)->cancelAndRefund($order, OrderStatus::Cancelled, 'Cancelled by Admin via 1-Tap Control', $request->user()?->id);
                        $message = $res['refunded']
                            ? "❌ Order {$order->number} Cancelled & Rs {$res['amount']} automatically refunded to customer Wallet!"
                            : "❌ Order {$order->number} has been Cancelled.";
                    }
                    $order->refresh()->load(['items.deliveries', 'items.product', 'paymentMethod', 'latestPayment']);
                    $payment = $order->latestPayment;
                    $actionResult = 'reject';
                }
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
        } else {
            $message = $order->status === OrderStatus::NeedsAttention
                ? '⚠️ Payment is already Confirmed, but Delivery is Pending: '.($order->admin_note ?: 'Awaiting stock/supplier or manual delivery.')
                : 'Tap Approve or Decline below to process this order.';
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
     * Approves the payment and attempts real fulfillment via Own Stock (.txt) or Supplier API.
     * NEVER fakes delivery if Supplier API fails or has insufficient balance!
     * Only delivers custom credentials if Admin explicitly pasted them in `$customCredentials`.
     */
    protected function approveAndFulfill(
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

        // If Admin explicitly pasted custom credentials on the 1-Tap screen, deliver those units first!
        if ($customCredentials !== '') {
            $units = Product::parseStockText($customCredentials, 'auto');
            if ($units !== [] && ($firstItem = $order->items()->first())) {
                foreach (array_slice($units, 0, max(1, $firstItem->remainingQty())) as $unitContent) {
                    $firstItem->deliveries()->create(['content' => $unitContent, 'source' => 'manual']);
                    $firstItem->delivered_qty++;
                }
                $firstItem->reserved_supplier_qty = max(0, $firstItem->quantity - $firstItem->delivered_qty);
                $firstItem->status = $firstItem->delivered_qty >= $firstItem->quantity ? 'delivered' : 'partial';
                $firstItem->save();
            }
        }

        if ($payment->status === PaymentStatus::Pending) {
            $order = $payments->approve($payment);
        } elseif ($order->status !== OrderStatus::Completed) {
            $order->update(['status' => OrderStatus::Processing, 'paid_at' => $order->paid_at ?? now()]);
            $order = $fulfillment->fulfill($order);
        }

        return $order->refresh();
    }

    private function adminQuickCookieToken(): string
    {
        $pin = trim((string) Setting::get('admin_quick_pin', '7860'));

        return hash_hmac('sha256', 'smt_quick_pin:'.$pin, (string) config('app.key'));
    }

    /**
     * Handles incoming text messages to the Live Synchronized Supplier-Style Telegram Store Bot.
     * Supports browsing products, direct in-bot TID payment submission, searching, and order tracking.
     */
    private function handleStoreBotMessage(array $message, TelegramNotifier $telegram, PaymentService $payments): void
    {
        $token = TelegramNotifier::cleanToken((string) Setting::get('telegram_bot_token', ''));
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));

        if ($token === '' || $chatId === '' || $text === '') {
            return;
        }

        $lower = mb_strtolower($text, 'UTF-8');
        $storeName = TelegramNotifier::e((string) Setting::get('store_name', 'SellMeTools'));

        // 1. /start -> Pin persistent bottom keyboard buttons AND show Direct Products List with Tool Icons
        if (in_array($lower, ['/start', '/menu', '/help', 'hi', 'hello', 'salam', 'assalam o alaikum'], true) || str_starts_with($lower, '/start ')) {
            $welcomeBar = "👋 <b>Welcome to {$storeName} Official Bot!</b>\n"
                ."⚡ Direct Supplier Bot — Buy right inside Telegram with Binance, Easypaisa, or JazzCash!\n"
                ."👇 Use the menu buttons at the bottom or tap any product below:";
            $telegram->send($token, $chatId, $welcomeBar, null, TelegramNotifier::bottomMenuKeyboard());

            [$html, $keyboard] = $this->buildProductsCatalog(1, 'all');
            $telegram->send($token, $chatId, $html, $keyboard);

            return;
        }

        // 2. All Products (/products, /shop, or bottom button "🛍️ All Products")
        if (in_array($lower, ['/products', '/shop'], true) || str_contains($lower, 'all products')) {
            [$html, $keyboard] = $this->buildProductsCatalog(1, 'all');
            $telegram->send($token, $chatId, $html, $keyboard);

            return;
        }

        // 3. In-Stock Products Only (/instock, /stock, or bottom button "🟢 In-Stock Only")
        if (in_array($lower, ['/instock', '/stock'], true) || str_contains($lower, 'in-stock only') || str_contains($lower, 'in stock only')) {
            [$html, $keyboard] = $this->buildProductsCatalog(1, 'instock');
            $telegram->send($token, $chatId, $html, $keyboard);

            return;
        }

        // 4. Search Prompt (/search or bottom button "🔍 Search Product")
        if ($lower === '/search' || str_contains($lower, 'search product')) {
            Cache::forget("tg_pending_order:{$chatId}");
            $telegram->send(
                $token,
                $chatId,
                "🔍 <b>Search Any Tool or Subscription</b>\n\nType and send the tool name right now (for example: <code>ChatGPT</code>, <code>Canva</code>, <code>CapCut</code>, <code>Netflix</code>, <code>Cursor</code>, <code>Claude</code>):",
                null,
                TelegramNotifier::bottomMenuKeyboard()
            );

            return;
        }

        // 5. Track Order Prompt (/track or bottom button "🧾 Track Order")
        if ($lower === '/track' || str_contains($lower, 'track order')) {
            $telegram->send(
                $token,
                $chatId,
                "🧾 <b>Track Your Order Status</b>\n\nSend your Order ID in this chat (for example: <code>SMT-123456</code>) to check live status, or track on our website:",
                [[['text' => '🔍 Track Order on Website', 'url' => route('orders.track')]]]
            );

            return;
        }

        // 6. Visit Website (bottom button "🌐 Visit Website")
        if (in_array($lower, ['/web', '/website'], true) || str_contains($lower, 'visit website')) {
            $shopUrl = route('shop');
            $isHttps = Str::startsWith($shopUrl, 'https://');
            $telegram->send(
                $token,
                $chatId,
                "🌐 <b>{$storeName} Official Store &amp; Mini App</b>\nOpen our store directly inside Telegram as a Mini App or in your browser:",
                [
                    [$isHttps
                        ? ['text' => '📱 Open Store Mini App (In-Telegram)', 'web_app' => ['url' => $shopUrl]]
                        : ['text' => '🛍️ Open Full Web Store', 'url' => $shopUrl]],
                    [['text' => '🔑 Customer Account & Order Vault', 'url' => route('account.orders')]],
                    [['text' => '🧾 Track Order', 'url' => route('orders.track')]],
                ]
            );

            return;
        }

        // 7. Support (/support or bottom button "💬 Support")
        if (in_array($lower, ['/support', '/contact'], true) || str_contains($lower, '💬 support') || $lower === 'support') {
            $wa = admin_whatsapp_number();
            $tgUser = trim((string) Setting::get('support_telegram', ''));
            $email = trim((string) Setting::get('support_email', 'contact@sellmetools.com'));

            $html = "💬 <b>{$storeName} Customer Support</b>\n\n"
                ."Need help with an order, custom activation, or bulk reseller pricing? Reach us directly:\n"
                .($wa !== '' ? "• 📱 <b>WhatsApp:</b> +{$wa}\n" : '')
                .($tgUser !== '' ? '• ✈️ <b>Telegram:</b> '.TelegramNotifier::e($tgUser)."\n" : '')
                .($email !== '' ? '• 📧 <b>Email:</b> '.TelegramNotifier::e($email)."\n" : '');

            $kb = [];
            if ($wa !== '') {
                $kb[] = [['text' => '📲 Chat on WhatsApp Support', 'url' => "https://wa.me/{$wa}"]];
            }
            $kb[] = [['text' => '🌐 Contact Helpdesk on Website', 'url' => route('pages.show', 'contact')]];
            $kb[] = [['text' => '🛍️ Back to All Products', 'callback_data' => 'store:list:1:all']];

            $telegram->send($token, $chatId, $html, $kb);

            return;
        }

        // 8. Check if Customer is submitting a Transaction ID (TID) for a pending In-Bot Order!
        $pendingOrderId = Cache::get("tg_pending_order:{$chatId}");
        if ($pendingOrderId) {
            $pendingOrder = Order::with(['items.product', 'paymentMethod'])->find($pendingOrderId);
            if ($pendingOrder && $pendingOrder->status === OrderStatus::PendingPayment && ! $pendingOrder->isExpired()) {
                $normalizedTid = PaymentService::normalizeTid($text);
                // If input looks like a Transaction ID (alphanumeric >= 5 chars or digits) and not a short product search word
                if (strlen($normalizedTid) >= 5 && preg_match('/\d/', $normalizedTid) && ! preg_match('/^SMT-[A-Z0-9]+$/i', $text)) {
                    try {
                        // Save optional activation info if customer wrote "TID | email@gmail.com"
                        if (str_contains($text, '|') || str_contains($text, ' ')) {
                            if (preg_match('/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/', $text, $emMatch)) {
                                $pendingOrder->update(['customer_input' => $emMatch[1], 'email' => $emMatch[1]]);
                                $tidOnly = trim(str_replace($emMatch[1], '', $text), " |,-:\t\n\r");
                                if (strlen(PaymentService::normalizeTid($tidOnly)) >= 4) {
                                    $text = $tidOnly;
                                }
                            }
                        }

                        $payment = $payments->submit($pendingOrder, $text, 'Telegram:'.$chatId);
                        Cache::forget("tg_pending_order:{$chatId}");

                        $pendingOrder->refresh()->load('items.deliveries');
                        if ($pendingOrder->status === OrderStatus::Completed) {
                            return; // FulfillmentService already sent the delivered credentials to Telegram!
                        }

                        $telegram->send(
                            $token,
                            $chatId,
                            "✅ <b>Payment Submitted Successfully!</b>\n"
                            ."━━━━━━━━━━━━━━━━━━\n"
                            ."🧾 <b>Order ID:</b> <code>{$pendingOrder->number}</code>\n"
                            ."🔑 <b>TID:</b> <code>".TelegramNotifier::e($payment->transaction_id)."</code>\n"
                            ."⏳ <b>Status:</b> Admin is verifying your payment.\n\n"
                            ."🚀 <i>As soon as your payment is verified, your login credentials will be sent right here in this Telegram chat automatically!</i>",
                            [
                                [['text' => '🧾 View Live Order Page', 'url' => $pendingOrder->customerUrl()]],
                                [['text' => '🛍️ Browse More Products', 'callback_data' => 'store:list:1:all']],
                            ]
                        );

                        return;
                    } catch (StoreException $e) {
                        $telegram->send(
                            $token,
                            $chatId,
                            "⚠️ <b>Could Not Submit TID for Order {$pendingOrder->number}:</b>\n".TelegramNotifier::e($e->getMessage())."\n\nPlease send your valid Transaction ID (TID) again, or tap Cancel below:",
                            [
                                [['text' => '❌ Cancel In-Bot Payment', 'callback_data' => "store:cancel:{$pendingOrder->id}"]],
                            ]
                        );

                        return;
                    }
                }
            } else {
                Cache::forget("tg_pending_order:{$chatId}");
            }
        }

        // 9. Order ID Lookup (e.g., SMT-XXXXXX)
        if (preg_match('/\b(SMT-[A-Z0-9]{4,12})\b/i', $text, $m)) {
            $orderNum = strtoupper($m[1]);
            $order = Order::with('items')->where('number', $orderNum)->first();
            if (! $order) {
                $telegram->send($token, $chatId, "❌ No order found with ID <code>".TelegramNotifier::e($orderNum)."</code>.\nPlease double-check your Order ID.", [
                    [['text' => '🛍️ View All Products', 'callback_data' => 'store:list:1:all']],
                ]);

                return;
            }

            $items = $order->items->map(fn ($i) => '• '.TelegramNotifier::e($i->product_name).' × '.$i->quantity)->implode("\n");
            $html = "🧾 <b>Order Status: {$order->number}</b>\n\n"
                ."📌 <b>Status:</b> ".TelegramNotifier::e($order->status->label())."\n"
                ."📦 <b>Items:</b>\n{$items}\n"
                ."💰 <b>Total:</b> ".money($order->total).' ('.money_usd($order->total).")\n"
                ."🕒 <b>Date:</b> ".$order->created_at->format('d M Y, h:i A');

            $telegram->send($token, $chatId, $html, [
                [['text' => '🔍 Open Order Page on Website', 'url' => route('orders.track')]],
                [['text' => '🛍️ View All Products', 'callback_data' => 'store:list:1:all']],
            ]);

            return;
        }

        // 10. Live Product Search by Keyword
        $like = '%'.addcslashes($text, '%_').'%';
        $products = Product::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('short_description', 'like', $like))
            ->orderByRaw('CASE WHEN stock_unlimited = 1 OR available_stock > 0 THEN 0 ELSE 1 END')
            ->orderByDesc('is_featured')
            ->orderByDesc('available_stock')
            ->limit(15)
            ->get();

        if ($products->isEmpty()) {
            [$html, $keyboard] = $this->buildProductsCatalog(1, 'all', "🔍 No exact match for \"<b>".TelegramNotifier::e(Str::limit($text, 35))."</b>\". Showing all live products:");
            $telegram->send($token, $chatId, $html, $keyboard);

            return;
        }

        $html = "🔎 <b>Search Results ({$products->count()} found)</b>\n"
            ."Matching \"<b>".TelegramNotifier::e(Str::limit($text, 40))."</b>\" — tap any product below:";

        $keyboard = [];
        foreach ($products as $prod) {
            $keyboard[] = [$this->formatProductButton($prod, 1, 'all')];
        }
        $keyboard[] = [['text' => '🛍️ View All Products Catalog', 'callback_data' => 'store:list:1:all']];

        $telegram->send($token, $chatId, $html, $keyboard);
    }

    /**
     * Handles inline button taps for the Supplier-Style Telegram Store Bot (including Direct In-Bot Buying!).
     */
    private function handleStoreBotCallback(array $callback, string $callbackId, string $chatId, int $messageId, string $data, TelegramNotifier $telegram, PaymentService $payments): void
    {
        if ($data === 'store:noop') {
            $telegram->answerCallback($callbackId, 'Current page', false);

            return;
        }

        $telegram->answerCallback($callbackId, 'Loading...', false);

        // Cancel pending in-bot order
        if (preg_match('/^store:cancel:(\d+)$/', $data, $m)) {
            Cache::forget("tg_pending_order:{$chatId}");
            $order = Order::find((int) $m[1]);
            if ($order && $order->status === OrderStatus::PendingPayment) {
                app(OrderService::class)->release($order, OrderStatus::Cancelled);
            }
            [$html, $keyboard] = $this->buildProductsCatalog(1, 'all', '✅ Order cancelled. Browse all products below:');
            $telegram->editMessage($chatId, $messageId, $html, $keyboard);

            return;
        }

        // store:home or store:list:{page}:{filter}
        if ($data === 'store:home' || preg_match('/^store:list:(\d+):(all|instock)$/', $data, $m)) {
            $page = isset($m[1]) ? max(1, (int) $m[1]) : 1;
            $filter = $m[2] ?? 'all';
            [$html, $keyboard] = $this->buildProductsCatalog($page, $filter);
            $telegram->editMessage($chatId, $messageId, $html, $keyboard);

            return;
        }

        // STEP 1 OF IN-BOT BUYING: Choose Payment Method inside Telegram (store:buy:{prodId}:{page}:{filter})
        if (preg_match('/^store:buy:(\d+)(?::(\d+):(all|instock))?$/', $data, $m)) {
            $productId = (int) $m[1];
            $backPage = isset($m[2]) ? max(1, (int) $m[2]) : 1;
            $backFilter = $m[3] ?? 'all';

            $prod = Product::with('category')->where('is_active', true)->find($productId);
            if (! $prod || ! $prod->inStock()) {
                [$html, $keyboard] = $this->buildProductsCatalog($backPage, $backFilter, '⚠️ Sorry, that product is currently out of stock.');
                $telegram->editMessage($chatId, $messageId, $html, $keyboard);

                return;
            }

            $methods = PaymentMethod::query()->where('is_active', true)->orderBy('sort')->get();
            $icon = $this->productIcon($prod);
            $priceText = $prod->is_free ? '🎁 FREE (0 PKR)' : money($prod->price).' (≈ '.money_usd($prod->price).' USDT)';

            $html = "🛒 <b>Buy Directly in Bot: {$icon} ".TelegramNotifier::e($prod->name)."</b>\n"
                ."━━━━━━━━━━━━━━━━━━━━\n"
                ."💰 <b>Price:</b> {$priceText}\n\n"
                ."👇 <b>Select your Payment Method below</b> to get payment account details right here in Telegram:";

            $keyboard = [];
            foreach ($methods as $method) {
                $currTag = $method->currency === 'USDT' ? '₮ USDT' : '🇵🇰 PKR';
                $keyboard[] = [[
                    'text' => "💳 {$method->name} ({$currTag})",
                    'callback_data' => "store:pay:{$prod->id}:{$method->id}",
                ]];
            }

            $checkoutUrl = route('checkout.create', $prod->slug);
            if (Str::startsWith($checkoutUrl, 'https://')) {
                $keyboard[] = [[
                    'text' => '📱 Or Open Checkout Mini App',
                    'web_app' => ['url' => $checkoutUrl],
                ]];
            }

            $keyboard[] = [[
                'text' => '⬅️ Back to Product',
                'callback_data' => "store:prod:{$prod->id}:{$backPage}:{$backFilter}",
            ]];

            $telegram->editMessage($chatId, $messageId, $html, $keyboard);

            return;
        }

        // STEP 2 OF IN-BOT BUYING: Create Order & Show Binance / Wallet Account Details (store:pay:{prodId}:{methodId})
        if (preg_match('/^store:pay:(\d+):(\d+)$/', $data, $m)) {
            $productId = (int) $m[1];
            $methodId = (int) $m[2];

            $prod = Product::where('is_active', true)->find($productId);
            $method = PaymentMethod::where('is_active', true)->find($methodId);

            if (! $prod || ! $method || ! $prod->inStock()) {
                [$html, $keyboard] = $this->buildProductsCatalog(1, 'all', '⚠️ Product or payment method unavailable.');
                $telegram->editMessage($chatId, $messageId, $html, $keyboard);

                return;
            }

            $fromUser = $callback['from'] ?? [];
            $customerName = trim(($fromUser['first_name'] ?? 'Telegram').' '.($fromUser['last_name'] ?? 'Customer'));
            $customerUsername = ! empty($fromUser['username']) ? '@'.$fromUser['username'] : $chatId;
            $customerEmail = "tg_{$chatId}@telegram.sellmetools.com";

            try {
                $order = app(OrderService::class)->create($prod, 1, [
                    'name' => "{$customerName} ({$customerUsername})",
                    'email' => $customerEmail,
                    'phone' => null,
                ], $method);

                Cache::put("tg_order_chat:{$order->id}", $chatId, now()->addDays(30));

                if ($prod->is_free || (float) $order->total <= 0) {
                    $payments->completeFreeOrder($order);

                    return;
                }

                Cache::put("tg_pending_order:{$chatId}", $order->id, now()->addHours(2));

                $amountDue = $method->amountFor($order);
                $amountFormatted = $method->currency !== 'PKR' ? "{$amountDue} {$method->currency}" : money($amountDue);
                $accLabel = $method->code === 'binance' ? 'Binance Pay ID' : ($method->code === 'usdt_trc20' ? 'USDT (TRC20) Address' : "{$method->name} Account");
                $activationPrompt = $prod->customer_input_label;

                $html = "🧾 <b>Order Created: <code>{$order->number}</code></b>\n"
                    ."━━━━━━━━━━━━━━━━━━━━\n"
                    ."📦 <b>Product:</b> ".TelegramNotifier::e($prod->name)."\n"
                    ."💳 <b>Gateway:</b> ".TelegramNotifier::e($method->name)."\n\n"
                    ."1️⃣ <b>STEP 1 — Send Exact Payment:</b>\n"
                    ."💰 <b>Amount to Send:</b> <code>{$amountDue}</code> (<b>{$amountFormatted}</b>)\n"
                    .($method->account_number ? "🏦 <b>{$accLabel} (Tap to Copy):</b>\n<code>".TelegramNotifier::e($method->account_number)."</code>\n" : '')
                    .($method->account_title ? '👤 <b>Account Title:</b> '.TelegramNotifier::e($method->account_title)."\n" : '')
                    .($method->instructions ? 'ℹ️ <i>'.TelegramNotifier::e($method->instructions)."</i>\n" : '')
                    ."\n2️⃣ <b>STEP 2 — Send Your Transaction ID (TID):</b>\n"
                    ."👉 <b>Just type and send your Transaction ID (TID / Binance Order ID) right here in this chat!</b>\n"
                    .($activationPrompt ? "📝 <i>Note: Also include your email for activation (e.g. <code>849201948 your@email.com</code>)</i>\n" : '')
                    ."\n⚡ <i>Once verified, your credentials will be delivered right here in this chat automatically!</i>";

                $keyboard = [
                    [['text' => '🌐 Or Upload Screenshot on Order Page', 'url' => $order->customerUrl()]],
                    [['text' => '❌ Cancel Order', 'callback_data' => "store:cancel:{$order->id}"]],
                ];

                $telegram->editMessage($chatId, $messageId, $html, $keyboard);
            } catch (Throwable $e) {
                $telegram->answerCallback($callbackId, 'Error: '.$e->getMessage(), true);
            }

            return;
        }

        // store:prod:{id} or store:prod:{id}:{page}:{filter}
        if (preg_match('/^store:prod:(\d+)(?::(\d+):(all|instock))?$/', $data, $m)) {
            $productId = (int) $m[1];
            $backPage = isset($m[2]) ? max(1, (int) $m[2]) : 1;
            $backFilter = $m[3] ?? 'all';

            $prod = Product::with('category')->where('is_active', true)->find($productId);
            if (! $prod) {
                [$html, $keyboard] = $this->buildProductsCatalog($backPage, $backFilter);
                $telegram->editMessage($chatId, $messageId, $html, $keyboard);

                return;
            }

            $icon = $this->productIcon($prod);
            $priceText = $prod->is_free ? '🎁 FREE (0 PKR)' : money($prod->price).' (≈ '.money_usd($prod->price).' USDT)';
            $stockCount = $prod->displayStock();
            $stockText = $prod->inStock() ? "🟢 In Stock ({$stockCount} pcs available)" : '🔴 Out of Stock';
            $desc = trim(strip_tags((string) ($prod->short_description ?: $prod->description)));

            $html = "{$icon} <b>".TelegramNotifier::e($prod->name)."</b>\n"
                ."━━━━━━━━━━━━━━━━━━━━\n"
                ."💰 <b>Price:</b> {$priceText}\n"
                ."📦 <b>Live Stock:</b> {$stockText}\n"
                .($prod->duration ? "⏳ <b>Validity:</b> ".TelegramNotifier::e($prod->duration)."\n" : '')
                .($prod->warranty ? "🛡️ <b>Warranty:</b> ".TelegramNotifier::e($prod->warranty)."\n" : '')
                .($prod->category ? "🏷️ <b>Type:</b> ".TelegramNotifier::e($prod->category->name)."\n" : '')
                .($desc !== '' ? "\n📝 <b>Details:</b>\n".TelegramNotifier::e(Str::limit($desc, 320))."\n" : '');

            $keyboard = [];
            if ($prod->inStock()) {
                $keyboard[] = [[
                    'text' => '⚡ Buy Here in Bot (Binance / PKR) — '.($prod->is_free ? 'FREE' : money($prod->price)),
                    'callback_data' => "store:buy:{$prod->id}:{$backPage}:{$backFilter}",
                ]];

                $checkoutUrl = route('checkout.create', $prod->slug);
                if (Str::startsWith($checkoutUrl, 'https://')) {
                    $keyboard[] = [[
                        'text' => '📱 Open Checkout Mini App (In-Telegram)',
                        'web_app' => ['url' => $checkoutUrl],
                    ]];
                } else {
                    $keyboard[] = [[
                        'text' => '🛒 Buy on Website Checkout',
                        'url' => $checkoutUrl,
                    ]];
                }
            }

            $wa = admin_whatsapp_number();
            if ($wa !== '') {
                $waText = urlencode("Assalam o Alaikum! I want to buy {$prod->name} (".money($prod->price).") from SellMeTools.");
                $keyboard[] = [[
                    'text' => '📲 Buy / Ask on WhatsApp',
                    'url' => "https://wa.me/{$wa}?text={$waText}",
                ]];
            }

            $keyboard[] = [[
                'text' => '⬅️ Back to Products List',
                'callback_data' => "store:list:{$backPage}:{$backFilter}",
            ]];

            $telegram->editMessage($chatId, $messageId, $html, $keyboard);
        }
    }

    /**
     * Builds the Supplier-Style Direct Products List with Tool Icons, Live Price, Stock & Pagination.
     *
     * @return array{0: string, 1: array<int, array<int, array<string, string>>>}
     */
    private function buildProductsCatalog(int $page = 1, string $filter = 'all', ?string $customIntro = null): array
    {
        $perPage = 12;
        $storeName = TelegramNotifier::e((string) Setting::get('store_name', 'SellMeTools'));
        $usdtRate = number_format(Setting::usdtRate(), 0);

        $query = Product::with('category')->where('is_active', true);

        if ($filter === 'instock') {
            $query->where(function ($q) {
                $q->where('stock_unlimited', true)->orWhere('available_stock', '>', 0);
            });
        }

        $totalProducts = (clone $query)->count();
        $inStockCount = Product::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('stock_unlimited', true)->orWhere('available_stock', '>', 0))
            ->count();

        $totalPages = max(1, (int) ceil($totalProducts / $perPage));
        $page = min(max(1, $page), $totalPages);

        $products = $query
            ->orderByRaw('CASE WHEN stock_unlimited = 1 OR available_stock > 0 THEN 0 ELSE 1 END')
            ->orderByDesc('is_featured')
            ->orderBy('sort')
            ->orderBy('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $modeTitle = $filter === 'instock' ? '🟢 In-Stock Products' : '🛍️ All Live Products';

        $html = ($customIntro ? $customIntro."\n\n" : '')
            ."⚡ <b>{$storeName} — {$modeTitle}</b>\n"
            ."📊 <b>Total:</b> {$totalProducts} Tools · <b>In Stock:</b> 🟢 {$inStockCount} · <b>Rate:</b> 1 USDT = Rs {$usdtRate}\n"
            ."📄 <b>Page {$page} of {$totalPages}</b> — Tap any product below to view details &amp; buy:";

        $keyboard = [];
        foreach ($products as $prod) {
            $keyboard[] = [$this->formatProductButton($prod, $page, $filter)];
        }

        // Pagination Row
        if ($totalPages > 1) {
            $navRow = [];
            if ($page > 1) {
                $navRow[] = ['text' => '⬅️ Prev', 'callback_data' => 'store:list:'.($page - 1).':'.$filter];
            }
            $navRow[] = ['text' => "📄 {$page}/{$totalPages}", 'callback_data' => 'store:noop'];
            if ($page < $totalPages) {
                $navRow[] = ['text' => 'Next ➡️', 'callback_data' => 'store:list:'.($page + 1).':'.$filter];
            }
            $keyboard[] = $navRow;
        }

        // Filter & Live Refresh Row
        $toggleFilterBtn = $filter === 'instock'
            ? ['text' => '📦 Show All Products', 'callback_data' => 'store:list:1:all']
            : ['text' => "🟢 In-Stock Only ({$inStockCount})", 'callback_data' => 'store:list:1:instock'];

        $keyboard[] = [
            $toggleFilterBtn,
            ['text' => '🔄 Refresh Stock', 'callback_data' => "store:list:{$page}:{$filter}"],
        ];

        $shopUrl = route('shop');
        $keyboard[] = [
            Str::startsWith($shopUrl, 'https://')
                ? ['text' => '📱 Open Store Mini App', 'web_app' => ['url' => $shopUrl]]
                : ['text' => '🌐 Open Website Store', 'url' => $shopUrl],
            ['text' => '🧾 Track Order', 'url' => route('orders.track')],
        ];

        return [$html, $keyboard];
    }

    /**
     * Formats a single Supplier-Style Product Inline Button with Tool Icon, Price & Stock count.
     *
     * @return array{text: string, callback_data: string}
     */
    private function formatProductButton(Product $prod, int $page = 1, string $filter = 'all'): array
    {
        $icon = $this->productIcon($prod);
        $inStock = $prod->inStock();
        $stockQty = $prod->displayStock();
        $stockBadge = $inStock ? "🟢 {$stockQty}" : '🔴 0';
        $priceStr = $prod->is_free ? 'FREE' : 'Rs '.number_format((float) $prod->price, 0).' ('.money_usd($prod->price).')';
        $shortName = Str::limit(trim($prod->name), 26, '');

        return [
            'text' => "{$icon} {$shortName} • {$priceStr} [{$stockBadge}]",
            'callback_data' => "store:prod:{$prod->id}:{$page}:{$filter}",
        ];
    }

    /**
     * Returns a recognizable brand/tool emoji icon for each product (like supplier bots).
     */
    private function productIcon(Product $prod): string
    {
        $n = strtolower($prod->name);

        return match (true) {
            str_contains($n, 'chatgpt') || str_contains($n, 'openai') || str_contains($n, 'gpt') => '🤖',
            str_contains($n, 'claude') || str_contains($n, 'perplexity') || str_contains($n, 'gemini') || str_contains($n, 'midjourney') || str_contains($n, 'grok') || str_contains($n, 'deepseek') => '🧠',
            str_contains($n, 'canva') || str_contains($n, 'adobe') || str_contains($n, 'figma') || str_contains($n, 'freepik') || str_contains($n, 'envato') || str_contains($n, 'picsart') || str_contains($n, 'leonardo') => '🎨',
            str_contains($n, 'capcut') || str_contains($n, 'veo') || str_contains($n, 'kling') || str_contains($n, 'runway') || str_contains($n, 'heygen') || str_contains($n, 'invideo') || str_contains($n, 'filmora') || str_contains($n, 'vidiq') || str_contains($n, 'elevenlabs') || str_contains($n, 'suno') => '🎬',
            str_contains($n, 'netflix') || str_contains($n, 'prime') || str_contains($n, 'youtube') || str_contains($n, 'spotify') || str_contains($n, 'disney') || str_contains($n, 'iptv') || str_contains($n, 'crunchyroll') => '📺',
            str_contains($n, 'cursor') || str_contains($n, 'copilot') || str_contains($n, 'github') || str_contains($n, 'lovable') || str_contains($n, 'bolt') || str_contains($n, 'replit') || str_contains($n, 'windsurf') => '💻',
            str_contains($n, 'vpn') || str_contains($n, 'nord') || str_contains($n, 'express') || str_contains($n, 'surfshark') || str_contains($n, 'cyberghost') || str_contains($n, 'hma') || str_contains($n, 'proxy') || str_contains($n, 'antivirus') => '🛡️',
            str_contains($n, 'semrush') || str_contains($n, 'ahrefs') || str_contains($n, 'moz') || str_contains($n, 'ubersuggest') || str_contains($n, 'linkedin') || str_contains($n, 'sales navigator') => '📈',
            str_contains($n, 'grammarly') || str_contains($n, 'quillbot') || str_contains($n, 'turnitin') || str_contains($n, 'scribd') || str_contains($n, 'chegg') || str_contains($n, 'coursera') || str_contains($n, 'udemy') || str_contains($n, 'duolingo') => '📚',
            str_contains($n, 'office') || str_contains($n, 'microsoft') || str_contains($n, 'windows') || str_contains($n, 'google') || str_contains($n, 'icloud') || str_contains($n, 'aws') || str_contains($n, 'rdp') || str_contains($n, 'vps') || str_contains($n, 'cloud') => '☁️',
            str_contains($n, 'whatsapp') || str_contains($n, 'telegram') || str_contains($n, 'discord') || str_contains($n, 'zoom') => '💬',
            default => $prod->category?->icon ?: '⚡',
        };
    }
}
