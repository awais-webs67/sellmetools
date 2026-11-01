@php
    use App\Enums\OrderStatus;
    $s = $order->status;
    $method = $order->paymentMethod;
    $statusUrl = route('orders.status', ['order' => $order->number, 'token' => $token]);
    $rejected = $order->latestPayment?->status === \App\Enums\PaymentStatus::Rejected ? $order->latestPayment : null;
    $steps = [
        ['Placed', true],
        ['Submitted', in_array($s, [OrderStatus::PaymentReview, OrderStatus::Processing, OrderStatus::Completed, OrderStatus::NeedsAttention], true)],
        ['Verified', in_array($s, [OrderStatus::Processing, OrderStatus::Completed, OrderStatus::NeedsAttention], true)],
        ['Delivered', $s === OrderStatus::Completed],
    ];

    $gatewayCheckoutUrl = $method ? app(\App\Services\AutoPaymentVerifier::class)->gatewayCheckoutUrl($order, $method) : null;
    $waNumber = preg_replace('/\D/', '', (string) ($method?->meta['whatsapp_number'] ?? setting('support_whatsapp') ?? ''));
    if (str_starts_with($waNumber, '03') && strlen($waNumber) === 11) {
        $waNumber = '92'.substr($waNumber, 1);
    }

    $itemsSummary = $order->items->map(fn ($i) => $i->product_name.' x'.$i->quantity)->implode(', ');
    $amountFormatted = $method && $method->currency !== 'PKR' ? $amountDue.' '.$method->currency : money($amountDue ?? $order->total);
    $isBinance = $method && in_array($method->code, ['binance', 'usdt_trc20'], true);

    $quickApproveUrl = route('webhooks.order.quick', ['order' => $order->number, 'do' => 'approve']);
    $quickRejectUrl = route('webhooks.order.quick', ['order' => $order->number, 'do' => 'reject']);

    $waTemplate = "Assalam o Alaikum! Here are my order details on ".setting('store_name').":\n\n"
        ."🧾 *Order ID:* {$order->number}\n"
        ."📦 *Product:* {$itemsSummary}\n"
        ."💰 *Amount:* {$amountFormatted}\n"
        ."💳 *Method:* ".($method?->name ?? 'Manual')."\n"
        ."🔑 *TID:* ".($order->latestPayment?->transaction_id ?? 'Pending')."\n"
        .($order->latestPayment?->sender_number || $order->phone ? "📱 *Sender:* ".($order->latestPayment?->sender_number ?: $order->phone)."\n" : '')
        ."📧 *Email:* {$order->email}\n\n"
        ."✅ *Admin 1-Tap Approve:*\n{$quickApproveUrl}\n\n"
        ."❌ *Admin 1-Tap Decline:*\n{$quickRejectUrl}";

    $waHref = $waNumber !== ''
        ? "https://wa.me/{$waNumber}?text=".urlencode($waTemplate)
        : 'https://api.whatsapp.com/send?text='.urlencode($waTemplate);
@endphp
<x-layouts.store :title="'Order '.$order->number" robots="noindex, nofollow">
    <div class="container-x py-5 sm:py-10" x-data="orderWatcher(@js($statusUrl), @js($s->value))">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <span class="eyebrow">Order {{ $order->number }}</span>
                <h1 class="display mt-1 text-3xl sm:text-5xl">
                    @switch($s)
                        @case(OrderStatus::PendingPayment) Complete your payment @break
                        @case(OrderStatus::PaymentReview) Verifying your payment @break
                        @case(OrderStatus::Processing) Delivering your order @break
                        @case(OrderStatus::Completed) Order Delivered &amp; Ready @break
                        @case(OrderStatus::NeedsAttention) Finalizing delivery @break
                        @default Order {{ strtolower($s->label()) }}
                    @endswitch
                </h1>
            </div>
            <p class="text-xs sm:text-sm text-muted">Placed {{ $order->created_at->format('d M Y, h:i A') }}</p>
        </div>

        {{-- Progress --}}
        @unless (in_array($s, [OrderStatus::Cancelled, OrderStatus::Expired, OrderStatus::Refunded], true))
            <ol class="mt-4 grid grid-cols-4 gap-1.5 sm:gap-2" aria-label="Order progress">
                @foreach ($steps as $i => [$label, $done])
                    <li class="min-w-0">
                        <div @class(['h-1.5 rounded-full transition-all duration-300', 'bg-brand' => $done, 'bg-line' => ! $done])></div>
                        <p @class(['mt-1.5 truncate text-[11px] font-semibold sm:text-sm', 'text-ink' => $done, 'text-muted' => ! $done])>{{ $label }}</p>
                    </li>
                @endforeach
            </ol>
        @endunless

        <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_360px]">
            <div class="min-w-0 space-y-5">
                {{-- ============ AWAITING PAYMENT ============ --}}
                @if ($s === OrderStatus::PendingPayment && $method)
                    @if ($rejected)
                        <div class="rounded-xl border border-danger/20 bg-danger-tint p-4 text-xs sm:text-sm text-danger" role="alert">
                            <p class="font-semibold">Your previous transaction ID ({{ $rejected->transaction_id }}) could not be verified.</p>
                            @if ($rejected->reject_reason)<p class="mt-1">Reason: {{ $rejected->reject_reason }}</p>@endif
                            <p class="mt-1">Please submit a valid, unused Transaction ID below.</p>
                        </div>
                    @endif

                    <section class="card overflow-hidden">
                        <div class="flex items-center justify-between gap-3 bg-ink px-4 py-3.5 sm:px-6 text-white" x-data="countdown(@js($order->expires_at?->toIso8601String()))">
                            <p class="text-xs sm:text-sm font-medium">Stock reserved for you</p>
                            <p class="display text-2xl sm:text-3xl text-white tabular-nums" :class="remaining < 120 && 'text-brand'" x-text="text">--:--</p>
                        </div>

                        <div class="p-4 sm:p-6">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-base sm:text-lg font-bold">Step 1 — Send Payment via {{ $method->name }}</h2>
                                <span class="badge badge-brand text-[11px]">
                                    {{ $isBinance ? '⚡ Binance Auto-Verify' : ($method->type === 'auto' ? 'Instant Auto Gateway' : 'Instant Verify') }}
                                </span>
                            </div>

                            @if ($gatewayCheckoutUrl)
                                <div class="mt-4 rounded-xl border border-brand/30 bg-brand-tint p-4">
                                    <p class="text-xs sm:text-sm font-semibold text-ink">Pay Online via Linked Wallet Gateway</p>
                                    <a href="{{ $gatewayCheckoutUrl }}" target="_blank" rel="noopener" class="btn btn-primary mt-2.5 w-full sm:w-auto text-xs sm:text-sm">
                                        Pay {{ $amountFormatted }} with {{ $method->name }} →
                                    </a>
                                </div>
                            @endif

                            @if ($isBinance)
                                <div class="mt-4 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3.5 text-xs text-ink">
                                    <p class="font-bold text-amber-900">⚡ Binance 1-Second Auto-Delivery:</p>
                                    <p class="mt-1 text-body">Send <strong>{{ $amountFormatted }}</strong> via Binance Pay to the ID below, then paste your <strong>Binance Order ID / TxID</strong> in Step 2.</p>
                                </div>
                            @endif

                            <dl class="mt-4 divide-y divide-line rounded-xl border border-line">
                                <div class="flex flex-wrap items-center justify-between gap-2 p-3.5 sm:p-4">
                                    <dt class="text-xs sm:text-sm text-muted">
                                        Exact Amount to Send
                                        @if ($method->currency !== 'PKR')
                                            <span class="block text-[11px] text-muted">({{ money($order->total) }})</span>
                                        @endif
                                    </dt>
                                    <dd class="flex items-center gap-2.5" x-data="copy(@js((string) $amountDue))">
                                        <span class="display text-2xl sm:text-3xl text-ink">{{ $amountFormatted }}</span>
                                        <button type="button" class="btn btn-outline px-2.5 py-1 text-xs" @click="copy" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                                    </dd>
                                </div>
                                @if ($method->account_number && $method->account_number !== 'Set in admin')
                                    <div class="flex flex-col gap-2 p-3.5 sm:flex-row sm:items-center sm:justify-between sm:p-4">
                                        <dt class="text-xs sm:text-sm text-muted">{{ $method->code === 'binance' ? 'Binance Pay ID (UID)' : ($method->code === 'usdt_trc20' ? 'USDT Wallet Address' : 'Account Number / IBAN') }}</dt>
                                        <dd class="flex items-center justify-between sm:justify-end gap-2.5" x-data="copy(@js($method->account_number))">
                                            <span class="font-mono text-sm sm:text-base font-bold break-all text-ink">{{ $method->account_number }}</span>
                                            <button type="button" class="btn btn-outline shrink-0 px-2.5 py-1 text-xs" @click="copy" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                                        </dd>
                                    </div>
                                @endif
                                @if ($method->account_title && $method->account_title !== 'Set in admin')
                                    <div class="flex flex-wrap items-center justify-between gap-2 p-3.5 sm:p-4">
                                        <dt class="text-xs sm:text-sm text-muted">{{ $isBinance ? 'Binance Nickname / Network' : 'Account Holder Name' }}</dt>
                                        <dd class="text-xs sm:text-sm font-semibold text-ink break-words">{{ $method->account_title }}</dd>
                                    </div>
                                @endif
                            </dl>

                            @if ($method->instructions)
                                <p class="mt-3 text-xs sm:text-sm leading-5 whitespace-pre-line text-body break-words">{{ $method->instructions }}</p>
                            @endif
                        </div>

                        <form method="post"
                              action="{{ route('orders.payment', $order) }}"
                              enctype="multipart/form-data"
                              class="border-t border-line bg-grey-soft p-4 sm:p-6"
                              x-data="paymentSubmitter()"
                              @submit="startSubmit()">
                            @csrf
                            <input type="hidden" name="token" value="{{ $token }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-base sm:text-lg font-bold">Step 2 — Submit Transaction ID (TID)</h2>
                                <span class="badge bg-ink text-white text-[10px]">🔒 Unique TID Check</span>
                            </div>

                            <div class="mt-3.5 grid gap-3.5 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label for="transaction_id" class="label text-xs sm:text-sm">
                                        {{ $isBinance ? 'Binance Order ID / Transaction ID' : 'Transaction ID (TID / Trx ID / Ref#)' }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <input id="transaction_id"
                                           name="transaction_id"
                                           required
                                           minlength="3"
                                           class="input font-mono uppercase text-sm"
                                           value="{{ old('transaction_id') }}"
                                           placeholder="{{ $isBinance ? 'e.g. 293847561029384756' : 'e.g. 84920194821' }}"
                                           autocomplete="off">
                                    @error('transaction_id')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                </div>
                                @if ($method->requires_sender_number)
                                    <div>
                                        <label for="sender_number" class="label text-xs sm:text-sm">Sender Number <span class="text-danger">*</span></label>
                                        <input id="sender_number" name="sender_number" required class="input text-sm" value="{{ old('sender_number', $order->phone) }}" placeholder="03xx xxxxxxx" inputmode="tel">
                                        @error('sender_number')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                    </div>
                                @endif
                                <div @class(['sm:col-span-2' => ! $method->requires_sender_number])>
                                    <label for="screenshot" class="label text-xs sm:text-sm">Screenshot (Optional)</label>
                                    <input id="screenshot" name="screenshot" type="file" accept="image/*" class="input py-1.5 text-xs file:mr-2.5 file:rounded file:border-0 file:bg-ink file:px-2.5 file:py-1 file:text-xs file:font-semibold file:text-white">
                                    @error('screenshot')<p class="field-error text-xs">{{ $message }}</p>@enderror
                                </div>
                            </div>

                            {{-- Live Verification Percentage Bar when submitting --}}
                            <div x-show="submitting" x-cloak class="mt-4 rounded-xl border border-brand/30 bg-white p-3.5">
                                <div class="flex items-center justify-between text-xs font-bold text-ink">
                                    <span x-text="stepLabel" class="truncate pr-2">Verifying Transaction ID...</span>
                                    <span class="text-brand tabular-nums shrink-0" x-text="percent + '%'">0%</span>
                                </div>
                                <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-line">
                                    <div class="h-full rounded-full bg-brand transition-all duration-300" :style="'width: ' + percent + '%'"></div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg mt-4 w-full text-sm sm:text-base" :disabled="submitting">
                                <span x-show="!submitting">Verify Payment &amp; Get Product →</span>
                                <span x-show="submitting" x-cloak x-text="'Verifying (' + percent + '%)...'">Verifying...</span>
                            </button>
                        </form>
                    </section>

                {{-- ============ VERIFYING / PROCESSING (WITH LIVE PERCENTAGE BAR) ============ --}}
                @elseif (in_array($s, [OrderStatus::PaymentReview, OrderStatus::Processing], true))
                    <section class="card p-5 sm:p-8">
                        <div class="flex flex-col items-center text-center">
                            <div class="relative flex h-16 w-16 items-center justify-center">
                                <div class="absolute inset-0 animate-spin rounded-full border-4 border-brand-tint-2 border-t-brand" aria-hidden="true"></div>
                                <span class="font-mono text-xs font-bold text-ink tabular-nums" x-text="progress + '%'">65%</span>
                            </div>

                            <h2 class="mt-4 text-lg sm:text-2xl font-bold text-ink">
                                {{ $s === OrderStatus::PaymentReview ? 'Verifying Your Payment...' : 'Payment Confirmed — Delivering Credentials...' }}
                            </h2>
                            <p class="mt-1 text-xs sm:text-sm text-body" x-text="stageText">
                                Checking Transaction ID uniqueness &amp; matching with payment gateway...
                            </p>
                        </div>

                        <div class="mt-5">
                            <div class="flex items-center justify-between text-xs font-bold">
                                <span class="text-ink">Verification Progress</span>
                                <span class="text-brand tabular-nums" x-text="progress + '%'">65%</span>
                            </div>
                            <div class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-line">
                                <div class="h-full rounded-full bg-gradient-to-r from-brand to-[#ff4d6d] transition-all duration-500"
                                     :style="'width: ' + progress + '%'"></div>
                            </div>
                        </div>

                        <ul class="mt-5 space-y-2 rounded-xl border border-line bg-grey-soft p-3.5 text-xs">
                            <li class="flex items-center justify-between gap-2">
                                <span class="font-medium text-ink">✓ Order &amp; Stock Reserved</span>
                                <span class="font-semibold text-success shrink-0">Done</span>
                            </li>
                            <li class="flex items-center justify-between gap-2">
                                <span class="font-medium text-ink truncate">✓ Unique TID Locked ({{ $order->latestPayment?->transaction_id }})</span>
                                <span class="font-semibold text-success shrink-0">Verified</span>
                            </li>
                            <li class="flex items-center justify-between gap-2">
                                <span class="font-medium text-ink">⟳ Confirming Payment ({{ $method?->name }})</span>
                                <span class="font-semibold text-brand shrink-0" x-text="progress + '%'">In Progress</span>
                            </li>
                        </ul>
                    </section>

                {{-- ============ COMPLETED / DELIVERED RECEIPT & CREDENTIALS TEMPLATE ============ --}}
                @elseif (in_array($s, [OrderStatus::Completed, OrderStatus::NeedsAttention], true))
                    @php
                        $allDeliveredText = $order->items->map(function ($it) {
                            $creds = $it->deliveries->pluck('content')->implode("\n");
                            return "Product: {$it->product_name} (x{$it->quantity})\nCredentials:\n{$creds}";
                        })->implode("\n\n");
                        $fullReceiptText = "=== ".setting('store_name', 'SellMeTools')." Order Receipt ===\n"
                            ."Order ID: {$order->number}\n"
                            ."Date: ".$order->created_at->format('d M Y, h:i A')."\n"
                            ."Payment: ".($method?->name ?? 'Online')." (".money($order->total).")\n"
                            ."Status: Completed & Delivered\n\n"
                            .$allDeliveredText;
                    @endphp

                    {{-- Premium Delivered Order Header Banner --}}
                    <section class="card overflow-hidden border-2 border-[#16794B]/30">
                        <div class="bg-gradient-to-r from-[#0F291E] via-[#143D2B] to-[#16794B] p-5 sm:p-6 text-white">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-bold tracking-wider text-[#4ade80] uppercase">
                                    ✓ Payment Verified &amp; Delivered
                                </span>
                                <span class="font-mono text-xs text-white/80">Order #{{ $order->number }}</span>
                            </div>
                            <h2 class="display mt-3 text-3xl sm:text-4xl text-white">
                                Your Subscription Credentials Are Ready!
                            </h2>
                            <p class="mt-1 text-xs sm:text-sm text-white/85">
                                Copy your login details or activation link below. These credentials are also permanently saved in your account history.
                            </p>
                        </div>

                        {{-- Delivered Items & Credentials Vault --}}
                        <div class="divide-y divide-line p-4 sm:p-6 space-y-6">
                            @foreach ($order->items as $item)
                                <div class="{{ ! $loop->first ? 'pt-6' : '' }}">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="flex items-center gap-3 min-w-0">
                                            @if ($img = $item->product?->imageUrl())
                                                <img src="{{ $img }}" alt="{{ $item->product_name }}" class="h-11 w-11 shrink-0 rounded-lg object-cover border border-line">
                                            @else
                                                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand text-white font-bold">✓</div>
                                            @endif
                                            <div class="min-w-0">
                                                <h3 class="text-base sm:text-lg font-bold text-ink truncate">{{ $item->product_name }}</h3>
                                                <p class="text-xs text-muted">Quantity: {{ $item->quantity }} · Paid {{ money($item->line_total) }}</p>
                                            </div>
                                        </div>
                                        <span class="badge badge-success text-xs">{{ $item->delivered_qty }} / {{ $item->quantity }} Delivered</span>
                                    </div>

                                    {{-- Credentials Cards --}}
                                    <div class="mt-4 space-y-3">
                                        @foreach ($item->deliveries as $idx => $delivery)
                                            @php
                                                $rawContent = trim($delivery->content);
                                                // Detect common email:password or user|pass format for easy 1-click copy
                                                $parts = null;
                                                if (! str_contains($rawContent, "\n") && preg_match('/^([^\s:|]+@[^\s:|]+)[:|](.+)$/', $rawContent, $m)) {
                                                    $parts = ['Email / Username' => trim($m[1]), 'Password' => trim($m[2])];
                                                }
                                            @endphp
                                            <div class="rounded-xl border border-[#2B0912]/15 bg-[#120408] p-4 text-white shadow-md">
                                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-white/10 pb-2.5">
                                                    <span class="text-[11px] font-bold tracking-wider text-[#ff8fa3] uppercase">
                                                        🔑 Delivered Account / Key {{ $item->deliveries->count() > 1 ? '#'.($idx + 1) : '' }}
                                                    </span>
                                                    <div x-data="copy(@js($rawContent))">
                                                        <button type="button"
                                                                @click="copy"
                                                                class="rounded-md bg-brand px-3 py-1 text-xs font-bold text-white transition hover:bg-brand-hover"
                                                                x-text="copied ? '✓ Copied Full Credential!' : 'Copy Credential'">
                                                            Copy Credential
                                                        </button>
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
                                                                <button type="button" @click="copy" class="shrink-0 rounded bg-white/15 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-white/25" x-text="copied ? 'Copied!' : 'Copy'">Copy</button>
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
                                        <div class="mt-3.5 rounded-xl bg-brand-tint p-3.5 text-xs">
                                            <p class="font-bold text-brand-text uppercase tracking-wider">Warranty &amp; Usage Rules</p>
                                            <p class="mt-1 whitespace-pre-line text-slate break-words">{{ $item->product->warranty }}</p>
                                        </div>
                                    @endif
                                </div>
                            @endforeach

                            {{-- Copy Complete Order Receipt Bar --}}
                            <div class="flex flex-wrap items-center justify-between gap-3 pt-4" x-data="copy(@js($fullReceiptText))">
                                <button type="button" @click="copy" class="btn btn-outline text-xs sm:text-sm w-full sm:w-auto" x-text="copied ? '✓ Complete Receipt Copied!' : '📋 Copy Complete Order Receipt'">
                                    📋 Copy Complete Order Receipt
                                </button>
                                <a href="{{ route('home') }}" class="btn btn-primary text-xs sm:text-sm w-full sm:w-auto">Continue Shopping →</a>
                            </div>
                        </div>
                    </section>

                {{-- ============ CLOSED ============ --}}
                @else
                    <section class="card p-6 sm:p-8 text-center">
                        <h2 class="text-xl font-bold">This order is {{ strtolower($s->label()) }}</h2>
                        <p class="mt-2 text-xs sm:text-sm">
                            @if ($s === OrderStatus::Expired) The payment window ended and the reserved stock was released. @endif
                            @if ($s === OrderStatus::Refunded) This order was refunded. @endif
                        </p>
                        @if ($product = $order->items->first()?->product)
                            <a href="{{ route('products.show', $product) }}" class="btn btn-primary mt-5">Order again</a>
                        @endif
                    </section>
                @endif

                @if ($s === OrderStatus::PendingPayment)
                    <form method="post" action="{{ route('orders.cancel', $order) }}" onsubmit="return confirm('Cancel this order?')">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <button class="text-xs sm:text-sm text-muted underline hover:text-danger">Cancel order</button>
                    </form>
                @endif
            </div>

            {{-- RIGHT SIDEBAR: Order Summary + Clean 1-Click WhatsApp Button --}}
            <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start">
                <div class="card p-4 sm:p-6">
                    <h2 class="text-base sm:text-lg font-bold">Order Summary</h2>
                    <ul class="mt-3 space-y-2.5 text-xs sm:text-sm">
                        @foreach ($order->items as $item)
                            <li class="flex justify-between gap-3">
                                <span class="text-body break-words min-w-0">{{ $item->product_name }} × {{ $item->quantity }}</span>
                                <span class="font-semibold text-ink shrink-0">{{ money($item->line_total) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-4 flex items-end justify-between border-t border-line pt-3.5">
                        <span class="text-sm font-medium text-ink">Total</span>
                        <span class="display text-2xl sm:text-3xl text-ink">{{ money($order->total) }}</span>
                    </div>
                    <dl class="mt-3.5 space-y-2 border-t border-line pt-3.5 text-xs sm:text-sm">
                        <div class="flex justify-between gap-2"><dt class="text-muted">Order ID</dt><dd class="font-mono font-bold text-ink">{{ $order->number }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-muted">Status</dt><dd class="font-semibold text-ink">{{ $s->label() }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-muted">Gateway</dt><dd class="text-ink truncate">{{ $method?->name ?? '—' }}</dd></div>
                        @if ($order->latestPayment)
                            <div class="flex justify-between gap-2"><dt class="text-muted">TID</dt><dd class="font-mono text-ink truncate max-w-[160px]">{{ $order->latestPayment->transaction_id }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-2"><dt class="text-muted">Email</dt><dd class="text-ink truncate max-w-[180px]">{{ $order->email }}</dd></div>
                    </dl>

                    {{-- Clean Compact WhatsApp Button (Sends full order info + 1-Tap Admin links without showing long text on screen!) --}}
                    <div class="mt-4 border-t border-line pt-4 space-y-2">
                        <a href="{{ $waHref }}"
                           target="_blank"
                           rel="noopener"
                           class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] w-full py-2.5 text-xs sm:text-sm font-bold">
                            📲 Send Order Info on WhatsApp
                        </a>
                        <div x-data="copy(@js($order->customerUrl()))">
                            <button type="button" class="btn btn-outline w-full py-2 text-xs" @click="copy" x-text="copied ? '✓ Order Link Copied!' : 'Copy Order Link'">Copy Order Link</button>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.store>
