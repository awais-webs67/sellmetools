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
<x-layouts.store :title="'1-Tap Order Control: '.$order->number" robots="noindex, nofollow">
    <div class="container-x py-6 sm:py-10">
        <div class="mx-auto max-w-xl">
            <section class="card overflow-hidden border-2 border-brand/20 shadow-2xl">
                {{-- Top Status Header with dynamic color coding --}}
                <div @class([
                    'px-5 py-6 sm:px-7 text-white relative overflow-hidden',
                    'bg-gradient-to-br from-amber-700 to-amber-900' => $isAlreadyCompleted || $isNeedsAttention || $actionResult === 'needs_attention',
                    'bg-gradient-to-br from-emerald-700 to-emerald-900' => ! $isAlreadyCompleted && ! $isNeedsAttention && ($isCompleted || $actionResult === 'approve'),
                    'bg-gradient-to-br from-rose-800 to-rose-950' => ! $isAlreadyCompleted && ($isRejected || $actionResult === 'reject'),
                    'bg-gradient-to-br from-[#1F070E] via-[#2D0A14] to-[#120408]' => ! $isAlreadyCompleted && ! $isCompleted && ! $isNeedsAttention && ! $isRejected && ! in_array($actionResult, ['approve', 'reject', 'needs_attention'], true),
                ])>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/20 px-3 py-1 text-[11px] font-bold tracking-wider uppercase text-white shadow-sm">
                            <span class="h-2 w-2 rounded-full bg-white animate-pulse"></span>
                            Admin 1-Tap Control
                        </span>
                        <span class="badge bg-white/25 text-white font-mono text-xs">{{ $order->status->label() }}</span>
                    </div>

                    <h1 class="display mt-3 text-3xl sm:text-4xl text-white tracking-wide">
                        @if ($isAlreadyCompleted)
                            ⚠️ Already Confirmed &amp; Delivered!
                        @elseif ($isCompleted)
                            ✅ Order Approved &amp; Delivered!
                        @elseif ($isNeedsAttention)
                            ✅ Payment Confirmed: ⚠️ Delivery On Hold
                        @elseif ($isRejected)
                            ❌ Order Declined
                        @else
                            Order {{ $order->number }}
                        @endif
                    </h1>

                    @if ($message)
                        <div class="mt-2.5 rounded-lg bg-black/25 px-3 py-2 text-xs sm:text-sm text-white/95 font-medium border border-white/10">
                            {{ $message }}
                        </div>
                    @endif
                </div>

                <div class="p-5 sm:p-7 space-y-6">
                    @if ($isAlreadyCompleted)
                        <div class="rounded-xl border-2 border-amber-500/40 bg-amber-500/10 p-4 text-xs sm:text-sm text-ink">
                            <p class="font-bold text-amber-900 flex items-center gap-1.5">
                                <span>🛑</span> Duplicate Approval Prevented
                            </p>
                            <p class="mt-1 text-body">
                                This order was <strong>already verified and delivered</strong> ({{ $order->completed_at ? $order->completed_at->format('d M Y, h:i A') : 'earlier' }}). Credentials are live on the customer's order page.
                            </p>
                        </div>
                    @endif

                    @if ($isNeedsAttention)
                        <div class="rounded-xl border-2 border-amber-500/40 bg-amber-500/10 p-4 text-xs sm:text-sm text-ink">
                            <p class="font-bold text-amber-900 flex items-center gap-1.5">
                                <span>⚠️</span> Why Delivery is Pending / On Hold:
                            </p>
                            <p class="mt-1 font-mono text-xs text-danger break-words bg-white/70 p-2.5 rounded-lg border border-amber-300">
                                {{ $order->admin_note ?: 'No stock or supplier balance available.' }}
                            </p>
                            <p class="mt-2 text-xs text-body">
                                <strong>Next Action:</strong> Either top up supplier funds &amp; tap <strong>Retry Supplier Delivery</strong>, OR paste credentials below &amp; tap <strong>Deliver Pasted Credentials</strong>, OR tap <strong>Mark Delivered via WhatsApp</strong>!
                            </p>
                        </div>
                    @endif

                    {{-- Order Details Summary Table --}}
                    <dl class="divide-y divide-line rounded-xl border border-line bg-grey-soft/50 text-xs sm:text-sm shadow-xs">
                        <div class="flex justify-between items-center gap-3 p-3.5">
                            <dt class="text-muted font-medium">Order Number</dt>
                            <dd class="font-mono font-bold text-ink">{{ $order->number }}</dd>
                        </div>
                        <div class="flex justify-between items-center gap-3 p-3.5">
                            <dt class="text-muted font-medium">Product / Quantity</dt>
                            <dd class="font-semibold text-ink text-right break-words">{{ $itemsSummary }}</dd>
                        </div>
                        <div class="flex justify-between items-center gap-3 p-3.5">
                            <dt class="text-muted font-medium">Total Amount</dt>
                            <dd class="display text-xl text-brand font-bold">{{ money($order->total) }}</dd>
                        </div>
                        <div class="flex justify-between items-center gap-3 p-3.5">
                            <dt class="text-muted font-medium">Payment Method</dt>
                            <dd class="font-semibold text-ink">
                                {{ $order->paymentMethod?->name ?? 'Direct Payment' }}
                            </dd>
                        </div>
                        <div class="flex justify-between items-center gap-3 p-3.5">
                            <dt class="text-muted font-medium">Transaction ID (TID)</dt>
                            <dd class="font-mono font-bold text-brand bg-brand-tint px-2.5 py-1 rounded text-right break-all">
                                {{ $payment?->transaction_id ?? 'WhatsApp Verification' }}
                            </dd>
                        </div>
                        <div class="flex justify-between items-start gap-3 p-3.5">
                            <dt class="text-muted font-medium">Customer</dt>
                            <dd class="text-right text-xs text-ink break-all">
                                <div class="font-medium">{{ $order->email }}</div>
                                @if ($order->phone || $payment?->sender_number)
                                    <div class="font-mono text-muted mt-0.5">{{ $order->phone ?: $payment?->sender_number }}</div>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    {{-- 1-Tap Action Form (Requires Admin Secret Key if not unlocked yet) --}}
                    @if (! $isCompleted && ! $isRejected)
                        <form method="post" action="{{ route('webhooks.order.quick', ['order' => $order->number]) }}" class="rounded-xl border-2 border-brand/25 bg-brand-tint/30 p-5 space-y-4">
                            @csrf

                            @if ($needsPin)
                                <div>
                                    <label for="pin" class="label text-xs sm:text-sm font-bold text-ink">
                                        🔑 Admin Secret Key <span class="text-danger">*</span>
                                        <span class="block text-[11px] font-normal text-muted mt-0.5">Enter the Admin 1-Tap PIN saved in your Admin Panel → Store Settings.</span>
                                    </label>
                                    <input id="pin"
                                           name="pin"
                                           type="password"
                                           required
                                           autofocus
                                           class="input mt-1.5 text-center font-mono text-xl tracking-widest py-3"
                                           placeholder="••••">
                                </div>
                            @endif

                            <div>
                                <label for="credentials" class="label text-xs font-semibold text-ink">
                                    {{ $isNeedsAttention ? 'Paste Credentials to Deliver Manually (or leave blank to Retry Supplier)' : 'Optional: Paste Account Credentials / Activation Link' }}
                                    <span class="block text-[11px] font-normal text-muted mt-0.5">
                                        For multiple pcs, separate each unit with <code>---</code> on its own line. Leave blank to auto-deliver from .TXT stock or supplier API.
                                    </span>
                                </label>
                                <textarea id="credentials"
                                          name="credentials"
                                          rows="3"
                                          class="input mt-1.5 font-mono text-xs"
                                          placeholder="Email: user@email.com&#10;Pass: secret123&#10;---&#10;Email: user2@email.com">{{ old('credentials') }}</textarea>
                            </div>

                            @if ($isNeedsAttention)
                                <div class="grid grid-cols-1 gap-2.5">
                                    <button type="submit"
                                            name="do"
                                            value="retry"
                                            class="btn bg-[#16a34a] text-white hover:bg-[#15803d] py-3.5 text-xs sm:text-sm font-bold shadow-md">
                                        🔄 Deliver Pasted Credentials / Retry Supplier API
                                    </button>
                                    <button type="submit"
                                            name="do"
                                            value="whatsapp_delivered"
                                            class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] py-3.5 text-xs sm:text-sm font-bold shadow-md">
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
                            <pre class="mt-2 font-mono text-xs whitespace-pre-wrap break-all text-ink bg-white/70 p-3 rounded-lg border border-success/20">{{ implode("\n---\n", $deliveredLines) }}</pre>
                        </div>
                    @endif

                    {{-- Direct WhatsApp Reply + Open Admin Panel --}}
                    <div class="space-y-2.5 border-t border-line pt-4">
                        @if ($customerPhone !== '')
                            <a href="https://wa.me/{{ $customerPhone }}?text={{ urlencode($customerWaText) }}"
                                target="_blank"
                                rel="noopener"
                                class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] w-full py-3 text-xs sm:text-sm font-bold shadow-sm">
                                📲 Message Customer on WhatsApp ({{ $isCompleted ? 'Send Credentials' : 'Order Update' }}) →
                            </a>
                        @endif
                        <div class="grid grid-cols-2 gap-2.5">
                            <a href="{{ route('filament.admin.resources.orders.view', ['record' => $order->number]) }}" class="btn btn-dark py-2.5 text-xs font-bold text-center">
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
