@php
    use App\Enums\OrderStatus;
    $isCompleted = $order->status === OrderStatus::Completed;
    $isAlreadyCompleted = $actionResult === 'already_completed';
    $isRejected = $payment?->status === \App\Enums\PaymentStatus::Rejected || $order->status === OrderStatus::Cancelled;
    $customerPhone = format_whatsapp($order->phone ?: $payment?->sender_number);

    $deliveredLines = $order->items->flatMap(fn ($item) => $item->deliveries->pluck('content'))->all();
    $itemsSummary = $order->items->map(fn ($i) => "{$i->product_name} x{$i->quantity}")->implode(', ');

    if ($isCompleted) {
        $customerWaText = "Assalam o Alaikum! ✅ Your payment for Order *{$order->number}* ({$itemsSummary}) has been verified and your order is delivered!\n\n"
            .($deliveredLines !== [] ? "🔑 *Your Credentials / Activation:*\n".implode("\n", $deliveredLines)."\n\n" : '')
            ."🔗 *View Order Page:* ".$order->customerUrl()."\n\n"
            ."Thank you for shopping with ".setting('store_name')."!";
    } elseif ($isRejected) {
        $customerWaText = "Assalam o Alaikum! ❌ Regarding your Order *{$order->number}* ({$itemsSummary}): your Transaction ID (".($payment?->transaction_id ?? 'N/A').") could not be verified.\n\n"
            ."Please reply with your payment screenshot or submit the correct TID here:\n".$order->customerUrl();
    } else {
        $customerWaText = "Assalam o Alaikum! Regarding your Order *{$order->number}* on ".setting('store_name').":\n".$order->customerUrl();
    }
@endphp
<x-layouts.store :title="'1-Tap Order Action – '.$order->number" robots="noindex, nofollow">
    <div class="container-x py-8 sm:py-12">
        <div class="mx-auto max-w-lg">
            @if ($needsPin)
                <section class="card overflow-hidden border-2 border-brand/30">
                    <div class="bg-ink px-6 py-4 text-white">
                        <span class="text-xs font-bold tracking-wider text-[#ff8fa3] uppercase">Admin 1-Tap Security</span>
                        <h1 class="display mt-1 text-3xl text-white">Unlock 1-Tap WhatsApp Approval</h1>
                    </div>
                    <form method="post" action="{{ route('webhooks.order.quick', ['order' => $order->number, 'do' => $requestedAction]) }}" class="p-6 space-y-4">
                        @csrf
                        <input type="hidden" name="do" value="{{ $requestedAction }}">
                        <p class="text-xs sm:text-sm text-body">
                            Enter your <strong>Admin 1-Tap PIN</strong> once. This phone will be remembered for <strong>1 year</strong> so future WhatsApp taps unlock immediately!
                        </p>
                        <div>
                            <label for="pin" class="label">Admin 1-Tap PIN</label>
                            <input id="pin" name="pin" type="password" inputmode="numeric" required autofocus class="input text-center font-mono text-xl tracking-widest" placeholder="••••">
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-full">
                            Unlock &amp; {{ $requestedAction === 'reject' ? 'Decline Order' : 'Approve & Deliver Order' }} →
                        </button>
                    </form>
                </section>
            @else
                <section class="card overflow-hidden">
                    <div @class([
                        'px-6 py-5 text-white',
                        'bg-[#b45309]' => $isAlreadyCompleted || $order->status === OrderStatus::NeedsAttention,
                        'bg-[#15803d]' => ! $isAlreadyCompleted && ($isCompleted || $actionResult === 'approve') && $order->status !== OrderStatus::NeedsAttention,
                        'bg-[#b91c1c]' => ! $isAlreadyCompleted && ($isRejected || $actionResult === 'reject'),
                        'bg-ink' => ! $isAlreadyCompleted && ! $isCompleted && ! $isRejected && ! in_array($actionResult, ['approve', 'reject'], true),
                    ])>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold tracking-wider uppercase opacity-85">Admin 1-Tap Order Control</span>
                            <span class="badge bg-white/20 text-white text-xs">{{ $order->status->label() }}</span>
                        </div>
                        <h1 class="display mt-1.5 text-3xl sm:text-4xl text-white">
                            @if ($isAlreadyCompleted)
                                ⚠️ Already Confirmed &amp; Delivered!
                            @elseif ($isCompleted)
                                ✅ Approved &amp; Delivered!
                            @elseif ($order->status === OrderStatus::NeedsAttention)
                                ⏸️ Paused — Needs Manual Delivery
                            @elseif ($isRejected)
                                ❌ Payment Declined
                            @else
                                Order {{ $order->number }}
                            @endif
                        </h1>
                        @if ($message)
                            <p class="mt-1 text-xs sm:text-sm text-white/95 font-medium">{{ $message }}</p>
                        @endif
                    </div>

                    <div class="p-5 sm:p-6 space-y-5">
                        @if ($isAlreadyCompleted)
                            <div class="rounded-xl border-2 border-amber-500/40 bg-amber-500/10 p-4 text-xs sm:text-sm text-ink">
                                <p class="font-bold text-amber-900">🛑 Duplicate Approval Prevented</p>
                                <p class="mt-1 text-body">
                                    This order was <strong>already verified and delivered</strong> ({{ $order->completed_at ? $order->completed_at->format('d M Y, h:i A') : 'automatically' }}). The customer already has their credentials on the order page.
                                </p>
                            </div>
                        @endif

                        {{-- Prominent POST Confirmation Buttons (Safe from WhatsApp/Telegram Link Preview Crawlers!) --}}
                        @if (! $isCompleted && (! $payment || $payment->status === \App\Enums\PaymentStatus::Pending))
                            <div class="rounded-xl border-2 border-brand/30 bg-brand-tint/40 p-4 space-y-3">
                                <p class="text-xs font-bold uppercase tracking-wider text-brand-text text-center">
                                    Tap Below to Confirm Action
                                </p>
                                @if (! empty($signedFormUrl))
                                    <form method="post" action="{{ $signedFormUrl }}">
                                        @csrf
                                        <button type="submit"
                                                class="btn w-full py-3.5 text-sm sm:text-base font-bold text-white {{ ($requestedAction ?? 'approve') === 'reject' ? 'bg-[#dc2626] hover:bg-[#b91c1c]' : 'bg-[#16a34a] hover:bg-[#15803d]' }}">
                                            {{ ($requestedAction ?? 'approve') === 'reject' ? '❌ Confirm Decline Payment' : '✅ Confirm Approve & Auto-Deliver' }}
                                        </button>
                                    </form>
                                @else
                                    <div class="grid grid-cols-2 gap-3">
                                        <form method="post" action="{{ route('webhooks.order.quick', ['order' => $order->number, 'do' => 'approve']) }}">
                                            @csrf
                                            <input type="hidden" name="do" value="approve">
                                            <button type="submit" class="btn bg-[#16a34a] text-white hover:bg-[#15803d] w-full py-3.5 text-center font-bold">
                                                ✅ Approve &amp; Deliver
                                            </button>
                                        </form>
                                        <form method="post" action="{{ route('webhooks.order.quick', ['order' => $order->number, 'do' => 'reject']) }}">
                                            @csrf
                                            <input type="hidden" name="do" value="reject">
                                            <button type="submit" class="btn bg-[#dc2626] text-white hover:bg-[#b91c1c] w-full py-3.5 text-center font-bold">
                                                ❌ Decline Payment
                                            </button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <dl class="divide-y divide-line rounded-xl border border-line text-sm">
                            <div class="flex justify-between gap-4 p-3.5">
                                <dt class="text-muted">Order Number</dt>
                                <dd class="font-mono font-bold text-ink">{{ $order->number }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 p-3.5">
                                <dt class="text-muted">Product</dt>
                                <dd class="font-semibold text-ink text-right">{{ $itemsSummary }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 p-3.5">
                                <dt class="text-muted">Amount</dt>
                                <dd class="font-bold text-ink">{{ money($order->total) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 p-3.5">
                                <dt class="text-muted">Payment Method</dt>
                                <dd class="font-semibold text-ink">{{ $order->paymentMethod?->name ?? 'Manual' }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 p-3.5">
                                <dt class="text-muted">Transaction ID (TID)</dt>
                                <dd class="font-mono font-bold text-brand">{{ $payment?->transaction_id ?? 'Not submitted on form' }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 p-3.5">
                                <dt class="text-muted">Customer</dt>
                                <dd class="text-right text-xs text-ink">
                                    <div>{{ $order->email }}</div>
                                    @if ($order->phone || $payment?->sender_number)
                                        <div class="font-mono text-muted">{{ $order->phone ?: $payment?->sender_number }}</div>
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        @if ($deliveredLines !== [])
                            <div class="rounded-xl border border-success/30 bg-success-tint p-4">
                                <p class="text-xs font-bold uppercase tracking-wider text-success">Delivered Credentials</p>
                                <pre class="mt-2 font-mono text-xs whitespace-pre-wrap break-all text-ink">{{ implode("\n", $deliveredLines) }}</pre>
                            </div>
                        @endif

                        {{-- 1-Tap Reply to Customer on WhatsApp --}}
                        <div class="space-y-2.5 border-t border-line pt-4">
                            @if ($customerPhone !== '')
                                <a href="https://wa.me/{{ $customerPhone }}?text={{ urlencode($customerWaText) }}"
                                   target="_blank"
                                   rel="noopener"
                                   class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] w-full py-3 font-bold">
                                    📲 Send {{ $isCompleted ? 'Delivered Credentials' : 'Status Update' }} to Customer on WhatsApp →
                                </a>
                            @endif
                            <div class="grid grid-cols-2 gap-2.5">
                                <a href="{{ $order->customerUrl() }}" class="btn btn-outline text-xs text-center">View Customer Order Page</a>
                                <a href="{{ url('/admin/orders/'.$order->id) }}" class="btn btn-outline text-xs text-center">Open in Admin Panel</a>
                            </div>
                        </div>
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-layouts.store>
