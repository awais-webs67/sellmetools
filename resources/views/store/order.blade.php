@php
    use App\Enums\OrderStatus;
    $s = $order->status;
    $method = $order->paymentMethod;
    $statusUrl = route('orders.status', ['order' => $order->number, 'token' => $token]);
    $rejected = $order->latestPayment?->status === \App\Enums\PaymentStatus::Rejected ? $order->latestPayment : null;
    $isPaid = in_array($s, [OrderStatus::Processing, OrderStatus::Completed, OrderStatus::NeedsAttention], true)
        || $order->paid_at !== null;

    $steps = [
        ['Placed', true],
        ['TID Sent', in_array($s, [OrderStatus::PaymentReview, OrderStatus::Processing, OrderStatus::Completed, OrderStatus::NeedsAttention], true)],
        ['Verified', $isPaid],
        ['Delivered', $s === OrderStatus::Completed],
    ];

    $gatewayCheckoutUrl = $method ? app(\App\Services\AutoPaymentVerifier::class)->gatewayCheckoutUrl($order, $method) : null;
    $waNumber = admin_whatsapp_number($method?->meta['whatsapp_number'] ?? null);

    $itemsSummary = $order->items->map(fn ($i) => $i->product_name.' x'.$i->quantity)->implode(', ');
    $amountFormatted = $method && $method->currency !== 'PKR' ? $amountDue.' '.$method->currency : money($amountDue ?? $order->total);
    $isBinance = $method && in_array($method->code, ['binance', 'usdt_trc20'], true);

    $waTemplate = "Assalam o Alaikum! Here are my order details on ".setting('store_name').":\n\n"
        ."Order ID: {$order->number}\n"
        ."Product: {$itemsSummary}\n"
        ."Amount: {$amountFormatted}\n"
        ."Method: ".($method?->name ?? 'Manual')."\n"
        ."TID: ".($order->latestPayment?->transaction_id ?? 'Pending')."\n"
        .($order->latestPayment?->sender_number || $order->phone ? "Sender: ".($order->latestPayment?->sender_number ?: $order->phone)."\n" : '')
        ."Email: {$order->email}\n"
        ."Order Link: ".$order->customerUrl();

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
                        <div class="card flex flex-col gap-3 border border-emerald-500/30 bg-emerald-50/50 p-4 sm:p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                                </span>
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm sm:text-base font-bold text-ink">Pay Directly with Account Wallet</span>
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800">No TID Needed</span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-body">
                                        Available Balance: <strong class="font-mono text-ink">{{ money($userWallet) }}</strong> · Unlock credentials immediately.
                                    </p>
                                </div>
                            </div>
                            <form method="post" action="{{ route('orders.pay-wallet', $order) }}" class="shrink-0">
                                @csrf
                                <input type="hidden" name="token" value="{{ $token }}">
                                <button type="submit" class="btn bg-[#16a34a] text-white hover:bg-[#15803d] w-full sm:w-auto px-5 py-2.5 text-xs sm:text-sm font-bold shadow-xs">
                                    Pay Instantly from Wallet →
                                </button>
                            </form>
                        </div>
                    @endif

                    <section class="card overflow-hidden">
                        {{-- Clean Header Strip with Timer --}}
                        <div class="flex items-center justify-between gap-3 border-b border-line bg-grey-soft px-5 py-3.5" x-data="countdown(@js($order->expires_at?->toIso8601String()))">
                            <div class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full bg-emerald-600"></span>
                                <span class="text-xs sm:text-sm font-bold text-ink">Pay via {{ $method->name }}</span>
                            </div>
                            <span class="rounded-md border border-line bg-white px-2.5 py-0.5 font-mono text-xs sm:text-sm font-bold text-ink tabular-nums" x-text="text">--:--</span>
                        </div>

                        <div class="p-5 sm:p-6 space-y-5">
                            @if ($gatewayCheckoutUrl)
                                <a href="{{ $gatewayCheckoutUrl }}" target="_blank" rel="noopener" class="btn btn-primary w-full py-3 text-sm font-bold">
                                    Pay {{ $amountFormatted }} Online via {{ $method->name }} →
                                </a>
                            @endif

                            {{-- STEP 1: Clean Structured Transfer Cards (Amount + Account Number) --}}
                            <div class="space-y-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="rounded bg-ink px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white">Step 1</span>
                                    <span class="text-xs sm:text-sm font-bold text-ink">Copy Details &amp; Send Payment</span>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-xl border border-line-strong bg-grey-soft/60 p-4 flex items-center justify-between gap-3" x-data="copy(@js((string) $amountDue))">
                                        <div>
                                            <span class="block text-[10px] font-bold uppercase tracking-wider text-muted">Exact Amount to Send</span>
                                            <span class="font-mono mt-0.5 block text-xl sm:text-2xl font-bold text-ink">{{ $amountFormatted }}</span>
                                        </div>
                                        <button type="button" class="btn btn-outline bg-white px-3 py-1.5 text-xs font-semibold shrink-0 inline-flex items-center gap-1.5" @click="copy">
                                            <svg class="h-3.5 w-3.5 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                            <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                        </button>
                                    </div>

                                    @if ($method->account_number && $method->account_number !== 'Set in admin')
                                        <div class="rounded-xl border border-line-strong bg-grey-soft/60 p-4 flex items-center justify-between gap-3" x-data="copy(@js($method->account_number))">
                                            <div class="min-w-0">
                                                <span class="block text-[10px] font-bold uppercase tracking-wider text-muted">
                                                    {{ $method->code === 'binance' ? 'Binance Pay ID' : ($method->code === 'usdt_trc20' ? 'USDT TRC20 Address' : $method->name.' Account') }}
                                                </span>
                                                <span class="mt-0.5 block font-mono text-base sm:text-lg font-bold text-ink break-all">{{ $method->account_number }}</span>
                                                @if ($method->account_title && $method->account_title !== 'Set in admin')
                                                    <span class="block text-xs text-muted truncate">Title: <strong class="text-ink font-medium">{{ $method->account_title }}</strong></span>
                                                @endif
                                            </div>
                                            <button type="button" class="btn btn-outline bg-white px-3 py-1.5 text-xs font-semibold shrink-0 inline-flex items-center gap-1.5" @click="copy">
                                                <svg class="h-3.5 w-3.5 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            @if ($method->instructions)
                                <p class="rounded-lg border border-line bg-grey-soft/50 px-3.5 py-2.5 text-xs text-body">{{ $method->instructions }}</p>
                            @endif

                            {{-- STEP 2: Highlighted TID Submission Box --}}
                            <form method="post"
                                  action="{{ route('orders.payment', $order) }}"
                                  enctype="multipart/form-data"
                                  class="rounded-2xl border border-line-strong bg-grey-soft/40 p-4 sm:p-5 space-y-4"
                                  x-data="paymentSubmitter()"
                                  @submit="startSubmit()">
                                @csrf
                                <input type="hidden" name="token" value="{{ $token }}">

                                <div class="flex items-center gap-2">
                                    <span class="rounded bg-ink px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white">Step 2</span>
                                    <span class="text-xs sm:text-sm font-bold text-ink">Submit Transaction ID (TID)</span>
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
                                               class="input font-mono uppercase text-sm py-2.5 bg-white"
                                               value="{{ old('transaction_id') }}"
                                               placeholder="{{ $isBinance ? 'e.g. 293847561029' : 'e.g. 84920194821' }}"
                                               autocomplete="off">
                                        @error('transaction_id')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                    </div>

                                    @if ($method->requires_sender_number)
                                        <div>
                                            <label for="sender_number" class="label text-xs font-semibold">Sender Number <span class="text-danger">*</span></label>
                                            <input id="sender_number" name="sender_number" required class="input text-sm py-2.5 bg-white" value="{{ old('sender_number', $order->phone) }}" placeholder="03001234567" inputmode="tel">
                                            @error('sender_number')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                        </div>
                                    @endif

                                    <div class="sm:col-span-2">
                                        <label for="screenshot" class="label text-xs font-semibold text-muted">Payment Screenshot (Optional)</label>
                                        <input id="screenshot" name="screenshot" type="file" accept="image/*" class="input py-1.5 text-xs bg-white file:mr-2.5 file:rounded file:border-0 file:bg-ink file:px-2.5 file:py-1 file:text-xs file:font-semibold file:text-white">
                                        @error('screenshot')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                    </div>

                                    @if ($customerInputLabel)
                                        <div class="sm:col-span-2 rounded-xl border border-amber-500/40 bg-amber-50 p-3.5">
                                            <label for="customer_input" class="label text-xs font-bold text-ink">
                                                {{ $customerInputLabel }} <span class="text-danger">*</span>
                                            </label>
                                            <textarea id="customer_input"
                                                      name="customer_input"
                                                      rows="2"
                                                      required
                                                      class="input mt-1 text-xs sm:text-sm bg-white"
                                                      placeholder="Enter your activation details here (e.g., Email / Username / Account ID)...">{{ old('customer_input', $order->customer_input) }}</textarea>
                                            <p class="mt-1 text-[11px] text-muted">Admin will use these details to activate and deliver your subscription.</p>
                                            @error('customer_input')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                        </div>
                                    @endif
                                </div>

                                <div x-show="submitting" x-cloak class="rounded-xl border border-line bg-white p-3">
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
                                 class="flex items-center justify-between gap-3 border-b border-line bg-grey-soft px-5 py-3"
                                 x-data="countdown(@js($order->expires_at->toIso8601String()))"
                                 x-effect="if (remaining === 0) { reviewTimeout = true; }">
                                <span class="text-xs font-semibold text-ink">Verification in Progress</span>
                                <span class="font-mono text-xs sm:text-sm font-bold text-ink tabular-nums" x-text="text">--:--</span>
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
                                        Instant Approval on WhatsApp →
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

                        $claimWaTemplate = "Assalam o Alaikum! My Order *{$order->number}* ({$itemsSummary}) on ".setting('store_name')." is CONFIRMED!\n\n"
                            ."Order ID: {$order->number}\n"
                            ."Product: {$itemsSummary}\n"
                            ."Amount: {$amountFormatted}\n"
                            ."TID: ".($order->latestPayment?->transaction_id ?? 'Verified')."\n"
                            ."Email: {$order->email}\n\n"
                            ."Please deliver my order.\n"
                            ."Order Link: ".$order->customerUrl();

                        $claimWaHref = $waNumber !== ''
                            ? "https://wa.me/{$waNumber}?text=".urlencode($claimWaTemplate)
                            : 'https://api.whatsapp.com/send?text='.urlencode($claimWaTemplate);
                    @endphp
                    <section class="card p-6 sm:p-8 text-center">
                        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                        </span>
                        <h2 class="mt-4 text-xl sm:text-2xl font-bold text-ink">
                            {{ $isWhatsAppHandover ? 'Order Confirmed - Receive on WhatsApp' : 'Payment Verified - Preparing Delivery' }}
                        </h2>
                        <p class="mt-1 text-xs sm:text-sm text-muted max-w-md mx-auto">
                            {{ $isWhatsAppHandover ? 'Click the button below to receive your product directly on WhatsApp.' : 'Your access details are being dispatched. This page updates automatically.' }}
                        </p>

                        @if ($waNumber !== '')
                            <div class="mt-6">
                                <a href="{{ $claimWaHref }}"
                                   target="_blank"
                                   rel="noopener"
                                   class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] w-full sm:w-auto px-7 py-3 text-sm font-bold shadow-xs">
                                    {{ $isWhatsAppHandover ? 'Receive Order on WhatsApp →' : 'Message Support on WhatsApp →' }}
                                </a>
                            </div>
                        @endif
                    </section>

                {{-- ============ 4. COMPLETED / DELIVERED CREDENTIALS (UNIFIED E-COMMERCE VAULT) ============ --}}
                @elseif ($s === OrderStatus::Completed)
                    @php
                        $allDeliveredText = $order->items->map(function ($it) {
                            $creds = $it->deliveries->pluck('content')->implode("\n");
                            return "Product: {$it->product_name} (x{$it->quantity})\nCredentials:\n{$creds}";
                        })->implode("\n\n");
                        $onlyRawCreds = $order->items->flatMap(fn ($it) => $it->deliveries->pluck('content'))->map(fn ($c) => trim((string) $c))->filter()->implode("\n\n");
                        $firstAnyUrl = null;
                        if (preg_match('/https?:\/\/[^\s"\'<>]+/i', $onlyRawCreds, $anyUrlMatch)) {
                            $firstAnyUrl = $anyUrlMatch[0];
                        }
                        $fullReceiptText = "=== ".setting('store_name', 'SellMeTools')." Receipt ===\n"
                            ."Order ID: {$order->number}\n"
                            ."Status: Delivered\n\n"
                            .$allDeliveredText;
                        $deliveredTime = ($order->completed_at ?? $order->updated_at)?->format('d M Y, h:i A');
                    @endphp

                    <div x-data="{
                            showDeliveryModal: false,
                            focusCredentials() {
                                this.showDeliveryModal = false;
                                this.$nextTick(() => {
                                    const el = document.getElementById('delivered-credentials');
                                    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                });
                            }
                        }"
                        @keydown.escape.window="showDeliveryModal = false">

                        {{-- Single Unified E-Commerce Digital Delivery Vault Card --}}
                        <section id="delivered-credentials"
                                 class="card overflow-hidden border border-slate-200 bg-white shadow-sm scroll-mt-24">

                            {{-- Unified Executive Header Bar --}}
                            <div class="bg-gradient-to-r from-slate-900 via-slate-800 to-emerald-950 px-5 py-4 sm:px-6 sm:py-5 text-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                <div class="flex items-start sm:items-center gap-3.5">
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-500 text-white shadow-sm ring-4 ring-emerald-500/20">
                                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                                    </span>
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/20 border border-emerald-400/30 px-2.5 py-0.5 text-[10px] font-extrabold uppercase tracking-wider text-emerald-300">
                                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span> Instant Digital Delivery
                                            </span>
                                            @if ($deliveredTime)
                                                <span class="text-[11px] text-slate-300">{{ $deliveredTime }}</span>
                                            @endif
                                        </div>
                                        <h2 class="mt-1 text-base sm:text-lg font-extrabold text-white tracking-tight">Delivered Access Credentials</h2>
                                        <p class="text-xs text-slate-300">Copy your login details or open your activation link directly below.</p>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-2 shrink-0" x-data="copy(@js($onlyRawCreds !== '' ? $onlyRawCreds : $fullReceiptText))">
                                    <button type="button"
                                            @click="copy"
                                            class="btn bg-emerald-500 text-white hover:bg-emerald-600 py-2 px-4 text-xs font-extrabold inline-flex items-center justify-center gap-1.5 shadow-sm w-full sm:w-auto">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                        <span x-text="copied ? '✓ All Copied!' : 'Copy All Credentials'">Copy All Credentials</span>
                                    </button>
                                </div>
                            </div>

                            {{-- Delivered Items & Credentials List --}}
                            <div class="divide-y divide-line p-5 sm:p-6 space-y-6">
                                @foreach ($order->items as $item)
                                    <div class="{{ ! $loop->first ? 'pt-6' : '' }}">
                                        {{-- Product Item Header --}}
                                        <div class="flex flex-wrap items-center justify-between gap-3 pb-3.5 border-b border-line">
                                            <div class="flex items-center gap-3 min-w-0">
                                                @if ($item->product?->imageUrl())
                                                    <img src="{{ $item->product->imageUrl() }}" alt="{{ $item->product_name }}" class="h-10 w-10 rounded-lg object-cover border border-line shrink-0">
                                                @else
                                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-700 font-bold text-xs border border-line">
                                                        🔑
                                                    </span>
                                                @endif
                                                <div class="min-w-0">
                                                    <h3 class="text-sm sm:text-base font-extrabold text-ink truncate">{{ $item->product_name }}</h3>
                                                    <p class="text-xs text-muted">Quantity: <strong class="text-ink">{{ $item->quantity }}</strong> · Digital License / Account</p>
                                                </div>
                                            </div>
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 border border-emerald-200 px-3 py-1 text-xs font-bold text-emerald-700">
                                                <svg class="h-3.5 w-3.5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                                                Unlocked
                                            </span>
                                        </div>

                                        {{-- Credentials Boxes --}}
                                        <div class="mt-4 space-y-3.5">
                                            @foreach ($item->deliveries as $idx => $delivery)
                                                @php
                                                    $rawContent = trim($delivery->content);
                                                    $parts = null;
                                                    if (! str_contains($rawContent, "\n") && preg_match('/^([^\s:|]+@[^\s:|]+)[:|](.+)$/', $rawContent, $m)) {
                                                        $parts = ['Email / Username' => trim($m[1]), 'Password / Access Key' => trim($m[2])];
                                                    }
                                                    $firstUrl = null;
                                                    if (preg_match('/https?:\/\/[^\s"\'<>]+/i', $rawContent, $urlMatch)) {
                                                        $firstUrl = $urlMatch[0];
                                                    }
                                                @endphp
                                                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4">
                                                    <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-slate-200/80">
                                                        <span class="inline-flex items-center gap-1.5 text-xs font-extrabold uppercase tracking-wider text-slate-700">
                                                            <svg class="h-3.5 w-3.5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                                            {{ $item->deliveries->count() > 1 ? 'Access Package #'.($idx + 1) : 'Account / Activation Details' }}
                                                        </span>
                                                        <div class="flex items-center gap-2" x-data="copy(@js($rawContent))">
                                                            @if ($firstUrl)
                                                                <a href="{{ $firstUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-700 shadow-2xs transition">
                                                                    <span>Open Activation Link</span>
                                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M7 17L17 7M7 7h10v10"/></svg>
                                                                </a>
                                                            @endif
                                                            <button type="button" @click="copy" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-ink hover:bg-slate-100 shadow-2xs transition">
                                                                <svg class="h-3.5 w-3.5 text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                                <span x-text="copied ? '✓ Copied!' : 'Copy'">Copy</span>
                                                            </button>
                                                        </div>
                                                    </div>

                                                    @if ($parts)
                                                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                                            @foreach ($parts as $pLabel => $pVal)
                                                                <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 shadow-2xs" x-data="copy(@js($pVal))">
                                                                    <div class="min-w-0">
                                                                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted">{{ $pLabel }}</p>
                                                                        <p class="mt-0.5 font-mono text-xs sm:text-sm font-bold text-ink break-all">{{ $pVal }}</p>
                                                                    </div>
                                                                    <button type="button" @click="copy" class="shrink-0 rounded-md bg-slate-900 px-3 py-1.5 text-[11px] font-bold text-white hover:bg-slate-800 transition" x-text="copied ? '✓ Copied' : 'Copy'">Copy</button>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <pre class="mt-3 rounded-lg border border-slate-200 bg-white p-3.5 font-mono text-xs sm:text-sm font-bold leading-relaxed whitespace-pre-wrap break-all text-ink shadow-2xs">{{ $rawContent }}</pre>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>

                                        @if ($item->product?->warranty)
                                            <div class="mt-3.5 flex items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3 text-xs text-body">
                                                <svg class="h-4 w-4 text-emerald-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                                <div>
                                                    <strong class="text-ink">Warranty &amp; Support:</strong> {{ $item->product->cleanWarrantyText() }}
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    </div>

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
                    <section class="card border {{ filled($order->customer_input) ? 'border-emerald-300' : 'border-amber-400' }} p-5 sm:p-6">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 class="text-sm sm:text-base font-bold text-ink">{{ $customerInputLabel }}</h3>
                                <p class="text-xs text-muted">
                                    {{ filled($order->customer_input) ? 'Submitted for custom activation.' : 'Required so our team can activate and deliver your subscription.' }}
                                </p>
                            </div>
                            @if (filled($order->customer_input))
                                <span class="badge badge-success text-[11px]">Received</span>
                            @else
                                <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-bold text-amber-800">Action Required</span>
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
                    <h2 class="text-xs font-bold uppercase tracking-wider text-muted">Order Summary</h2>
                    <ul class="mt-3 space-y-2 text-xs sm:text-sm">
                        @foreach ($order->items as $item)
                            <li class="flex justify-between gap-2">
                                <span class="text-ink font-medium break-words min-w-0">{{ $item->product_name }} × {{ $item->quantity }}</span>
                                <span class="font-bold text-ink shrink-0">{{ money($item->line_total) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-3.5 flex items-baseline justify-between border-t border-line pt-3.5">
                        <span class="text-xs font-bold text-muted uppercase">{{ $isPaid ? 'Total Paid' : 'Amount Due' }}</span>
                        <span class="display text-2xl text-ink">{{ money($order->total) }}</span>
                    </div>

                    <dl class="mt-3.5 space-y-1.5 border-t border-line pt-3.5 text-xs">
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted">Payment</dt>
                            <dd class="font-bold {{ $isPaid ? 'text-emerald-700' : ($s === OrderStatus::PaymentReview ? 'text-amber-700' : 'text-ink') }}">
                                {{ $isPaid ? 'Paid' : ($s === OrderStatus::PaymentReview ? 'Verifying TID' : 'Unpaid') }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2"><dt class="text-muted">Order Status</dt><dd class="font-semibold text-ink">{{ $s->label() }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-muted">Method</dt><dd class="text-ink truncate">{{ $method?->name ?? 'Free / Instant' }}</dd></div>
                        @if ($order->latestPayment)
                            <div class="flex justify-between gap-2"><dt class="text-muted">TID</dt><dd class="font-mono font-bold text-ink truncate max-w-[150px]">{{ $order->latestPayment->transaction_id }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-2"><dt class="text-muted">Email</dt><dd class="text-ink truncate max-w-[170px]">{{ $order->email }}</dd></div>
                    </dl>

                    <div class="mt-4 border-t border-line pt-4 space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <a href="{{ $receiptUrl }}" target="_blank" class="btn btn-outline py-2 text-xs font-semibold text-center">
                                {{ $isPaid ? 'View Receipt' : 'View Invoice' }}
                            </a>
                            <a href="{{ $pdfReceiptUrl }}" class="btn btn-primary py-2 text-xs font-semibold text-center">
                                {{ $isPaid ? 'PDF Receipt' : 'PDF Invoice' }}
                            </a>
                        </div>
                        @if ($s !== OrderStatus::Completed && $waNumber !== '')
                            <a href="{{ $waHref }}"
                               target="_blank"
                               rel="noopener"
                               class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] w-full py-2 text-xs font-semibold">
                                WhatsApp Support
                            </a>
                        @endif
                        <div x-data="copy(@js($order->customerUrl()))">
                            <button type="button" class="btn btn-outline w-full py-2 text-xs font-semibold" @click="copy" x-text="copied ? 'Link Copied' : 'Copy Order Link'">Copy Order Link</button>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.store>
