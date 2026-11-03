<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
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

        // Handle customer text messages (/start, /shop, product search, or Order ID lookup)
        $message = $request->input('message');
        if (is_array($message) && $botEnabled) {
            $this->handleStoreBotMessage($message, $telegram);

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

        // Handle customer Store Bot navigation callbacks (store:home, store:cat:{id}, store:prod:{id})
        if (str_starts_with($data, 'store:') && $botEnabled) {
            $this->handleStoreBotCallback($callbackId, $chatId, $messageId, $data, $telegram);

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
                $order = $this->approveAndFulfill($order, $payment, $payments, $fulfillment);

                if ($order->status === OrderStatus::Completed) {
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
     * Handles incoming text messages to the Live Synchronized Telegram Store Bot.
     * Reads categories, products, prices, and orders directly from the live database.
     */
    private function handleStoreBotMessage(array $message, TelegramNotifier $telegram): void
    {
        $token = TelegramNotifier::cleanToken((string) Setting::get('telegram_bot_token', ''));
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));

        if ($token === '' || $chatId === '' || $text === '') {
            return;
        }

        $lower = strtolower($text);

        // 1. /start, /shop, /products, /categories, /help -> Show Main Store Menu with Live Categories
        if (in_array($lower, ['/start', '/shop', '/products', '/categories', '/menu', '/help', 'hi', 'hello', 'salam', 'assalam o alaikum'], true) || str_starts_with($lower, '/start ')) {
            [$html, $keyboard] = $this->buildStoreHomeMenu();
            $telegram->send($token, $chatId, $html, $keyboard);

            return;
        }

        // 2. Order Tracking (e.g., SMT-XXXXXX)
        if (preg_match('/\b(SMT-[A-Z0-9]{4,12})\b/i', $text, $m)) {
            $orderNum = strtoupper($m[1]);
            $order = Order::with('items')->where('number', $orderNum)->first();
            if (! $order) {
                $telegram->send($token, $chatId, "❌ No order found with ID <code>".TelegramNotifier::e($orderNum)."</code>.\nPlease double-check your Order ID.", [
                    [['text' => '🛍️ Browse All Tools', 'callback_data' => 'store:home']],
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
                [['text' => '🔍 Track Order on Website', 'url' => route('orders.track')]],
                [['text' => '🛍️ Browse Store Catalog', 'callback_data' => 'store:home']],
            ]);

            return;
        }

        // 3. Live Product Search by Keyword
        $like = '%'.addcslashes($text, '%_').'%';
        $products = Product::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('short_description', 'like', $like))
            ->orderByDesc('is_featured')
            ->orderByDesc('available_stock')
            ->limit(10)
            ->get();

        if ($products->isEmpty()) {
            [$html, $keyboard] = $this->buildStoreHomeMenu("🔍 No exact match for <b>".TelegramNotifier::e(Str::limit($text, 40))."</b>. Browse our live categories below:");
            $telegram->send($token, $chatId, $html, $keyboard);

            return;
        }

        $storeName = TelegramNotifier::e((string) Setting::get('store_name', 'SellMeTools'));
        $html = "🔎 <b>Search Results on {$storeName}</b>\n"
            ."Found <b>{$products->count()}</b> live tools matching \"<b>".TelegramNotifier::e(Str::limit($text, 40))."</b>\".\n"
            ."Tap any product below to view live price, stock &amp; instant buy link:";

        $keyboard = [];
        foreach ($products as $prod) {
            $priceLabel = $prod->is_free ? 'FREE' : money($prod->price).' ('.money_usd($prod->price).')';
            $stockBadge = $prod->inStock() ? '🟢' : '🔴';
            $keyboard[] = [[
                'text' => "{$stockBadge} {$prod->name} — {$priceLabel}",
                'callback_data' => 'store:prod:'.$prod->id,
            ]];
        }
        $keyboard[] = [['text' => '⬅️ Back to Categories', 'callback_data' => 'store:home']];

        $telegram->send($token, $chatId, $html, $keyboard);
    }

    /**
     * Handles inline button taps for the Live Synchronized Telegram Store Bot.
     */
    private function handleStoreBotCallback(string $callbackId, string $chatId, int $messageId, string $data, TelegramNotifier $telegram): void
    {
        $telegram->answerCallback($callbackId, 'Loading...', false);

        if ($data === 'store:home') {
            [$html, $keyboard] = $this->buildStoreHomeMenu();
            $telegram->editMessage($chatId, $messageId, $html, $keyboard);

            return;
        }

        if (preg_match('/^store:cat:(\d+)$/', $data, $m)) {
            $categoryId = (int) $m[1];
            $category = Category::query()->where('is_active', true)->find($categoryId);
            if (! $category) {
                [$html, $keyboard] = $this->buildStoreHomeMenu();
                $telegram->editMessage($chatId, $messageId, $html, $keyboard);

                return;
            }

            $products = Product::query()
                ->where('is_active', true)
                ->where('category_id', $category->id)
                ->orderByDesc('is_featured')
                ->orderBy('sort')
                ->limit(25)
                ->get();

            $html = "📂 <b>".TelegramNotifier::e($category->name)."</b>\n"
                .($category->description ? TelegramNotifier::e(Str::limit(strip_tags($category->description), 160))."\n\n" : "\n")
                ."Select any product below for live pricing, stock &amp; instant checkout:";

            $keyboard = [];
            foreach ($products as $prod) {
                $priceLabel = $prod->is_free ? 'FREE' : money($prod->price).' ('.money_usd($prod->price).')';
                $stockBadge = $prod->inStock() ? '🟢' : '🔴';
                $keyboard[] = [[
                    'text' => "{$stockBadge} {$prod->name} — {$priceLabel}",
                    'callback_data' => 'store:prod:'.$prod->id,
                ]];
            }

            $keyboard[] = [
                ['text' => '⬅️ All Categories', 'callback_data' => 'store:home'],
                ['text' => '🌐 View on Web', 'url' => route('categories.show', $category->slug)],
            ];

            $telegram->editMessage($chatId, $messageId, $html, $keyboard);

            return;
        }

        if (preg_match('/^store:prod:(\d+)$/', $data, $m)) {
            $productId = (int) $m[1];
            $prod = Product::with('category')->where('is_active', true)->find($productId);
            if (! $prod) {
                [$html, $keyboard] = $this->buildStoreHomeMenu();
                $telegram->editMessage($chatId, $messageId, $html, $keyboard);

                return;
            }

            $priceText = $prod->is_free ? '🎁 FREE (0 PKR)' : money($prod->price).' (≈ '.money_usd($prod->price).' USDT)';
            $stockCount = $prod->displayStock();
            $stockText = $prod->inStock() ? "✅ In Stock ({$stockCount} available)" : '❌ Out of Stock';
            $desc = trim(strip_tags((string) ($prod->short_description ?: $prod->description)));

            $html = "⚡ <b>".TelegramNotifier::e($prod->name)."</b>\n"
                .($prod->category ? "📂 <b>Category:</b> ".TelegramNotifier::e($prod->category->name)."\n" : '')
                ."💰 <b>Price:</b> {$priceText}\n"
                ."📦 <b>Stock:</b> {$stockText}\n"
                .($prod->duration ? "⏳ <b>Validity:</b> ".TelegramNotifier::e($prod->duration)."\n" : '')
                .($prod->warranty ? "🛡️ <b>Warranty:</b> ".TelegramNotifier::e($prod->warranty)."\n" : '')
                .($desc !== '' ? "\n📝 ".TelegramNotifier::e(Str::limit($desc, 260))."\n" : '');

            $keyboard = [];
            if ($prod->inStock()) {
                $keyboard[] = [[
                    'text' => '🛒 Buy Now — '.$priceText,
                    'url' => route('checkout.create', $prod->slug),
                ]];
            }
            $keyboard[] = [[
                'text' => '🌐 View Full Details on Website',
                'url' => route('products.show', $prod->slug),
            ]];

            $backData = $prod->category_id ? 'store:cat:'.$prod->category_id : 'store:home';
            $keyboard[] = [
                ['text' => '⬅️ Back', 'callback_data' => $backData],
                ['text' => '🏠 Main Menu', 'callback_data' => 'store:home'],
            ];

            $telegram->editMessage($chatId, $messageId, $html, $keyboard);
        }
    }

    /**
     * @return array{0: string, 1: array<int, array<int, array<string, string>>>}
     */
    private function buildStoreHomeMenu(?string $customIntro = null): array
    {
        $storeName = TelegramNotifier::e((string) Setting::get('store_name', 'SellMeTools'));
        $tagline = TelegramNotifier::e((string) Setting::get('tagline', 'Instant Digital Tools & Subscriptions'));
        $totalActiveProducts = Product::query()->where('is_active', true)->count();

        $categories = Category::query()
            ->where('is_active', true)
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort')
            ->get();

        $html = ($customIntro ? $customIntro."\n\n" : '')
            ."👋 <b>Welcome to {$storeName} Official Bot!</b>\n"
            ."<i>{$tagline}</i>\n\n"
            ."✨ <b>{$totalActiveProducts} Live Products</b> synced automatically with our website.\n"
            ."💡 <b>How to use:</b>\n"
            ."• Tap a <b>Category</b> below to browse products &amp; live prices\n"
            ."• Type any tool name (e.g. <code>ChatGPT</code>, <code>Canva</code>) to search\n"
            ."• Send your Order ID (e.g. <code>SMT-123456</code>) to track your order";

        $keyboard = [];
        $row = [];
        foreach ($categories as $cat) {
            if ($cat->products_count <= 0) {
                continue;
            }
            $icon = $cat->icon ?: '📦';
            $row[] = [
                'text' => "{$icon} {$cat->name} ({$cat->products_count})",
                'callback_data' => 'store:cat:'.$cat->id,
            ];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $keyboard[] = $row;
        }

        $keyboard[] = [
            ['text' => '🛍️ Open Full Web Store', 'url' => route('shop')],
            ['text' => '🔍 Track Order', 'url' => route('orders.track')],
        ];

        $wa = admin_whatsapp_number();
        if ($wa !== '') {
            $keyboard[] = [
                ['text' => '📲 WhatsApp Support', 'url' => "https://wa.me/{$wa}"],
            ];
        }

        return [$html, $keyboard];
    }
}
