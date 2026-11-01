@php
    use App\Enums\OrderStatus;
    $isCompleted = $order->status === OrderStatus::Completed;
    $isNeedsAttention = $order->status === OrderStatus::NeedsAttention;
    $isAlreadyCompleted = $actionResult === 'already_completed';
    $isRejected = $payment?->status === \App\Enums\PaymentStatus::Rejected || $order->status === OrderStatus::Cancelled;
    $customerPhone = format_whatsapp($order->phone ?: $payment?->sender_number);

    $deliveredLines = $order->items->flatMap(fn ($item) => $item->deliveries->pluck('content'))->all();
    $itemsSummary = $order->items->map(fn ($i) => "{$i->product_name} x{$i->quantity}")->implode(', ');

    if ($isCompleted) {
        $customerWaText = "Assalam o Alaikum! ✅ Your payment for Order *{$order->number}* ({$itemsSummary}) has been verified and your order is confirmed!\n\n"
            .($deliveredLines !== [] ? "🔑 *Your Credentials / Activation:*\n".implode("\n---\n", $deliveredLines)."\n\n" : '')
            ."🔗 *View Order Page:* ".$order->customerUrl()."\n\n"
            ."Thank you for shopping with ".setting('store_name')."!";
    } elseif ($isRejected) {
        $customerWaText = "Assalam o Alaikum! ❌ Regarding your Order *{$order->number}* ({$itemsSummary}): your Transaction ID (".($payment?->transaction_id ?? 'N/A').") could not be verified.\n\n"
            ."Please reply with your payment screenshot or submit the correct TID here:\n".$order->customerUrl();
    } else {
        $customerWaText = "Assalam o Alaikum! ✅ Your payment for Order *{$order->number}* ({$itemsSummary}) on ".setting('store_name')." has been verified! Here are your order details:\n".$order->customerUrl();
    }
@endphp
<x-layouts.store :title="'1-Tap Order Control – '.$order->number" robots="noindex, nofollow">
    <div class="container-x py-6 sm:py-10">
        <div class="mx-auto max-w-lg">
            <section class="card overflow-hidden border-2 border-brand/25 shadow-xl">
                {{-- Top Status Header --}}
                <div @class([
                    'px-5 py-5 sm:px-6 text-white',
                    'bg-[#b45309]' => $isAlreadyCompleted || $isNeedsAttention || $actionResult === 'needs_attention',
                    'bg-[#15803d]' => ! $isAlreadyCompleted && ! $isNeedsAttention && ($isCompleted || $actionResult === 'approve'),
                    'bg-[#b91c1c]' => ! $isAlreadyCompleted && ($isRejected || $actionResult === 'reject'),
                    'bg-[#120408]' => ! $isAlreadyCompleted && ! $isCompleted && ! $isNeedsAttention && ! $isRejected && ! in_array($actionResult, ['approve', 'reject', 'needs_attention'], true),
                ])>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="rounded-full bg-white/15 px-3 py-0.5 text-[11px] font-bold tracking-wider uppercase text-white">
                            🔒 Admin 1-Tap Order Control
                        </span>
                        <span class="badge bg-white/20 text-white text-xs">{{ $order->status->label() }}</span>
                    </div>

                    <h1 class="display mt-2.5 text-3xl sm:text-4xl text-white">
                        @if ($isAlreadyCompleted)
                            ⚠️ Already Confirmed &amp; Delivered!
                        @elseif ($isCompleted)
                            ✅ Order Approved &amp; Delivered!
                        @elseif ($isNeedsAttention)
                            ✅ Payment Confirmed — ⚠️ Delivery On Hold
                        @elseif ($isRejected)
                            ❌ Order Declined
                        @else
                            Order {{ $order->number }}
                        @endif
                    </h1>

                    @if ($message)
                        <p class="mt-1 text-xs sm:text-sm text-white/90 font-medium">{{ $message }}</p>
                    @endif
                </div>

                <div class="p-4 sm:p-6 space-y-5">
                    @if ($isAlreadyCompleted)
                        <div class="rounded-xl border-2 border-amber-500/40 bg-amber-500/10 p-4 text-xs sm:text-sm text-ink">
                            <p class="font-bold text-amber-900">🛑 Duplicate Approval Prevented</p>
                            <p class="mt-1 text-body">
                                This order was <strong>already verified and delivered</strong> ({{ $order->completed_at ? $order->completed_at->format('d M Y, h:i A') : 'earlier' }}). The customer already has their credentials on the order page.
                            </p>
                        </div>
                    @endif

                    @if ($isNeedsAttention)
                        <div class="rounded-xl border-2 border-amber-500/40 bg-amber-500/10 p-4 text-xs sm:text-sm text-ink">
                            <p class="font-bold text-amber-900">⚠️ Why Delivery is Pending / On Hold:</p>
                            <p class="mt-1 font-mono text-xs text-danger break-words">{{ $order->admin_note ?: 'No stock or supplier balance available.' }}</p>
                            <p class="mt-2 text-xs text-body">
                                <strong>What to do:</strong> Either top up your supplier balance &amp; tap <strong>Retry Supplier Delivery</strong>, OR paste the customer's credentials below &amp; tap <strong>Deliver Pasted Credentials</strong>, OR tap <strong>Mark Delivered via WhatsApp</strong>!
                            </p>
                        </div>
                    @endif

                    {{-- Order Details Summary Table --}}
                    <dl class="divide-y divide-line rounded-xl border border-line text-xs sm:text-sm">
                        <div class="flex justify-between gap-3 p-3">
                            <dt class="text-muted">Order ID</dt>
                            <dd class="font-mono font-bold text-ink">{{ $order->number }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 p-3">
                            <dt class="text-muted">Product</dt>
                            <dd class="font-semibold text-ink text-right break-words">{{ $itemsSummary }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 p-3">
                            <dt class="text-muted">Total Amount</dt>
                            <dd class="font-bold text-ink">{{ money($order->total) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 p-3">
                            <dt class="text-muted">Payment Status</dt>
                            <dd class="font-semibold text-ink">
                                {{ $order->paid_at ? '✅ Confirmed ('.$order->paid_at->format('h:i A').')' : ($order->paymentMethod?->name ?? 'Pending Review') }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3 p-3">
                            <dt class="text-muted">Transaction ID (TID)</dt>
                            <dd class="font-mono font-bold text-brand break-all text-right">{{ $payment?->transaction_id ?? 'WhatsApp Verification' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 p-3">
                            <dt class="text-muted">Customer</dt>
                            <dd class="text-right text-xs text-ink break-all">
                                <div>{{ $order->email }}</div>
                                @if ($order->phone || $payment?->sender_number)
                                    <div class="font-mono text-muted">{{ $order->phone ?: $payment?->sender_number }}</div>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    {{-- 1-Tap Action Form (Requires Admin Secret Key if not unlocked yet) --}}
                    @if (! $isCompleted && ! $isRejected)
                        <form method="post" action="{{ route('webhooks.order.quick', ['order' => $order->number]) }}" class="rounded-xl border-2 border-brand/30 bg-brand-tint/35 p-4 space-y-4">
                            @csrf

                            @if ($needsPin)
                                <div>
                                    <label for="pin" class="label text-xs sm:text-sm font-bold text-ink">
                                        🔑 Admin Secret Key <span class="text-danger">*</span>
                                        <span class="block text-[11px] font-normal text-muted">Enter the Admin 1-Tap PIN saved in your Admin Panel → Store Settings (remembered on your phone for 1 year).</span>
                                    </label>
                                    <input id="pin"
                                           name="pin"
                                           type="password"
                                           required
                                           autofocus
                                           class="input mt-1.5 text-center font-mono text-lg tracking-widest"
                                           placeholder="••••">
                                </div>
                            @endif

                            <div>
                                <label for="credentials" class="label text-xs font-semibold text-ink">
                                    {{ $isNeedsAttention ? 'Paste Credentials to Deliver Manually (or leave blank to Retry Supplier)' : 'Optional: Paste Account Credentials / Activation Link' }}
                                    <span class="block text-[11px] font-normal text-muted">
                                        For multiple pcs, separate each unit with <code>---</code> on its own line, or 1 unit per line. Leave blank to auto-deliver from .TXT stock or supplier API.
                                    </span>
                                </label>
                                <textarea id="credentials"
                                          name="credentials"
                                          rows="3"
                                          class="input mt-1 font-mono text-xs"
                                          placeholder="Email: user@email.com&#10;Pass: secret123&#10;---&#10;Email: user2@email.com">{{ old('credentials') }}</textarea>
                            </div>

                            @if ($isNeedsAttention)
                                <div class="grid grid-cols-1 gap-2.5">
                                    <button type="submit"
                                            name="do"
                                            value="retry"
                                            class="btn bg-[#16a34a] text-white hover:bg-[#15803d] py-3 text-xs sm:text-sm font-bold shadow-md">
                                        🔄 Deliver Pasted Credentials / Retry Supplier API
                                    </button>
                                    <button type="submit"
                                            name="do"
                                            value="whatsapp_delivered"
                                            class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] py-3 text-xs sm:text-sm font-bold shadow-md">
                                        📲 Mark Delivered to Customer via WhatsApp
                                    </button>
                                </div>
                            @else
                                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                                    <button type="submit"
                                            name="do"
                                            value="approve"
                                            class="btn bg-[#16a34a] text-white hover:bg-[#15803d] py-3.5 text-sm font-bold shadow-md">
                                        ✅ Approve &amp; Attempt Delivery
                                    </button>
                                    <button type="submit"
                                            name="do"
                                            value="reject"
                                            class="btn bg-[#dc2626] text-white hover:bg-[#b91c1c] py-3.5 text-sm font-bold shadow-md">
                                        ❌ Decline Order
                                    </button>
                                </div>
                            @endif
                        </form>
                    @endif

                    @if ($deliveredLines !== [])
                        <div class="rounded-xl border border-success/30 bg-success-tint p-4">
                            <p class="text-xs font-bold uppercase tracking-wider text-success">Delivered Credentials</p>
                            <pre class="mt-2 font-mono text-xs whitespace-pre-wrap break-all text-ink">{{ implode("\n---\n", $deliveredLines) }}</pre>
                        </div>
                    @endif

                    {{-- Direct WhatsApp Reply + Open Admin Panel --}}
                    <div class="space-y-2.5 border-t border-line pt-4">
                        @if ($customerPhone !== '')
                            <a href="https://wa.me/{{ $customerPhone }}?text={{ urlencode($customerWaText) }}"
                               target="_blank"
                               rel="noopener"
                               class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] w-full py-3 text-xs sm:text-sm font-bold">
                                📲 Message Customer on WhatsApp ({{ $isCompleted ? 'Send Credentials' : 'Order Update' }}) →
                            </a>
                        @endif
                        <div class="grid grid-cols-2 gap-2.5">
                            <a href="{{ url('/admin/orders/'.$order->id) }}" class="btn btn-dark py-2.5 text-xs font-bold text-center">
                                ⚙️ Open Order in Admin Panel
                            </a>
                            <a href="{{ $order->customerUrl() }}" class="btn btn-outline py-2.5 text-xs font-semibold text-center">
                                View Customer Order Page
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-layouts.store>
