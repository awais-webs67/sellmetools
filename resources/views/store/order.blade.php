@php
    use App\Enums\OrderStatus;
    $s = $order->status;
    $method = $order->paymentMethod;
    $statusUrl = route('orders.status', ['order' => $order->number, 'token' => $token]);
    $rejected = $order->latestPayment?->status === \App\Enums\PaymentStatus::Rejected ? $order->latestPayment : null;
    $steps = [
        ['Order placed', true],
        ['Payment submitted', in_array($s, [OrderStatus::PaymentReview, OrderStatus::Processing, OrderStatus::Completed, OrderStatus::NeedsAttention], true)],
        ['Verified', in_array($s, [OrderStatus::Processing, OrderStatus::Completed, OrderStatus::NeedsAttention], true)],
        ['Delivered', $s === OrderStatus::Completed],
    ];

    $gatewayCheckoutUrl = $method ? app(\App\Services\AutoPaymentVerifier::class)->gatewayCheckoutUrl($order, $method) : null;
    $waNumber = preg_replace('/\D/', '', (string) ($method?->meta['whatsapp_number'] ?? setting('support_whatsapp') ?? ''));
    $itemsSummary = $order->items->map(fn ($i) => $i->product_name.' x'.$i->quantity)->implode(', ');
    $amountFormatted = $method && $method->currency !== 'PKR' ? $amountDue.' '.$method->currency : money($amountDue ?? $order->total);

    $waTemplate = "Assalam o Alaikum! I have placed an order on ".setting('store_name').".\n\n"
        ."🧾 *Order ID:* {$order->number}\n"
        ."📦 *Product:* {$itemsSummary}\n"
        ."💰 *Amount:* {$amountFormatted}\n"
        ."💳 *Payment Method:* ".($method?->name ?? 'Manual')."\n"
        ."🔑 *Transaction ID (TID):* ".($order->latestPayment?->transaction_id ?? '[Enter TID / Attached Screenshot]')."\n"
        ."📧 *Email:* {$order->email}\n"
        ."🔗 *Order Link:* ".$order->customerUrl()."\n\n"
        ."Please verify my payment and deliver my order. Thank you!";
@endphp
<x-layouts.store :title="'Order '.$order->number">
    <div class="container-x py-8 lg:py-12" x-data="orderWatcher(@js($statusUrl), @js($s->value))">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <span class="eyebrow">Order {{ $order->number }}</span>
                <h1 class="display mt-2 text-4xl sm:text-5xl">
                    @switch($s)
                        @case(OrderStatus::PendingPayment) Complete your payment @break
                        @case(OrderStatus::PaymentReview) Verifying your payment @break
                        @case(OrderStatus::Processing) Delivering your order @break
                        @case(OrderStatus::Completed) Your order is ready @break
                        @case(OrderStatus::NeedsAttention) We're on it @break
                        @default Order {{ strtolower($s->label()) }}
                    @endswitch
                </h1>
            </div>
            <p class="text-sm">Placed {{ $order->created_at->format('d M Y, h:i A') }}</p>
        </div>

        {{-- Progress --}}
        @unless (in_array($s, [OrderStatus::Cancelled, OrderStatus::Expired, OrderStatus::Refunded], true))
            <ol class="mt-6 grid grid-cols-4 gap-2" aria-label="Order progress">
                @foreach ($steps as $i => [$label, $done])
                    <li>
                        <div @class(['h-1.5 rounded-full', 'bg-brand' => $done, 'bg-line' => ! $done])></div>
                        <p @class(['mt-2 text-xs font-semibold sm:text-sm', 'text-ink' => $done, 'text-muted' => ! $done])>{{ $label }}</p>
                    </li>
                @endforeach
            </ol>
        @endunless

        <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_380px]">
            <div class="space-y-6">
                {{-- ============ AWAITING PAYMENT ============ --}}
                @if ($s === OrderStatus::PendingPayment && $method)
                    @if ($rejected)
                        <div class="rounded-xl border border-danger/20 bg-danger-tint p-4 text-sm text-danger" role="alert">
                            <p class="font-semibold">Your previous transaction ID ({{ $rejected->transaction_id }}) could not be verified.</p>
                            @if ($rejected->reject_reason)<p class="mt-1">Reason: {{ $rejected->reject_reason }}</p>@endif
                            <p class="mt-1">Please check and submit the correct transaction ID or contact us on WhatsApp below.</p>
                        </div>
                    @endif

                    <section class="card overflow-hidden">
                        <div class="flex items-center justify-between gap-4 bg-ink px-6 py-4 text-white" x-data="countdown(@js($order->expires_at?->toIso8601String()))">
                            <p class="text-sm font-medium">Stock reserved for you</p>
                            <p class="display text-3xl text-white tabular-nums" :class="remaining < 120 && 'text-brand'" x-text="text">--:--</p>
                        </div>

                        <div class="p-5 sm:p-6">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-lg font-bold">Step 1 — Send Payment via {{ $method->name }}</h2>
                                <span class="badge badge-brand">{{ $method->type === 'auto' ? 'Instant Auto Gateway' : 'Auto-TID / Manual Verify' }}</span>
                            </div>

                            @if ($gatewayCheckoutUrl)
                                <div class="mt-4 rounded-xl border border-brand/30 bg-brand-tint p-4">
                                    <p class="text-sm font-semibold text-ink">Pay Online Instantly</p>
                                    <p class="mt-1 text-xs text-body">Click the button below to complete your payment on the {{ $method->name }} checkout page, then enter your reference ID below.</p>
                                    <a href="{{ $gatewayCheckoutUrl }}" target="_blank" rel="noopener" class="btn btn-primary mt-3">
                                        Pay {{ $amountFormatted }} on {{ $method->name }} →
                                    </a>
                                </div>
                            @endif

                            <dl class="mt-4 divide-y divide-line rounded-xl border border-line">
                                <div class="flex items-center justify-between gap-4 p-4">
                                    <dt class="text-sm">Exact Amount to Send</dt>
                                    <dd class="flex items-center gap-3" x-data="copy(@js((string) $amountDue))">
                                        <span class="display text-3xl text-ink">{{ $amountFormatted }}</span>
                                        <button type="button" class="btn btn-outline px-3 py-1.5 text-xs" @click="copy" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                                    </dd>
                                </div>
                                @if ($method->account_number && $method->account_number !== 'Set in admin')
                                    <div class="flex items-center justify-between gap-4 p-4">
                                        <dt class="text-sm">{{ $method->code === 'binance' ? 'Binance Pay ID' : ($method->code === 'usdt_trc20' ? 'USDT Wallet Address' : 'Account Number / IBAN') }}</dt>
                                        <dd class="flex items-center gap-3" x-data="copy(@js($method->account_number))">
                                            <span class="font-mono text-base sm:text-lg font-bold break-all text-ink">{{ $method->account_number }}</span>
                                            <button type="button" class="btn btn-outline shrink-0 px-3 py-1.5 text-xs" @click="copy" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                                        </dd>
                                    </div>
                                @endif
                                @if ($method->account_title && $method->account_title !== 'Set in admin')
                                    <div class="flex items-center justify-between gap-4 p-4">
                                        <dt class="text-sm">Account Title / Network</dt>
                                        <dd class="font-semibold text-ink">{{ $method->account_title }}</dd>
                                    </div>
                                @endif
                            </dl>

                            @if ($method->instructions)
                                <p class="mt-4 text-sm leading-6 whitespace-pre-line">{{ $method->instructions }}</p>
                            @endif
                        </div>

                        <form method="post" action="{{ route('orders.payment', $order) }}" enctype="multipart/form-data" class="border-t border-line bg-grey-soft p-5 sm:p-6">
                            @csrf
                            <input type="hidden" name="token" value="{{ $token }}">
                            <h2 class="text-lg font-bold">Step 2 — Submit Transaction ID (Auto / Manual Verify)</h2>
                            <p class="mt-1 text-xs text-muted">If our system already received your payment SMS/notification, your order will be delivered automatically in 1 second!</p>
                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label for="transaction_id" class="label">Transaction ID (TID / Order ID / Ref#) <span class="text-danger">*</span></label>
                                    <input id="transaction_id" name="transaction_id" required class="input font-mono uppercase" value="{{ old('transaction_id') }}" placeholder="{{ $method->code === 'binance' ? 'Binance Order ID / TxID' : 'e.g. 01234567891' }}" autocomplete="off">
                                    @error('transaction_id')<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                                @if ($method->requires_sender_number)
                                    <div>
                                        <label for="sender_number" class="label">Sender Account Number <span class="text-danger">*</span></label>
                                        <input id="sender_number" name="sender_number" required class="input" value="{{ old('sender_number', $order->phone) }}" placeholder="03xx xxxxxxx" inputmode="tel">
                                        @error('sender_number')<p class="field-error">{{ $message }}</p>@enderror
                                    </div>
                                @endif
                                <div @class(['sm:col-span-2' => ! $method->requires_sender_number])>
                                    <label for="screenshot" class="label">Payment Screenshot (Optional)</label>
                                    <input id="screenshot" name="screenshot" type="file" accept="image/*" class="input py-2 file:mr-3 file:rounded file:border-0 file:bg-ink file:px-3 file:py-1 file:text-xs file:font-semibold file:text-white">
                                    @error('screenshot')<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg mt-5 w-full">I Have Paid — Verify &amp; Get Product Now</button>
                        </form>
                    </section>

                {{-- ============ VERIFYING / PROCESSING ============ --}}
                @elseif (in_array($s, [OrderStatus::PaymentReview, OrderStatus::Processing], true))
                    <section class="card p-8 text-center">
                        <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-brand-tint-2 border-t-brand" aria-hidden="true"></div>
                        <h2 class="mt-5 text-xl font-bold">{{ $s === OrderStatus::PaymentReview ? 'Payment Submitted — Verifying' : 'Payment Verified — Delivering Now' }}</h2>
                        <p class="mx-auto mt-2 max-w-md text-sm">This page refreshes automatically every 5 seconds. As soon as your payment is confirmed, your credentials will appear right here!</p>
                        @if ($order->latestPayment)
                            <p class="mt-4 text-sm">Submitted TID: <span class="font-mono font-bold text-ink">{{ $order->latestPayment->transaction_id }}</span></p>
                        @endif
                    </section>

                {{-- ============ COMPLETED / PARTIAL ============ --}}
                @elseif (in_array($s, [OrderStatus::Completed, OrderStatus::NeedsAttention], true))
                    @if ($s === OrderStatus::NeedsAttention)
                        <div class="rounded-xl border border-brand/30 bg-brand-tint p-4 text-sm text-brand-text" role="status">
                            Your payment is confirmed! Part of your order is being finalized by our team and will appear here shortly. You can also message us on WhatsApp below.
                        </div>
                    @endif

                    @foreach ($order->items as $item)
                        <section class="card p-6">
                            <div class="flex items-start justify-between gap-4">
                                <h2 class="text-lg font-bold">{{ $item->product_name }}</h2>
                                <span class="badge {{ $item->delivered_qty >= $item->quantity ? 'badge-success' : 'badge-brand' }}">{{ $item->delivered_qty }} / {{ $item->quantity }} delivered</span>
                            </div>
                            <ul class="mt-4 space-y-3">
                                @foreach ($item->deliveries as $delivery)
                                    <li class="flex flex-col gap-3 rounded-xl border border-line bg-grey-soft p-4 sm:flex-row sm:items-center sm:justify-between" x-data="copy(@js($delivery->content))">
                                        <code class="font-mono text-sm break-all text-ink">{{ $delivery->content }}</code>
                                        <button type="button" class="btn btn-dark shrink-0 px-4 py-2 text-xs" @click="copy" x-text="copied ? 'Copied!' : 'Copy'">Copy</button>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($item->product?->warranty)
                                <p class="mt-4 text-xs leading-5 whitespace-pre-line text-muted"><span class="font-semibold text-slate">Warranty:</span> {{ $item->product->warranty }}</p>
                            @endif
                        </section>
                    @endforeach

                {{-- ============ CLOSED ============ --}}
                @else
                    <section class="card p-8 text-center">
                        <h2 class="text-xl font-bold">This order is {{ strtolower($s->label()) }}</h2>
                        <p class="mt-2 text-sm">
                            @if ($s === OrderStatus::Expired) The payment window ended and the reserved stock was released. @endif
                            @if ($s === OrderStatus::Refunded) This order was refunded. @endif
                            If you already paid, please send us the pre-filled WhatsApp message below.
                        </p>
                        @if ($product = $order->items->first()?->product)
                            <a href="{{ route('products.show', $product) }}" class="btn btn-primary mt-6">Order again</a>
                        @endif
                    </section>
                @endif

                {{-- ============ MANUAL WHATSAPP VERIFICATION TEMPLATE CARD ============ --}}
                @if ($s !== OrderStatus::Completed)
                    <section class="card border-2 border-[#25D366]/40 bg-[#F4FBF7] p-5 sm:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <span class="badge bg-[#25D366] text-white">Fast Manual Verification</span>
                                <h2 class="mt-2 text-lg font-bold text-ink">Paid? Send Order Template on WhatsApp</h2>
                                <p class="mt-0.5 text-xs text-body">Copy this ready-made order receipt or click the WhatsApp button to send your payment proof directly to admin for instant confirmation.</p>
                            </div>
                        </div>

                        <div class="mt-4 rounded-xl border border-line bg-white p-3.5">
                            <pre class="font-mono text-xs leading-relaxed whitespace-pre-wrap text-ink">{{ $waTemplate }}</pre>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-3" x-data="copy(@js($waTemplate))">
                            @if ($waNumber !== '')
                                <a href="https://wa.me/{{ $waNumber }}?text={{ urlencode($waTemplate) }}"
                                   target="_blank" rel="noopener"
                                   class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b]">
                                    Send on WhatsApp Now →
                                </a>
                            @endif
                            <button type="button" class="btn btn-outline" @click="copy" x-text="copied ? 'Template Copied!' : 'Copy Message Template'">Copy Message Template</button>
                            @if ($tg = setting('support_telegram'))
                                <a href="https://t.me/{{ ltrim($tg, '@') }}" target="_blank" rel="noopener" class="btn btn-outline">Send on Telegram</a>
                            @endif
                        </div>
                    </section>
                @endif

                @if ($s === OrderStatus::PendingPayment)
                    <form method="post" action="{{ route('orders.cancel', $order) }}" onsubmit="return confirm('Cancel this order?')">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <button class="text-sm text-muted underline hover:text-danger">Cancel order</button>
                    </form>
                @endif
            </div>

            <aside class="space-y-6 lg:sticky lg:top-24 lg:self-start">
                <div class="card p-6">
                    <h2 class="text-lg font-bold">Order Summary</h2>
                    <ul class="mt-4 space-y-3 text-sm">
                        @foreach ($order->items as $item)
                            <li class="flex justify-between gap-4"><span>{{ $item->product_name }} × {{ $item->quantity }}</span><span class="font-semibold text-ink">{{ money($item->line_total) }}</span></li>
                        @endforeach
                    </ul>
                    <div class="mt-4 flex items-end justify-between border-t border-line pt-4">
                        <span class="font-medium text-ink">Total</span>
                        <span class="display text-3xl text-ink">{{ money($order->total) }}</span>
                    </div>
                    <dl class="mt-4 space-y-2 border-t border-line pt-4 text-sm">
                        <div class="flex justify-between"><dt>Order ID</dt><dd class="font-mono font-bold text-ink">{{ $order->number }}</dd></div>
                        <div class="flex justify-between"><dt>Status</dt><dd class="font-semibold text-ink">{{ $s->label() }}</dd></div>
                        <div class="flex justify-between"><dt>Method</dt><dd class="text-ink">{{ $method?->name ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt>Email</dt><dd class="text-ink break-all">{{ $order->email }}</dd></div>
                    </dl>
                </div>
                <div class="card p-6 text-sm">
                    <p class="font-semibold text-ink">Save Your Order Link</p>
                    <p class="mt-1 text-xs text-muted">Bookmark this page to view your delivered credentials anytime.</p>
                    <div class="mt-3" x-data="copy(@js($order->customerUrl()))">
                        <button type="button" class="btn btn-outline w-full" @click="copy" x-text="copied ? 'Link Copied!' : 'Copy Order Link'">Copy Order Link</button>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.store>
