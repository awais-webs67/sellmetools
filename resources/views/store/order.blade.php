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
@endphp
<x-layouts.store :title="'Order '.$order->number">
    <div class="container-x py-8 lg:py-12" x-data="orderWatcher(@js($statusUrl), @js($s->value))">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <span class="eyebrow">Order {{ $order->number }}</span>
                <h1 class="display mt-2 text-5xl">
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
            <ol class="mt-8 grid grid-cols-4 gap-2" aria-label="Order progress">
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
                        <div class="rounded-md border border-danger/20 bg-danger-tint p-4 text-sm text-danger" role="alert">
                            <p class="font-semibold">Your previous transaction ID ({{ $rejected->transaction_id }}) could not be verified.</p>
                            @if ($rejected->reject_reason)<p class="mt-1">Reason: {{ $rejected->reject_reason }}</p>@endif
                            <p class="mt-1">Please check and submit the correct transaction ID.</p>
                        </div>
                    @endif

                    <section class="card overflow-hidden">
                        <div class="flex items-center justify-between gap-4 bg-ink px-6 py-4 text-white" x-data="countdown(@js($order->expires_at?->toIso8601String()))">
                            <p class="text-sm font-medium">Stock reserved for you</p>
                            <p class="display text-3xl text-white tabular-nums" :class="remaining < 120 && 'text-brand'" x-text="text">--:--</p>
                        </div>
                        <div class="p-6">
                            <h2 class="text-lg font-semibold">Step 1 — Send payment via {{ $method->name }}</h2>
                            <dl class="mt-4 divide-y divide-line rounded-md border border-line">
                                <div class="flex items-center justify-between gap-4 p-4">
                                    <dt class="text-sm">Amount</dt>
                                    <dd class="flex items-center gap-3" x-data="copy(@js((string) $amountDue))">
                                        <span class="display text-3xl text-ink">{{ $method->currency === 'PKR' ? money($amountDue) : $amountDue.' '.$method->currency }}</span>
                                        <button type="button" class="btn btn-outline px-3 py-1.5 text-xs" @click="copy" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                                    </dd>
                                </div>
                                @if ($method->account_number)
                                    <div class="flex items-center justify-between gap-4 p-4">
                                        <dt class="text-sm">{{ $method->code === 'binance' ? 'Binance Pay ID' : 'Account number' }}</dt>
                                        <dd class="flex items-center gap-3" x-data="copy(@js($method->account_number))">
                                            <span class="font-mono text-lg font-semibold text-ink">{{ $method->account_number }}</span>
                                            <button type="button" class="btn btn-outline px-3 py-1.5 text-xs" @click="copy" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                                        </dd>
                                    </div>
                                @endif
                                @if ($method->account_title)
                                    <div class="flex items-center justify-between gap-4 p-4">
                                        <dt class="text-sm">Account title</dt>
                                        <dd class="font-semibold text-ink">{{ $method->account_title }}</dd>
                                    </div>
                                @endif
                            </dl>
                            @if ($method->instructions)
                                <p class="mt-4 text-sm leading-6 whitespace-pre-line">{{ $method->instructions }}</p>
                            @endif
                            <p class="mt-3 text-sm font-medium text-brand-text">Send the exact amount. Different amounts may delay verification.</p>
                        </div>

                        <form method="post" action="{{ route('orders.payment', $order) }}" enctype="multipart/form-data" class="border-t border-line bg-grey-soft p-6">
                            @csrf
                            <input type="hidden" name="token" value="{{ $token }}">
                            <h2 class="text-lg font-semibold">Step 2 — Submit your transaction ID</h2>
                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label for="transaction_id" class="label">Transaction ID (TID) <span class="text-danger">*</span></label>
                                    <input id="transaction_id" name="transaction_id" required class="input font-mono uppercase" value="{{ old('transaction_id') }}" placeholder="{{ $method->code === 'binance' ? 'Binance Order ID / TxID' : 'e.g. 12345678901' }}" autocomplete="off">
                                    @error('transaction_id')<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                                @if ($method->requires_sender_number)
                                    <div>
                                        <label for="sender_number" class="label">Sender account number <span class="text-danger">*</span></label>
                                        <input id="sender_number" name="sender_number" required class="input" value="{{ old('sender_number') }}" placeholder="03xx xxxxxxx" inputmode="tel">
                                        @error('sender_number')<p class="field-error">{{ $message }}</p>@enderror
                                    </div>
                                @endif
                                <div @class(['sm:col-span-2' => ! $method->requires_sender_number])>
                                    <label for="screenshot" class="label">Screenshot (optional)</label>
                                    <input id="screenshot" name="screenshot" type="file" accept="image/*" class="input py-2 file:mr-3 file:rounded file:border-0 file:bg-ink file:px-3 file:py-1 file:text-xs file:font-semibold file:text-white">
                                    @error('screenshot')<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg mt-5 w-full sm:w-auto">I have paid – verify now</button>
                        </form>
                    </section>

                    <form method="post" action="{{ route('orders.cancel', $order) }}" onsubmit="return confirm('Cancel this order?')">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <button class="text-sm text-muted underline hover:text-danger">Cancel order</button>
                    </form>

                {{-- ============ VERIFYING / PROCESSING ============ --}}
                @elseif (in_array($s, [OrderStatus::PaymentReview, OrderStatus::Processing], true))
                    <section class="card p-8 text-center">
                        <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-brand-tint-2 border-t-brand" aria-hidden="true"></div>
                        <h2 class="mt-5 text-xl font-semibold">{{ $s === OrderStatus::PaymentReview ? 'Payment submitted – verifying' : 'Payment verified – delivering' }}</h2>
                        <p class="mx-auto mt-2 max-w-md">This page updates automatically. You can close it and come back any time using this link or “Track order”.</p>
                        @if ($order->latestPayment)
                            <p class="mt-4 text-sm">TID: <span class="font-mono font-semibold text-ink">{{ $order->latestPayment->transaction_id }}</span></p>
                        @endif
                    </section>

                {{-- ============ COMPLETED / PARTIAL ============ --}}
                @elseif (in_array($s, [OrderStatus::Completed, OrderStatus::NeedsAttention], true))
                    @if ($s === OrderStatus::NeedsAttention)
                        <div class="rounded-md border border-brand/30 bg-brand-tint p-4 text-sm text-brand-text" role="status">
                            Your payment is confirmed. Part of your order needs a manual check by our team – it will appear here shortly. No action is needed from you.
                        </div>
                    @endif

                    @foreach ($order->items as $item)
                        <section class="card p-6">
                            <div class="flex items-start justify-between gap-4">
                                <h2 class="text-lg font-semibold">{{ $item->product_name }}</h2>
                                <span class="badge {{ $item->delivered_qty >= $item->quantity ? 'badge-success' : 'badge-brand' }}">{{ $item->delivered_qty }} / {{ $item->quantity }} delivered</span>
                            </div>
                            <ul class="mt-4 space-y-3">
                                @foreach ($item->deliveries as $delivery)
                                    <li class="flex flex-col gap-3 rounded-md border border-line bg-grey-soft p-4 sm:flex-row sm:items-center sm:justify-between" x-data="copy(@js($delivery->content))">
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
                    <p class="text-sm">Keep this page private – anyone with the link can see your credentials. Format usually is <span class="font-mono">email:password</span>.</p>

                {{-- ============ CLOSED ============ --}}
                @else
                    <section class="card p-8 text-center">
                        <h2 class="text-xl font-semibold">This order is {{ strtolower($s->label()) }}</h2>
                        <p class="mt-2">
                            @if ($s === OrderStatus::Expired) The payment window ended and the reserved stock was released. @endif
                            @if ($s === OrderStatus::Refunded) This order was refunded. @endif
                            If you already paid, please contact support with your order number.
                        </p>
                        @if ($product = $order->items->first()?->product)
                            <a href="{{ route('products.show', $product) }}" class="btn btn-primary mt-6">Order again</a>
                        @endif
                    </section>
                @endif
            </div>

            <aside class="space-y-6 lg:sticky lg:top-28 lg:self-start">
                <div class="card p-6">
                    <h2 class="text-lg font-semibold">Summary</h2>
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
                        <div class="flex justify-between"><dt>Status</dt><dd class="font-semibold text-ink">{{ $s->label() }}</dd></div>
                        <div class="flex justify-between"><dt>Payment</dt><dd class="text-ink">{{ $method?->name ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt>Email</dt><dd class="text-ink">{{ $order->email }}</dd></div>
                    </dl>
                </div>
                <div class="card p-6 text-sm">
                    <p class="font-semibold text-ink">Save this link</p>
                    <p class="mt-1">Bookmark this page to access your order later.</p>
                    <div class="mt-3" x-data="copy(@js($order->customerUrl()))">
                        <button type="button" class="btn btn-outline w-full" @click="copy" x-text="copied ? 'Link copied' : 'Copy order link'">Copy order link</button>
                    </div>
                    @if (setting('support_whatsapp') || setting('support_telegram'))
                        <p class="mt-4">Need help?
                            @if ($wa = setting('support_whatsapp'))<a class="font-semibold text-brand-text underline" href="https://wa.me/{{ preg_replace('/\D/', '', $wa) }}?text={{ urlencode('Order '.$order->number) }}" target="_blank" rel="noopener">WhatsApp</a>@endif
                            @if ($tg = setting('support_telegram')) · <a class="font-semibold text-brand-text underline" href="https://t.me/{{ ltrim($tg, '@') }}" target="_blank" rel="noopener">Telegram</a>@endif
                        </p>
                    @endif
                </div>
            </aside>
        </div>
    </div>
</x-layouts.store>

