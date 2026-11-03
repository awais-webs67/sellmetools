@php
    use App\Enums\OrderStatus;
    $s = $order->status;
    $method = $order->paymentMethod;
    $statusUrl = route('orders.status', ['order' => $order->number, 'token' => $token]);
    $rejected = $order->latestPayment?->status === \App\Enums\PaymentStatus::Rejected ? $order->latestPayment : null;
    $steps = [
        ['Placed', true],
        ['Paid', in_array($s, [OrderStatus::PaymentReview, OrderStatus::Processing, OrderStatus::Completed, OrderStatus::NeedsAttention], true)],
        ['Verified', in_array($s, [OrderStatus::Processing, OrderStatus::Completed, OrderStatus::NeedsAttention], true)],
        ['Delivered', $s === OrderStatus::Completed],
    ];

    $gatewayCheckoutUrl = $method ? app(\App\Services\AutoPaymentVerifier::class)->gatewayCheckoutUrl($order, $method) : null;
    $waNumber = admin_whatsapp_number($method?->meta['whatsapp_number'] ?? null);

    $itemsSummary = $order->items->map(fn ($i) => $i->product_name.' x'.$i->quantity)->implode(', ');
    $amountFormatted = $method && $method->currency !== 'PKR' ? $amountDue.' '.$method->currency : money($amountDue ?? $order->total);
    $isBinance = $method && in_array($method->code, ['binance', 'usdt_trc20'], true);

    $waTemplate = "Assalam o Alaikum! Here are my order details on ".setting('store_name').":\n\n"
        ."🧾 *Order ID:* {$order->number}\n"
        ."📦 *Product:* {$itemsSummary}\n"
        ."💰 *Amount:* {$amountFormatted}\n"
        ."💳 *Method:* ".($method?->name ?? 'Manual')."\n"
        ."🔑 *TID:* ".($order->latestPayment?->transaction_id ?? 'Pending')."\n"
        .($order->latestPayment?->sender_number || $order->phone ? "📱 *Sender:* ".($order->latestPayment?->sender_number ?: $order->phone)."\n" : '')
        ."📧 *Email:* {$order->email}\n"
        ."🔗 *Order Link:* ".$order->customerUrl();

    $waHref = $waNumber !== ''
        ? "https://wa.me/{$waNumber}?text=".urlencode($waTemplate)
        : 'https://api.whatsapp.com/send?text='.urlencode($waTemplate);

    $initialReviewTimeout = $s === OrderStatus::PaymentReview && (bool) $order->expires_at?->isPast();
    $customerInputLabel = $order->requiredCustomerInputLabel();
    $receiptUrl = route('orders.receipt', ['order' => $order->number, 'token' => $token]);
    $pdfReceiptUrl = route('orders.receipt', ['order' => $order->number, 'token' => $token, 'download' => 'pdf']);
@endphp
<x-layouts.store :title="'Order '.$order->number" robots="noindex, nofollow">
    <div class="container-x max-w-5xl py-6 sm:py-10" x-data="orderWatcher(@js($statusUrl), @js($s->value), @js($initialReviewTimeout))">
        {{-- Compact Header --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <span class="rounded-md bg-ink px-2.5 py-0.5 font-mono text-xs font-bold text-white">{{ $order->number }}</span>
                    <span class="text-xs text-muted">{{ $order->created_at->format('d M Y, h:i A') }}</span>
                </div>
                <h1 class="display mt-1.5 text-3xl sm:text-4xl text-ink">
                    @switch($s)
                        @case(OrderStatus::PendingPayment) Complete your payment @break
                        @case(OrderStatus::PaymentReview)
                            <span x-show="!reviewTimeout">Admin is Verifying Your Payment</span>
                            <span x-show="reviewTimeout" x-cloak>Order On Hold</span>
                            @break
                        @case(OrderStatus::Processing) Delivering your order @break
                        @case(OrderStatus::Completed) Order Delivered @break
                        @case(OrderStatus::NeedsAttention) Order Confirmed @break
                        @default Order {{ strtolower($s->label()) }}
                    @endswitch
                </h1>
            </div>

            {{-- Clean 4-Step Pill Progress --}}
            @unless (in_array($s, [OrderStatus::Cancelled, OrderStatus::Expired, OrderStatus::Refunded], true))
                <ol class="flex items-center gap-1.5 sm:gap-2" aria-label="Order progress">
                    @foreach ($steps as $i => [$label, $done])
                        <li class="flex items-center gap-1.5">
                            <span @class([
                                'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold transition',
                                'bg-ink text-white' => $done,
                                'bg-grey text-muted' => ! $done,
                            ])>
                                <span>{{ $done ? '✓' : $i + 1 }}</span>
                                <span class="hidden sm:inline">{{ $label }}</span>
                            </span>
                            @if (! $loop->last)
                                <span @class(['h-0.5 w-3 sm:w-5 rounded-full', 'bg-ink' => $done, 'bg-line-strong' => ! $done])></span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endunless
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_330px]">
            <div class="min-w-0 space-y-5">
                {{-- ============ 1. AWAITING PAYMENT ============ --}}
                @if ($s === OrderStatus::PendingPayment && $method)
                    @if ($rejected)
                        @php
                            $customerRejectReason = $rejected->reject_reason;
                            if ($customerRejectReason && str_contains(strtolower($customerRejectReason), 'declined by admin')) {
                                $customerRejectReason = 'Please check your Transaction ID (TID) and submit a valid payment reference.';
                            }
                        @endphp
                        <div class="rounded-xl border border-danger/25 bg-danger-tint p-4 text-xs sm:text-sm text-danger" role="alert">
                            <p class="font-bold">TID ({{ $rejected->transaction_id }}) could not be verified.</p>
                            @if ($customerRejectReason)<p class="mt-0.5">{{ $customerRejectReason }}</p>@endif
                        </div>
                    @endif

                    @php
                        $authUser = auth()->user();
                        $userWallet = (float) ($authUser?->wallet_balance ?? 0);
                    @endphp
                    @if ($authUser && $userWallet >= (float) $order->total)
                        <div class="surface-banner flex flex-col gap-3 rounded-2xl border border-[#22c55e]/40 p-4 sm:p-5 text-white shadow-md sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#22c55e]/20 text-lg">👛</span>
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm sm:text-base font-bold text-white">Pay Directly with Wallet</span>
                                        <span class="rounded-full bg-[#22c55e]/20 px-2 py-0.5 text-[10px] font-bold text-[#4ade80]">⚡ No Transaction ID Needed</span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-white/75">
                                        Your Wallet Balance: <strong class="font-mono text-white">{{ money($userWallet) }}</strong> · Skip manual transfer &amp; unlock credentials immediately.
                                    </p>
                                </div>
                            </div>
                            <form method="post" action="{{ route('orders.pay-wallet', $order) }}" class="shrink-0">
                                @csrf
                                <input type="hidden" name="token" value="{{ $token }}">
                                <button type="submit" class="btn bg-[#16a34a] text-white hover:bg-[#15803d] w-full sm:w-auto px-5 py-2.5 text-xs sm:text-sm font-bold shadow-sm">
                                    ⚡ 1-Click Instant Wallet Pay →
                                </button>
                            </form>
                        </div>
                    @endif

                    <section class="card overflow-hidden">
                        {{-- Top Dark Strip with Timer --}}
                        <div class="flex items-center justify-between gap-3 bg-ink px-5 py-3.5 text-white" x-data="countdown(@js($order->expires_at?->toIso8601String()))">
                            <div class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full bg-[#4ade80] animate-pulse"></span>
                                <span class="text-xs sm:text-sm font-semibold">Pay via {{ $method->name }}</span>
                            </div>
                            <span class="rounded-md bg-white/15 px-2.5 py-0.5 font-mono text-sm font-bold text-white tabular-nums" x-text="text">--:--</span>
                        </div>

                        <div class="p-5 sm:p-6 space-y-5">
                            @if ($gatewayCheckoutUrl)
                                <a href="{{ $gatewayCheckoutUrl }}" target="_blank" rel="noopener" class="btn btn-primary w-full py-3 text-sm font-bold">
                                    Pay {{ $amountFormatted }} Online via {{ $method->name }} →
                                </a>
                            @endif

                            {{-- STEP 1: High-Contrast Dark Transfer Box (Amount + Account Number) --}}
                            <div class="space-y-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="rounded bg-ink px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white">Step 1</span>
                                    <span class="text-xs sm:text-sm font-bold text-ink">Copy Details &amp; Send Payment</span>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="surface-banner rounded-xl border border-white/15 p-4 text-white flex items-center justify-between gap-3 shadow-md" x-data="copy(@js((string) $amountDue))">
                                        <div>
                                            <span class="block text-[10px] font-bold uppercase tracking-wider text-white/65">Exact Amount</span>
                                            <span class="display mt-0.5 block text-3xl text-[#4ade80]">{{ $amountFormatted }}</span>
                                        </div>
                                        <button type="button" class="btn btn-hero-primary px-3.5 py-2 text-xs font-bold shrink-0" @click="copy" x-text="copied ? '✓ Copied!' : '📋 Copy'">📋 Copy</button>
                                    </div>

                                    @if ($method->account_number && $method->account_number !== 'Set in admin')
                                        <div class="surface-banner rounded-xl border border-white/15 p-4 text-white flex items-center justify-between gap-3 shadow-md" x-data="copy(@js($method->account_number))">
                                            <div class="min-w-0">
                                                <span class="block text-[10px] font-bold uppercase tracking-wider text-white/65">
                                                    {{ $method->code === 'binance' ? 'Binance Pay ID' : ($method->code === 'usdt_trc20' ? 'USDT TRC20 Address' : $method->name.' Account') }}
                                                </span>
                                                <span class="mt-0.5 block font-mono text-base sm:text-lg font-bold text-white break-all">{{ $method->account_number }}</span>
                                                @if ($method->account_title && $method->account_title !== 'Set in admin')
                                                    <span class="block text-xs text-white/75 truncate">Title: {{ $method->account_title }}</span>
                                                @endif
                                            </div>
                                            <button type="button" class="btn btn-hero-primary px-3.5 py-2 text-xs font-bold shrink-0" @click="copy" x-text="copied ? '✓ Copied!' : '📋 Copy'">📋 Copy</button>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            @if ($method->instructions)
                                <p class="rounded-lg bg-grey-soft px-3.5 py-2.5 text-xs text-body">{{ $method->instructions }}</p>
                            @endif

                            {{-- STEP 2: Highlighted TID Submission Box --}}
                            <form method="post"
                                  action="{{ route('orders.payment', $order) }}"
                                  enctype="multipart/form-data"
                                  class="rounded-2xl border-2 border-ink/15 bg-grey-soft/70 p-4 sm:p-5 space-y-4"
                                  x-data="paymentSubmitter()"
                                  @submit="startSubmit()">
                                @csrf
                                <input type="hidden" name="token" value="{{ $token }}">

                                <div class="flex items-center gap-2">
                                    <span class="rounded bg-[#16a34a] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white">Step 2</span>
                                    <span class="text-xs sm:text-sm font-bold text-ink">Enter Transaction ID (TID)</span>
                                </div>

                                <div class="grid gap-3.5 sm:grid-cols-2">
                                    <div @class(['sm:col-span-2' => ! $method->requires_sender_number])>
                                        <label for="transaction_id" class="label text-xs font-semibold">
                                            {{ $isBinance ? 'Binance Order ID / TxID' : 'Transaction ID (TID)' }} <span class="text-danger">*</span>
                                        </label>
                                        <input id="transaction_id"
                                               name="transaction_id"
                                               required
                                               minlength="3"
                                               class="input font-mono uppercase text-sm py-2.5"
                                               value="{{ old('transaction_id') }}"
                                               placeholder="{{ $isBinance ? 'e.g. 293847561029' : 'e.g. 84920194821' }}"
                                               autocomplete="off">
                                        @error('transaction_id')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                    </div>

                                    @if ($method->requires_sender_number)
                                        <div>
                                            <label for="sender_number" class="label text-xs font-semibold">Sender Number <span class="text-danger">*</span></label>
                                            <input id="sender_number" name="sender_number" required class="input text-sm py-2.5" value="{{ old('sender_number', $order->phone) }}" placeholder="03001234567" inputmode="tel">
                                            @error('sender_number')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                        </div>
                                    @endif

                                    <div class="sm:col-span-2">
                                        <label for="screenshot" class="label text-xs font-semibold text-muted">Payment Screenshot (Optional)</label>
                                        <input id="screenshot" name="screenshot" type="file" accept="image/*" class="input py-1.5 text-xs file:mr-2.5 file:rounded file:border-0 file:bg-ink file:px-2.5 file:py-1 file:text-xs file:font-semibold file:text-white">
                                        @error('screenshot')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                    </div>

                                    @if ($customerInputLabel)
                                        <div class="sm:col-span-2 rounded-xl border border-amber-500/40 bg-amber-50 p-3.5">
                                            <label for="customer_input" class="label text-xs font-bold text-ink">
                                                📝 {{ $customerInputLabel }} <span class="text-danger">*</span>
                                            </label>
                                            <textarea id="customer_input"
                                                      name="customer_input"
                                                      rows="2"
                                                      required
                                                      class="input mt-1 text-xs sm:text-sm"
                                                      placeholder="Enter your activation details here (e.g., Email / Username / Account ID)...">{{ old('customer_input', $order->customer_input) }}</textarea>
                                            <p class="mt-1 text-[11px] text-muted">Admin will use these details to activate and deliver your subscription.</p>
                                            @error('customer_input')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                        </div>
                                    @endif
                                </div>

                                <div x-show="submitting" x-cloak class="rounded-xl border border-line bg-grey-soft p-3">
                                    <div class="flex items-center justify-between text-xs font-bold text-ink">
                                        <span x-text="stepLabel">Verifying...</span>
                                        <span class="tabular-nums" x-text="percent + '%'">0%</span>
                                    </div>
                                    <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-line">
                                        <div class="h-full rounded-full bg-ink transition-all duration-300" :style="'width: ' + percent + '%'"></div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary btn-lg w-full text-sm sm:text-base font-bold" :disabled="submitting">
                                    <span x-show="!submitting">Verify Payment &amp; Get Product →</span>
                                    <span x-show="submitting" x-cloak x-text="'Verifying (' + percent + '%)...'">Verifying...</span>
                                </button>
                            </form>
                        </div>
                    </section>

                {{-- ============ 2. VERIFYING / PROCESSING (CLEAN, MINIMAL CARD) ============ --}}
                @elseif (in_array($s, [OrderStatus::PaymentReview, OrderStatus::Processing], true))
                    <section class="card overflow-hidden">
                        @if ($s === OrderStatus::PaymentReview && $order->expires_at)
                            <div x-show="!reviewTimeout"
                                 class="flex items-center justify-between gap-3 bg-ink px-5 py-3 text-white"
                                 x-data="countdown(@js($order->expires_at->toIso8601String()))"
                                 x-effect="if (remaining === 0) { reviewTimeout = true; }">
                                <span class="text-xs font-semibold">⏳ Verification in Progress</span>
                                <span class="font-mono text-sm font-bold tabular-nums" x-text="text">--:--</span>
                            </div>
                        @endif

                        <div class="p-6 sm:p-8 text-center">
                            <div class="mx-auto relative flex h-16 w-16 items-center justify-center">
                                <div class="absolute inset-0 animate-spin rounded-full border-4 border-line border-t-ink" aria-hidden="true"></div>
                                <span class="font-mono text-xs font-bold text-ink tabular-nums" x-text="progress + '%'">85%</span>
                            </div>

                            <h2 class="mt-4 text-xl sm:text-2xl font-bold text-ink">
                                <span x-show="!reviewTimeout">{{ $s === OrderStatus::PaymentReview ? 'Admin is Verifying Your Payment' : 'Delivering Your Credentials' }}</span>
                                <span x-show="reviewTimeout" x-cloak>Priority Verification in Progress</span>
                            </h2>

                            <p class="mt-1 text-xs sm:text-sm text-muted">
                                TID <strong class="font-mono text-ink">{{ $order->latestPayment?->transaction_id }}</strong> received · This page unlocks automatically once approved.
                            </p>

                            <div class="mx-auto mt-5 max-w-md">
                                <div class="h-2 w-full overflow-hidden rounded-full bg-line">
                                    <div class="h-full rounded-full bg-ink transition-all duration-500" :style="'width: ' + progress + '%'"></div>
                                </div>

                                <div class="mt-4 grid grid-cols-3 gap-2 text-[11px] font-semibold">
                                    <div class="rounded-lg bg-grey-soft py-2 px-2 text-success">✓ Reserved</div>
                                    <div class="rounded-lg bg-grey-soft py-2 px-2 text-success">✓ TID Sent</div>
                                    <div class="rounded-lg bg-grey-soft py-2 px-2 text-ink">⟳ <span x-text="progress + '%'">85%</span></div>
                                </div>
                            </div>

                            @if ($waNumber !== '')
                                <div class="mt-6 pt-5 border-t border-line">
                                    <a href="{{ $waHref }}"
                                       target="_blank"
                                       rel="noopener"
                                       class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] w-full sm:w-auto px-6 py-2.5 text-xs sm:text-sm font-bold shadow-xs">
                                        📲 Instant Approval on WhatsApp →
                                    </a>
                                </div>
                            @endif
                        </div>
                    </section>

                {{-- ============ 3. CONFIRMED / WHATSAPP HANDOVER ============ --}}
                @elseif ($s === OrderStatus::NeedsAttention)
                    @php
                        $primaryDeliveryType = $order->items->first()?->product?->delivery_type ?: 'auto';
                        $isWhatsAppHandover = $primaryDeliveryType === 'whatsapp_handover';

                        $claimWaTemplate = "Assalam o Alaikum! ✅ My Order *{$order->number}* ({$itemsSummary}) on ".setting('store_name')." is CONFIRMED!\n\n"
                            ."🧾 *Order ID:* {$order->number}\n"
                            ."📦 *Product:* {$itemsSummary}\n"
                            ."💰 *Amount:* {$amountFormatted}\n"
                            ."🔑 *TID:* ".($order->latestPayment?->transaction_id ?? 'Verified')."\n"
                            ."📧 *Email:* {$order->email}\n\n"
                            ."Please deliver my order.\n"
                            ."🔗 *Order Link:* ".$order->customerUrl();

                        $claimWaHref = $waNumber !== ''
                            ? "https://wa.me/{$waNumber}?text=".urlencode($claimWaTemplate)
                            : 'https://api.whatsapp.com/send?text='.urlencode($claimWaTemplate);
                    @endphp
                    <section class="card p-6 sm:p-8 text-center">
                        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-success text-2xl text-white">✓</span>
                        <h2 class="mt-4 text-xl sm:text-2xl font-bold text-ink">
                            {{ $isWhatsAppHandover ? 'Order Confirmed — Receive on WhatsApp' : 'Payment Verified — Preparing Delivery' }}
                        </h2>
                        <p class="mt-1 text-xs sm:text-sm text-muted max-w-md mx-auto">
                            {{ $isWhatsAppHandover ? 'Tap the button below to receive your product directly on WhatsApp.' : 'Your access details are being dispatched. This page updates automatically.' }}
                        </p>

                        @if ($waNumber !== '')
                            <div class="mt-6">
                                <a href="{{ $claimWaHref }}"
                                   target="_blank"
                                   rel="noopener"
                                   class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] w-full sm:w-auto px-7 py-3 text-sm font-bold shadow-sm">
                                    📲 {{ $isWhatsAppHandover ? 'Receive Order on WhatsApp →' : 'Message Admin on WhatsApp →' }}
                                </a>
                            </div>
                        @endif
                    </section>

                {{-- ============ 4. COMPLETED / DELIVERED CREDENTIALS VAULT ============ --}}
                @elseif ($s === OrderStatus::Completed)
                    @php
                        $allDeliveredText = $order->items->map(function ($it) {
                            $creds = $it->deliveries->pluck('content')->implode("\n");
                            return "Product: {$it->product_name} (x{$it->quantity})\nCredentials:\n{$creds}";
                        })->implode("\n\n");
                        $fullReceiptText = "=== ".setting('store_name', 'SellMeTools')." Receipt ===\n"
                            ."Order ID: {$order->number}\n"
                            ."Status: Delivered\n\n"
                            .$allDeliveredText;
                    @endphp

                    <section class="card overflow-hidden">
                        <div class="bg-[#0F291E] px-5 py-4 sm:px-6 text-white flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#22c55e] text-xs font-bold text-black">✓</span>
                                <div>
                                    <h2 class="text-sm sm:text-base font-bold text-white">Your Access Credentials</h2>
                                </div>
                            </div>
                            <div x-data="copy(@js($fullReceiptText))">
                                <button type="button" @click="copy" class="rounded-lg bg-white/15 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/25" x-text="copied ? '✓ Copied All' : 'Copy All'">Copy All</button>
                            </div>
                        </div>

                        <div class="divide-y divide-line p-5 sm:p-6 space-y-5">
                            @foreach ($order->items as $item)
                                <div class="{{ ! $loop->first ? 'pt-5' : '' }}">
                                    <div class="flex items-center justify-between gap-2 mb-3">
                                        <h3 class="text-base font-bold text-ink">{{ $item->product_name }} <span class="text-xs font-normal text-muted">× {{ $item->quantity }}</span></h3>
                                        <span class="badge badge-success text-[11px]">Delivered</span>
                                    </div>

                                    <div class="space-y-3">
                                        @foreach ($item->deliveries as $idx => $delivery)
                                            @php
                                                $rawContent = trim($delivery->content);
                                                $parts = null;
                                                if (! str_contains($rawContent, "\n") && preg_match('/^([^\s:|]+@[^\s:|]+)[:|](.+)$/', $rawContent, $m)) {
                                                    $parts = ['Email / Login' => trim($m[1]), 'Password' => trim($m[2])];
                                                }
                                            @endphp
                                            <div class="rounded-xl bg-ink p-4 text-white">
                                                <div class="flex items-center justify-between gap-2 pb-2.5 border-b border-white/10">
                                                    <span class="text-[11px] font-bold uppercase tracking-wider text-white/70">
                                                        🔑 Access {{ $item->deliveries->count() > 1 ? '#'.($idx + 1) : 'Details' }}
                                                    </span>
                                                    <div x-data="copy(@js($rawContent))">
                                                        <button type="button" @click="copy" class="rounded bg-white/15 px-2.5 py-1 text-[11px] font-bold text-white hover:bg-white/25" x-text="copied ? '✓ Copied!' : 'Copy'">Copy</button>
                                                    </div>
                                                </div>

                                                @if ($parts)
                                                    <div class="mt-3 grid gap-2.5 sm:grid-cols-2">
                                                        @foreach ($parts as $pLabel => $pVal)
                                                            <div class="flex items-center justify-between gap-2 rounded-lg bg-white/10 px-3 py-2" x-data="copy(@js($pVal))">
                                                                <div class="min-w-0">
                                                                    <p class="text-[10px] uppercase tracking-wider text-white/60">{{ $pLabel }}</p>
                                                                    <p class="font-mono text-xs sm:text-sm font-bold text-white break-all">{{ $pVal }}</p>
                                                                </div>
                                                                <button type="button" @click="copy" class="shrink-0 rounded bg-white/15 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-white/25" x-text="copied ? '✓' : 'Copy'">Copy</button>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <pre class="mt-3 font-mono text-xs sm:text-sm leading-relaxed whitespace-pre-wrap break-all text-[#4ade80]">{{ $rawContent }}</pre>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>

                                    @if ($item->product?->warranty)
                                        <div class="mt-3 rounded-lg bg-grey-soft p-3 text-xs text-body">
                                            <strong class="text-ink">Warranty:</strong> {{ $item->product->warranty }}
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>

                {{-- ============ 5. CLOSED / EXPIRED / REFUNDED ============ --}}
                @else
                    @php
                        $walletRefundTx = \App\Models\WalletTransaction::query()
                            ->where('order_id', $order->id)
                            ->where('type', 'order_refund')
                            ->first();
                    @endphp
                    <section class="card p-6 sm:p-8 text-center">
                        <h2 class="text-lg sm:text-xl font-bold text-ink">Order {{ strtolower($s->label()) }}</h2>
                        @if ($walletRefundTx)
                            <div class="mx-auto mt-3 max-w-md rounded-xl border border-success/30 bg-success-tint p-3.5 text-xs sm:text-sm font-semibold text-success">
                                ✓ {{ money((float) $walletRefundTx->amount) }} has been automatically refunded to your Account Wallet Balance!
                            </div>
                        @endif
                        @if ($product = $order->items->first()?->product)
                            <a href="{{ route('products.show', $product) }}" class="btn btn-primary mt-4">Order Again</a>
                        @endif
                    </section>
                @endif

                @if ($customerInputLabel && in_array($s, [OrderStatus::PaymentReview, OrderStatus::Processing, OrderStatus::NeedsAttention, OrderStatus::Completed], true))
                    <section class="card border-2 {{ filled($order->customer_input) ? 'border-success/30' : 'border-amber-500' }} p-5 sm:p-6">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="flex h-8 w-8 items-center justify-center rounded-lg {{ filled($order->customer_input) ? 'bg-success text-white' : 'bg-amber-500 text-black' }} text-sm font-bold">
                                    {{ filled($order->customer_input) ? '✓' : '📝' }}
                                </span>
                                <div>
                                    <h3 class="text-sm sm:text-base font-bold text-ink">{{ $customerInputLabel }}</h3>
                                    <p class="text-xs text-muted">
                                        {{ filled($order->customer_input) ? 'Submitted to Admin for custom activation.' : 'Required so Admin can activate and deliver your subscription.' }}
                                    </p>
                                </div>
                            </div>
                            @if (filled($order->customer_input))
                                <span class="badge badge-success text-[11px]">Sent to Admin</span>
                            @else
                                <span class="rounded-full bg-amber-500/20 px-2.5 py-0.5 text-[11px] font-bold text-amber-800">Action Required</span>
                            @endif
                        </div>

                        <form method="post" action="{{ route('orders.customer-input', $order) }}" class="mt-3.5 space-y-3">
                            @csrf
                            <input type="hidden" name="token" value="{{ $token }}">
                            <textarea name="customer_input"
                                      rows="2"
                                      required
                                      class="input text-xs sm:text-sm font-mono"
                                      placeholder="Enter the required details here (e.g., your Email / Account Info)...">{{ old('customer_input', $order->customer_input) }}</textarea>
                            @error('customer_input')<p class="field-error text-xs">{{ $message }}</p>@enderror
                            <div class="flex justify-end">
                                <button type="submit" class="btn btn-primary px-5 py-2 text-xs font-bold">
                                    {{ filled($order->customer_input) ? 'Update Activation Details →' : 'Submit Details for Activation →' }}
                                </button>
                            </div>
                        </form>
                    </section>
                @endif

                @if ($s === OrderStatus::PendingPayment)
                    <form method="post" action="{{ route('orders.cancel', $order) }}" onsubmit="return confirm('Cancel this order?')">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <button class="text-xs text-muted hover:text-danger underline">Cancel order</button>
                    </form>
                @endif
            </div>

            {{-- RIGHT SIDEBAR: Compact Summary --}}
            <aside class="min-w-0 lg:sticky lg:top-24 lg:self-start">
                <div class="card p-5">
                    <h2 class="text-sm font-bold uppercase tracking-wider text-muted">Order Summary</h2>
                    <ul class="mt-3 space-y-2 text-xs sm:text-sm">
                        @foreach ($order->items as $item)
                            <li class="flex justify-between gap-2">
                                <span class="text-ink font-medium break-words min-w-0">{{ $item->product_name }} × {{ $item->quantity }}</span>
                                <span class="font-bold text-ink shrink-0">{{ money($item->line_total) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-3.5 flex items-baseline justify-between border-t border-line pt-3.5">
                        <span class="text-xs font-bold text-muted uppercase">Total</span>
                        <span class="display text-2xl text-ink">{{ money($order->total) }}</span>
                    </div>

                    <dl class="mt-3.5 space-y-1.5 border-t border-line pt-3.5 text-xs">
                        <div class="flex justify-between gap-2"><dt class="text-muted">Status</dt><dd class="font-bold text-ink">{{ $s->label() }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-muted">Method</dt><dd class="text-ink truncate">{{ $method?->name ?? 'Free / Instant' }}</dd></div>
                        @if ($order->latestPayment)
                            <div class="flex justify-between gap-2"><dt class="text-muted">TID</dt><dd class="font-mono font-bold text-ink truncate max-w-[150px]">{{ $order->latestPayment->transaction_id }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-2"><dt class="text-muted">Email</dt><dd class="text-ink truncate max-w-[170px]">{{ $order->email }}</dd></div>
                    </dl>

                    <div class="mt-4 border-t border-line pt-4 space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <a href="{{ $receiptUrl }}" target="_blank" class="btn btn-outline py-2 text-xs font-bold text-center">
                                🧾 View Receipt
                            </a>
                            <a href="{{ $pdfReceiptUrl }}" class="btn btn-primary py-2 text-xs font-bold text-center">
                                📥 PDF Receipt
                            </a>
                        </div>
                        @if ($s !== OrderStatus::Completed && $waNumber !== '')
                            <a href="{{ $waHref }}"
                               target="_blank"
                               rel="noopener"
                               class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] w-full py-2 text-xs font-bold">
                                📲 WhatsApp Support
                            </a>
                        @endif
                        <div x-data="copy(@js($order->customerUrl()))">
                            <button type="button" class="btn btn-outline w-full py-2 text-xs font-semibold" @click="copy" x-text="copied ? '✓ Link Copied' : 'Copy Order Link'">Copy Order Link</button>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.store>
