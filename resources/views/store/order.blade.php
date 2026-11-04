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

        {{-- Refined Editorial Order Header Bar --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-2xs">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-900 px-2.5 py-1 font-mono text-xs font-semibold text-white">
                            {{ $order->number }}
                        </span>
                        <span class="text-xs font-medium text-slate-500">{{ $order->created_at->format('d M Y, h:i A') }}</span>
                        @if ($s === OrderStatus::Completed)
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Delivered
                            </span>
                        @elseif (in_array($s, [OrderStatus::PaymentReview, OrderStatus::Processing], true))
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 border border-amber-200 px-2.5 py-0.5 text-[11px] font-semibold text-amber-800">
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span> In Progress
                            </span>
                        @endif
                    </div>

                    <h1 class="mt-2 text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">
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

                {{-- Clean 4-Step Progress Track --}}
                @unless (in_array($s, [OrderStatus::Cancelled, OrderStatus::Expired, OrderStatus::Refunded], true))
                    <ol class="flex items-center gap-1.5 sm:gap-2" aria-label="Order progress">
                        @foreach ($steps as $i => [$label, $done])
                            <li class="flex items-center gap-1.5">
                                <span @class([
                                    'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition border',
                                    'bg-slate-900 border-slate-900 text-white' => $done && $label !== 'Delivered',
                                    'bg-emerald-600 border-emerald-600 text-white' => $done && $label === 'Delivered',
                                    'bg-slate-50 border-slate-200 text-slate-400' => ! $done,
                                ])>
                                    @if ($done)
                                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                    @else
                                        <span class="font-mono text-[11px]">{{ $i + 1 }}</span>
                                    @endif
                                    <span class="hidden sm:inline">{{ $label }}</span>
                                </span>
                                @if (! $loop->last)
                                    <span @class(['h-px w-3 sm:w-5', 'bg-slate-900' => $done, 'bg-slate-200' => ! $done])></span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endunless
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_340px]">
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
                        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs sm:text-sm text-rose-800 flex items-start gap-3" role="alert">
                            <svg class="h-5 w-5 text-rose-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <div>
                                <p class="font-semibold">TID ({{ $rejected->transaction_id }}) could not be verified.</p>
                                @if ($customerRejectReason)<p class="mt-0.5 text-rose-700">{{ $customerRejectReason }}</p>@endif
                            </div>
                        </div>
                    @endif

                    @php
                        $authUser = auth()->user();
                        $userWallet = (float) ($authUser?->wallet_balance ?? 0);
                    @endphp
                    @if ($authUser && $userWallet >= (float) $order->total)
                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 sm:p-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3.5">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                                </span>
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm sm:text-base font-bold text-slate-900">Pay Directly with Account Wallet</span>
                                        <span class="rounded-md bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800">Instant Unlock</span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-600">
                                        Available Balance: <strong class="font-mono text-slate-900">{{ money($userWallet) }}</strong> · No TID required.
                                    </p>
                                </div>
                            </div>
                            <form method="post" action="{{ route('orders.pay-wallet', $order) }}" class="shrink-0">
                                @csrf
                                <input type="hidden" name="token" value="{{ $token }}">
                                <button type="submit" class="btn bg-emerald-600 text-white hover:bg-emerald-700 w-full sm:w-auto px-5 py-2.5 text-xs sm:text-sm font-semibold shadow-2xs">
                                    Pay Instantly from Wallet →
                                </button>
                            </form>
                        </div>
                    @endif

                    <section class="rounded-2xl border border-slate-200 bg-white shadow-2xs overflow-hidden">
                        {{-- Clean Header Strip with Timer --}}
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50/80 px-5 py-3.5" x-data="countdown(@js($order->expires_at?->toIso8601String()))">
                            <div class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                <span class="text-xs sm:text-sm font-semibold text-slate-900">Pay via {{ $method->name }}</span>
                            </div>
                            <span class="rounded-md border border-slate-200 bg-white px-2.5 py-0.5 font-mono text-xs font-semibold text-slate-700 tabular-nums" x-text="text">--:--</span>
                        </div>

                        <div class="p-5 sm:p-6 space-y-6">
                            @if ($gatewayCheckoutUrl)
                                <a href="{{ $gatewayCheckoutUrl }}" target="_blank" rel="noopener" class="btn btn-primary w-full py-3 text-sm font-semibold">
                                    Pay {{ $amountFormatted }} Online via {{ $method->name }} →
                                </a>
                            @endif

                            {{-- STEP 1: Clean Transfer Cards --}}
                            <div class="space-y-3">
                                <div class="flex items-center gap-2">
                                    <span class="rounded bg-slate-900 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wider text-white">Step 1</span>
                                    <span class="text-xs sm:text-sm font-semibold text-slate-900">Transfer Exact Amount</span>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 flex items-center justify-between gap-3" x-data="copy(@js((string) $amountDue))">
                                        <div>
                                            <span class="block text-[11px] font-medium text-slate-500">Exact Amount to Send</span>
                                            <span class="font-mono mt-0.5 block text-xl sm:text-2xl font-bold text-slate-900 tabular-nums">{{ $amountFormatted }}</span>
                                        </div>
                                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 shrink-0 inline-flex items-center gap-1.5 shadow-2xs transition" @click="copy">
                                            <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                            <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                        </button>
                                    </div>

                                    @if ($method->account_number && $method->account_number !== 'Set in admin')
                                        <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 flex items-center justify-between gap-3" x-data="copy(@js($method->account_number))">
                                            <div class="min-w-0">
                                                <span class="block text-[11px] font-medium text-slate-500">
                                                    {{ $method->code === 'binance' ? 'Binance Pay ID' : ($method->code === 'usdt_trc20' ? 'USDT TRC20 Address' : $method->name.' Account') }}
                                                </span>
                                                <span class="mt-0.5 block font-mono text-base sm:text-lg font-bold text-slate-900 break-all">{{ $method->account_number }}</span>
                                                @if ($method->account_title && $method->account_title !== 'Set in admin')
                                                    <span class="block text-xs text-slate-500 truncate">Title: <strong class="text-slate-800 font-medium">{{ $method->account_title }}</strong></span>
                                                @endif
                                            </div>
                                            <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 shrink-0 inline-flex items-center gap-1.5 shadow-2xs transition" @click="copy">
                                                <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            @if ($method->instructions)
                                <p class="rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3 text-xs text-slate-600 leading-relaxed">{{ $method->instructions }}</p>
                            @endif

                            {{-- STEP 2: TID Submission Box --}}
                            <form method="post"
                                  action="{{ route('orders.payment', $order) }}"
                                  enctype="multipart/form-data"
                                  class="rounded-xl border border-slate-200 bg-slate-50/40 p-4 sm:p-5 space-y-4"
                                  x-data="paymentSubmitter()"
                                  @submit="startSubmit()">
                                @csrf
                                <input type="hidden" name="token" value="{{ $token }}">

                                <div class="flex items-center gap-2">
                                    <span class="rounded bg-slate-900 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wider text-white">Step 2</span>
                                    <span class="text-xs sm:text-sm font-semibold text-slate-900">Submit Transaction Reference (TID)</span>
                                </div>

                                <div class="grid gap-3.5 sm:grid-cols-2">
                                    <div @class(['sm:col-span-2' => ! $method->requires_sender_number])>
                                        <label for="transaction_id" class="label text-xs font-semibold text-slate-700">
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
                                            <label for="sender_number" class="label text-xs font-semibold text-slate-700">Sender Number <span class="text-danger">*</span></label>
                                            <input id="sender_number" name="sender_number" required class="input text-sm py-2.5 bg-white" value="{{ old('sender_number', $order->phone) }}" placeholder="03001234567" inputmode="tel">
                                            @error('sender_number')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                        </div>
                                    @endif

                                    <div class="sm:col-span-2">
                                        <label for="screenshot" class="label text-xs font-semibold text-slate-600">Payment Screenshot (Optional)</label>
                                        <input id="screenshot" name="screenshot" type="file" accept="image/*" class="input py-1.5 text-xs bg-white file:mr-2.5 file:rounded-md file:border-0 file:bg-slate-900 file:px-2.5 file:py-1 file:text-xs file:font-semibold file:text-white">
                                        @error('screenshot')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                    </div>

                                    @if ($customerInputLabel)
                                        <div class="sm:col-span-2 rounded-xl border border-amber-300 bg-amber-50/70 p-3.5">
                                            <label for="customer_input" class="label text-xs font-semibold text-slate-900">
                                                {{ $customerInputLabel }} <span class="text-danger">*</span>
                                            </label>
                                            <textarea id="customer_input"
                                                      name="customer_input"
                                                      rows="2"
                                                      required
                                                      class="input mt-1 text-xs sm:text-sm bg-white"
                                                      placeholder="Enter your activation details here (e.g., Email / Username / Account ID)...">{{ old('customer_input', $order->customer_input) }}</textarea>
                                            <p class="mt-1 text-[11px] text-slate-500">Used by our system to activate your subscription.</p>
                                            @error('customer_input')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                        </div>
                                    @endif
                                </div>

                                <div x-show="submitting" x-cloak class="rounded-xl border border-slate-200 bg-white p-3">
                                    <div class="flex items-center justify-between text-xs font-semibold text-slate-900">
                                        <span x-text="stepLabel">Verifying...</span>
                                        <span class="tabular-nums" x-text="percent + '%'">0%</span>
                                    </div>
                                    <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-full rounded-full bg-slate-900 transition-all duration-300" :style="'width: ' + percent + '%'"></div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary btn-lg w-full text-sm font-semibold" :disabled="submitting">
                                    <span x-show="!submitting">Verify Payment &amp; Unlock Order →</span>
                                    <span x-show="submitting" x-cloak x-text="'Verifying (' + percent + '%)...'">Verifying...</span>
                                </button>
                            </form>
                        </div>
                    </section>

                {{-- ============ 2. VERIFYING / PROCESSING ============ --}}
                @elseif (in_array($s, [OrderStatus::PaymentReview, OrderStatus::Processing], true))
                    <section class="rounded-2xl border border-slate-200 bg-white shadow-2xs overflow-hidden">
                        @if ($s === OrderStatus::PaymentReview && $order->expires_at)
                            <div x-show="!reviewTimeout"
                                 class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-5 py-3"
                                 x-data="countdown(@js($order->expires_at->toIso8601String()))"
                                 x-effect="if (remaining === 0) { reviewTimeout = true; }">
                                <span class="text-xs font-semibold text-slate-700">Payment Verification in Progress</span>
                                <span class="font-mono text-xs font-semibold text-slate-900 tabular-nums" x-text="text">--:--</span>
                            </div>
                        @endif

                        <div class="p-6 sm:p-8 text-center">
                            <div class="mx-auto relative flex h-14 w-14 items-center justify-center">
                                <div class="absolute inset-0 animate-spin rounded-full border-3 border-slate-200 border-t-slate-900" aria-hidden="true"></div>
                                <span class="font-mono text-xs font-semibold text-slate-900 tabular-nums" x-text="progress + '%'">85%</span>
                            </div>

                            <h2 class="mt-4 text-xl font-bold text-slate-900">
                                <span x-show="!reviewTimeout">{{ $s === OrderStatus::PaymentReview ? 'Admin is Verifying Your Payment' : 'Delivering Your Credentials' }}</span>
                                <span x-show="reviewTimeout" x-cloak>Priority Verification in Progress</span>
                            </h2>

                            <p class="mt-1 text-xs sm:text-sm text-slate-500">
                                Reference <strong class="font-mono text-slate-900">{{ $order->latestPayment?->transaction_id }}</strong> received · This page unlocks automatically once approved.
                            </p>

                            <div class="mx-auto mt-5 max-w-md">
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-slate-900 transition-all duration-500" :style="'width: ' + progress + '%'"></div>
                                </div>

                                <div class="mt-4 grid grid-cols-3 gap-2 text-[11px] font-semibold">
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 py-2 px-2 text-emerald-700">Reserved</div>
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 py-2 px-2 text-emerald-700">TID Sent</div>
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 py-2 px-2 text-slate-900"><span x-text="progress + '%'">85%</span></div>
                                </div>
                            </div>

                            @if ($waNumber !== '')
                                <div class="mt-6 pt-5 border-t border-slate-100">
                                    <a href="{{ $waHref }}"
                                       target="_blank"
                                       rel="noopener"
                                       class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] w-full sm:w-auto px-6 py-2.5 text-xs sm:text-sm font-semibold shadow-2xs">
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
                    <section class="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8 text-center shadow-2xs">
                        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>
                        </span>
                        <h2 class="mt-4 text-xl font-bold text-slate-900">
                            {{ $isWhatsAppHandover ? 'Order Confirmed — Receive on WhatsApp' : 'Payment Verified — Preparing Delivery' }}
                        </h2>
                        <p class="mt-1 text-xs sm:text-sm text-slate-500 max-w-md mx-auto">
                            {{ $isWhatsAppHandover ? 'Click the button below to receive your product directly on WhatsApp.' : 'Your access details are being dispatched. This page updates automatically.' }}
                        </p>

                        @if ($waNumber !== '')
                            <div class="mt-6">
                                <a href="{{ $claimWaHref }}"
                                   target="_blank"
                                   rel="noopener"
                                   class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] w-full sm:w-auto px-7 py-3 text-sm font-semibold shadow-2xs">
                                    {{ $isWhatsAppHandover ? 'Receive Order on WhatsApp →' : 'Message Support on WhatsApp →' }}
                                </a>
                            </div>
                        @endif
                    </section>

                {{-- ============ 4. COMPLETED / DELIVERED CREDENTIALS (CLEAN STRIPE / LEMONSQUEEZY STYLE) ============ --}}
                @elseif ($s === OrderStatus::Completed)
                    @php
                        $allDeliveredText = $order->items->map(function ($it) {
                            $creds = $it->deliveries->pluck('content')->implode("\n");
                            return "Product: {$it->product_name} (x{$it->quantity})\nCredentials:\n{$creds}";
                        })->implode("\n\n");
                        $onlyRawCreds = $order->items->flatMap(fn ($it) => $it->deliveries->pluck('content'))->map(fn ($c) => trim((string) $c))->filter()->implode("\n\n");
                        $fullReceiptText = "=== ".setting('store_name', 'SellMeTools')." Receipt ===\n"
                            ."Order ID: {$order->number}\n"
                            ."Status: Delivered\n\n"
                            .$allDeliveredText;
                        $deliveredTime = ($order->completed_at ?? $order->updated_at)?->format('d M Y, h:i A');
                    @endphp

                    <section id="delivered-credentials"
                             class="rounded-2xl border border-slate-200 bg-white shadow-2xs overflow-hidden scroll-mt-24">

                        {{-- Clean Neutral Header Bar --}}
                        <div class="border-b border-slate-200 bg-slate-50/90 px-5 py-4 sm:px-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div class="flex items-center gap-3.5">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                </span>
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="text-sm sm:text-base font-bold text-slate-900">Delivered Access Credentials</h2>
                                        @if ($deliveredTime)
                                            <span class="text-xs text-slate-400">· {{ $deliveredTime }}</span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-slate-500">Your digital license and login credentials are ready for immediate use.</p>
                                </div>
                            </div>

                            <div class="shrink-0" x-data="copy(@js($onlyRawCreds !== '' ? $onlyRawCreds : $fullReceiptText))">
                                <button type="button"
                                        @click="copy"
                                        class="inline-flex w-full sm:w-auto items-center justify-center gap-1.5 rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white hover:bg-slate-800 transition shadow-2xs">
                                    <svg class="h-3.5 w-3.5 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    <span x-text="copied ? 'Copied to Clipboard' : 'Copy All Credentials'">Copy All Credentials</span>
                                </button>
                            </div>
                        </div>

                        {{-- Delivered Items & Credentials List --}}
                        <div class="divide-y divide-slate-200 p-5 sm:p-6 space-y-6">
                            @foreach ($order->items as $item)
                                <div class="{{ ! $loop->first ? 'pt-6' : '' }}">
                                    {{-- Product Item Row --}}
                                    <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-slate-100">
                                        <div class="flex items-center gap-3 min-w-0">
                                            @if ($item->product?->imageUrl())
                                                <img src="{{ $item->product->imageUrl() }}" alt="{{ $item->product_name }}" class="h-10 w-10 rounded-lg object-cover border border-slate-200 shrink-0">
                                            @else
                                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 border border-slate-200">
                                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                                </span>
                                            @endif
                                            <div class="min-w-0">
                                                <h3 class="text-sm sm:text-base font-bold text-slate-900 truncate">{{ $item->product_name }}</h3>
                                                <p class="text-xs text-slate-500">Qty: <strong class="text-slate-800">{{ $item->quantity }}</strong> · Digital License / Account</p>
                                            </div>
                                        </div>
                                        <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 border border-emerald-200 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                            <svg class="h-3.5 w-3.5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
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
                                                    $parts = ['Account Email / Username' => trim($m[1]), 'Password / Access Key' => trim($m[2])];
                                                }
                                                $firstUrl = null;
                                                if (preg_match('/https?:\/\/[^\s"\'<>]+/i', $rawContent, $urlMatch)) {
                                                    $firstUrl = $urlMatch[0];
                                                }
                                            @endphp
                                            <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                                <div class="flex flex-wrap items-center justify-between gap-2 bg-slate-50 px-4 py-2.5 border-b border-slate-200">
                                                    <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-600">
                                                        <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                                                        {{ $item->deliveries->count() > 1 ? 'License Unit #'.($idx + 1) : 'Account / Activation Details' }}
                                                    </span>
                                                    <div class="flex items-center gap-2" x-data="copy(@js($rawContent))">
                                                        @if ($firstUrl)
                                                            <a href="{{ $firstUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700 transition">
                                                                <span>Open Link</span>
                                                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M7 17L17 7M7 7h10v10"/></svg>
                                                            </a>
                                                        @endif
                                                        <button type="button" @click="copy" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition">
                                                            <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                            <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="p-4">
                                                    @if ($parts)
                                                        <div class="grid gap-3 sm:grid-cols-2">
                                                            @foreach ($parts as $pLabel => $pVal)
                                                                <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50/60 px-3.5 py-2.5" x-data="copy(@js($pVal))">
                                                                    <div class="min-w-0">
                                                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $pLabel }}</p>
                                                                        <p class="mt-0.5 font-mono text-xs sm:text-sm font-semibold text-slate-900 break-all">{{ $pVal }}</p>
                                                                    </div>
                                                                    <button type="button" @click="copy" class="shrink-0 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-100 transition" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <pre class="rounded-lg bg-slate-900 p-3.5 font-mono text-xs sm:text-sm font-medium leading-relaxed whitespace-pre-wrap break-all text-slate-100">{{ $rawContent }}</pre>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    @if ($item->product?->warranty)
                                        <div class="mt-3.5 flex items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3 text-xs text-slate-600">
                                            <svg class="h-4 w-4 text-slate-500 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                            <div>
                                                <strong class="text-slate-900 font-semibold">Warranty &amp; Support:</strong> {{ $item->product->cleanWarrantyText() }}
                                            </div>
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
                    <section class="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8 text-center shadow-2xs">
                        <h2 class="text-lg sm:text-xl font-bold text-slate-900">Order {{ strtolower($s->label()) }}</h2>
                        @if ($walletRefundTx)
                            <div class="mx-auto mt-3 max-w-md rounded-xl border border-emerald-200 bg-emerald-50 p-3.5 text-xs sm:text-sm font-semibold text-emerald-800">
                                {{ money((float) $walletRefundTx->amount) }} has been automatically refunded to your Account Wallet Balance.
                            </div>
                        @endif
                        @if ($product = $order->items->first()?->product)
                            <a href="{{ route('products.show', $product) }}" class="btn btn-primary mt-4">Order Again</a>
                        @endif
                    </section>
                @endif

                @if ($customerInputLabel && in_array($s, [OrderStatus::PaymentReview, OrderStatus::Processing, OrderStatus::NeedsAttention, OrderStatus::Completed], true))
                    <section class="rounded-2xl border {{ filled($order->customer_input) ? 'border-slate-200' : 'border-amber-300' }} bg-white p-5 sm:p-6 shadow-2xs">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 class="text-sm sm:text-base font-bold text-slate-900">{{ $customerInputLabel }}</h3>
                                <p class="text-xs text-slate-500">
                                    {{ filled($order->customer_input) ? 'Submitted for custom activation.' : 'Required so our team can activate and deliver your subscription.' }}
                                </p>
                            </div>
                            @if (filled($order->customer_input))
                                <span class="rounded-md bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700">Received</span>
                            @else
                                <span class="rounded-md bg-amber-50 border border-amber-200 px-2.5 py-0.5 text-[11px] font-semibold text-amber-800">Action Required</span>
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
                                <button type="submit" class="btn btn-primary px-5 py-2 text-xs font-semibold">
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
                        <button class="text-xs text-slate-500 hover:text-rose-600 underline">Cancel order</button>
                    </form>
                @endif
            </div>

            {{-- RIGHT SIDEBAR: Clean Stripe-Style Receipt Summary --}}
            <aside class="min-w-0 lg:sticky lg:top-24 lg:self-start">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-2xs">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Order Summary</h2>
                    <ul class="mt-3.5 space-y-2.5 text-xs sm:text-sm">
                        @foreach ($order->items as $item)
                            <li class="flex justify-between gap-3">
                                <span class="text-slate-800 font-medium break-words min-w-0">{{ $item->product_name }} <span class="text-slate-400">× {{ $item->quantity }}</span></span>
                                <span class="font-mono font-semibold text-slate-900 shrink-0 tabular-nums">{{ money($item->line_total) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-4 flex items-baseline justify-between border-t border-slate-200 pt-4">
                        <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">{{ $isPaid ? 'Total Paid' : 'Amount Due' }}</span>
                        <span class="font-mono text-2xl font-bold text-slate-900 tabular-nums">{{ money($order->total) }}</span>
                    </div>

                    <dl class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-xs">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-slate-500">Payment</dt>
                            <dd class="font-semibold {{ $isPaid ? 'text-emerald-700' : ($s === OrderStatus::PaymentReview ? 'text-amber-700' : 'text-slate-900') }}">
                                {{ $isPaid ? 'Paid' : ($s === OrderStatus::PaymentReview ? 'Verifying TID' : 'Unpaid') }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-slate-500">Order Status</dt>
                            <dd class="font-semibold text-slate-900">{{ $s->label() }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-slate-500">Method</dt>
                            <dd class="font-medium text-slate-800 truncate">{{ $method?->name ?? 'Free / Instant' }}</dd>
                        </div>
                        @if ($order->latestPayment)
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-slate-500">TID</dt>
                                <dd class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-slate-800 truncate max-w-[160px]">{{ $order->latestPayment->transaction_id }}</dd>
                            </div>
                        @endif
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-slate-500">Email</dt>
                            <dd class="text-slate-700 truncate max-w-[175px]">{{ $order->email }}</dd>
                        </div>
                    </dl>

                    <div class="mt-5 border-t border-slate-200 pt-4 space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <a href="{{ $receiptUrl }}" target="_blank" class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                                <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <span>{{ $isPaid ? 'View Receipt' : 'View Invoice' }}</span>
                            </a>
                            <a href="{{ $pdfReceiptUrl }}" class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800 transition">
                                <svg class="h-3.5 w-3.5 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                <span>{{ $isPaid ? 'PDF Receipt' : 'PDF Invoice' }}</span>
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
                            <button type="button"
                                    class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50/70 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition"
                                    @click="copy">
                                <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <span x-text="copied ? 'Order Link Copied' : 'Copy Order Link'">Copy Order Link</span>
                            </button>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.store>
