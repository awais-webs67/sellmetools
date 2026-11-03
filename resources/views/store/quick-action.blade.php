@php
    use App\Enums\OrderStatus;
    $isCompleted = $order->status === OrderStatus::Completed;
    $isNeedsAttention = $order->status === OrderStatus::NeedsAttention;
    $isAlreadyCompleted = $actionResult === 'already_completed';
    $isRejected = $payment?->status === \App\Enums\PaymentStatus::Rejected || $order->status === OrderStatus::Cancelled;
    $customerPhone = format_whatsapp($order->phone ?: $payment?->sender_number);

    $primaryDeliveryType = $order->items->first()?->product?->delivery_type ?: 'auto';
    $isManualProduct = in_array($primaryDeliveryType, ['manual_admin', 'whatsapp_handover'], true);
    $openManualByDefault = $isNeedsAttention || $isManualProduct;

    $deliveredLines = $order->items->flatMap(fn ($item) => $item->deliveries->pluck('content'))->all();
    $itemsSummary = $order->items->map(fn ($i) => "{$i->product_name} x{$i->quantity}")->implode(', ');

    if ($isCompleted) {
        $customerWaText = "Assalam o Alaikum! ✅ Your Order *{$order->number}* ({$itemsSummary}) is confirmed & delivered!\n\n"
            .($deliveredLines !== [] ? "🔑 *Credentials:*\n".implode("\n---\n", $deliveredLines)."\n\n" : '')
            ."🔗 *Order Page:* ".$order->customerUrl();
    } elseif ($isRejected) {
        $customerWaText = "Assalam o Alaikum! ❌ Your Transaction ID (".($payment?->transaction_id ?? 'N/A').") for Order *{$order->number}* could not be verified.\n\n"
            ."Submit correct TID here:\n".$order->customerUrl();
    } else {
        $customerWaText = "Assalam o Alaikum! ✅ Your payment for Order *{$order->number}* ({$itemsSummary}) has been verified!\n".$order->customerUrl();
    }
@endphp
<x-layouts.store :title="'1-Tap Control: '.$order->number" robots="noindex, nofollow">
    <div class="container-x py-6 sm:py-10">
        <div class="mx-auto max-w-md">
            <section class="card overflow-hidden shadow-xl">
                {{-- Sleek Status Header --}}
                <div @class([
                    'px-5 py-5 text-white',
                    'bg-[#0F291E]' => ! $isAlreadyCompleted && ! $isNeedsAttention && ($isCompleted || $actionResult === 'approve'),
                    'bg-danger' => ! $isAlreadyCompleted && ($isRejected || $actionResult === 'reject'),
                    'bg-ink' => $isAlreadyCompleted || $isNeedsAttention || $actionResult === 'needs_attention' || (! $isCompleted && ! $isRejected && ! in_array($actionResult, ['approve', 'reject'], true)),
                ])>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="rounded-md bg-white/15 px-2.5 py-0.5 font-mono text-xs font-bold text-white">
                            #{{ $order->number }}
                        </span>
                        <span class="rounded-full bg-white/15 px-2.5 py-0.5 text-[11px] font-bold text-white">
                            {{ $order->status->label() }}
                        </span>
                    </div>

                    <div class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-2.5 py-1 text-xs font-bold text-white">
                        <span>🏭 Source:</span>
                        <span class="font-mono text-[#4ade80]">{{ $order->adminSupplierSummary(true) }}</span>
                    </div>

                    <h1 class="display mt-2.5 text-2xl sm:text-3xl text-white">
                        @if ($isAlreadyCompleted)
                            Already Confirmed &amp; Delivered!
                        @elseif ($isCompleted)
                            Order Approved &amp; Delivered!
                        @elseif ($isNeedsAttention)
                            Payment Confirmed: ⚠️ Delivery On Hold
                        @elseif ($isRejected)
                            Order Declined
                        @else
                            1-Tap Order Control
                        @endif
                    </h1>
                </div>

                <div class="p-5 space-y-4">
                    @if ($isAlreadyCompleted)
                        <div class="rounded-xl border border-line-strong bg-grey-soft p-3.5 text-xs text-ink">
                            <p class="font-bold">🛑 Duplicate Approval Prevented</p>
                            <p class="mt-0.5 text-muted">Already delivered {{ $order->completed_at ? $order->completed_at->diffForHumans() : 'earlier' }}.</p>
                        </div>
                    @endif

                    @if ($isNeedsAttention)
                        <div class="rounded-xl border border-danger/30 bg-danger-tint p-3.5 text-xs text-danger">
                            <p class="font-bold">{{ $order->admin_note ?: 'No stock or supplier balance available.' }}</p>
                        </div>
                    @endif

                    {{-- Compact Scannable Key-Value Grid --}}
                    <div class="grid grid-cols-2 gap-2.5 rounded-xl border border-line bg-grey-soft/60 p-3.5 text-xs">
                        <div class="col-span-2 flex items-center justify-between border-b border-line pb-2.5">
                            <span class="text-muted">Product</span>
                            <span class="font-bold text-ink text-right">{{ $itemsSummary }}</span>
                        </div>
                        <div class="col-span-2 flex items-center justify-between border-b border-line pb-2.5">
                            <span class="text-muted">🏭 Supplier / Source</span>
                            <span class="rounded bg-ink px-2 py-0.5 font-mono text-[11px] font-bold text-white text-right">{{ $order->adminSupplierSummary(true) }}</span>
                        </div>
                        <div>
                            <span class="block text-[10px] uppercase text-muted">Sale Amount</span>
                            <span class="display text-xl text-ink">{{ money($order->total) }}</span>
                        </div>
                        <div class="text-right">
                            <span class="block text-[10px] uppercase text-muted">{{ $order->paymentMethod?->name ?? 'Gateway' }} TID</span>
                            <span class="font-mono text-xs font-bold text-ink break-all">{{ $payment?->transaction_id ?? 'N/A' }}</span>
                        </div>
                        @if ($isCompleted && $order->total_cost !== null)
                            <div class="col-span-2 flex items-center justify-between border-t border-line pt-2.5 text-[11px]">
                                <span class="text-muted">Cost: <strong class="font-mono text-ink">{{ money($order->total_cost) }}</strong></span>
                                <span class="text-muted">Net Profit: <strong class="font-mono text-emerald-700">{{ money((float) $order->total - (float) $order->total_cost) }}</strong></span>
                            </div>
                        @endif
                        <div class="col-span-2 flex items-center justify-between border-t border-line pt-2.5 text-[11px]">
                            <span class="text-muted truncate">{{ $order->email }}</span>
                            <span class="font-mono text-ink shrink-0">{{ $order->phone ?: $payment?->sender_number }}</span>
                        </div>
                        @if (filled($order->customer_input))
                            <div class="col-span-2 rounded-lg bg-ink p-2.5 text-white border-t border-line mt-1" x-data="copy(@js((string) $order->customer_input))">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-[#ff8fa3]">📝 Customer Activation Data</span>
                                    <button type="button" @click="copy" class="rounded bg-white/15 px-2 py-0.5 text-[10px] font-bold text-white hover:bg-white/25" x-text="copied ? '✓ Copied' : 'Copy'">Copy</button>
                                </div>
                                <p class="mt-1 font-mono text-xs font-bold text-[#4ade80] break-all whitespace-pre-wrap">{{ $order->customer_input }}</p>
                            </div>
                        @endif
                    </div>

                    {{-- 1-Tap Action Controls --}}
                    @if (! $isCompleted && ! $isRejected)
                        <form method="post"
                              action="{{ route('webhooks.order.quick', ['order' => $order->number]) }}"
                              class="space-y-3.5"
                              x-data="{ manualMode: {{ $openManualByDefault ? 'true' : 'false' }} }">
                            @csrf

                            @if ($needsPin)
                                <div>
                                    <label for="pin" class="label text-xs font-bold text-ink">🔑 Admin Secret Key</label>
                                    <input id="pin"
                                           name="pin"
                                           type="password"
                                           required
                                           autofocus
                                           class="input text-center font-mono text-lg tracking-widest py-2.5"
                                           placeholder="••••">
                                </div>
                            @endif

                            {{-- Primary Auto-Approve / Decline Buttons (when not on NeedsAttention) --}}
                            @if (! $isNeedsAttention)
                                <div x-show="!manualMode" class="space-y-2.5">
                                    <div class="grid grid-cols-2 gap-2.5">
                                        <button type="submit" name="do" value="approve" class="btn bg-success text-white hover:opacity-95 py-3 text-sm font-bold">
                                            ✅ Approve
                                        </button>
                                        <button type="submit" name="do" value="reject" class="btn bg-danger text-white hover:opacity-95 py-3 text-sm font-bold">
                                            ❌ Decline
                                        </button>
                                    </div>
                                    <button type="button"
                                            @click="manualMode = true"
                                            class="w-full rounded-lg border border-line bg-grey-soft py-2 px-3 text-[11px] font-semibold text-muted hover:text-ink transition">
                                        Deliver Manually Instead (Bypass Supplier API) ↓
                                    </button>
                                </div>
                            @endif

                            {{-- Manual Delivery / On-Hold Panel --}}
                            <div x-show="manualMode" x-cloak class="rounded-xl border border-line-strong bg-grey-soft/60 p-3.5 space-y-3">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-bold text-ink">
                                        {{ $isNeedsAttention ? 'Deliver / Retry Order' : 'Manual Delivery (Skips Supplier API)' }}
                                    </span>
                                    @if (! $openManualByDefault)
                                        <button type="button" @click="manualMode = false; $refs.credsInput.value = ''" class="text-[11px] font-semibold text-muted hover:text-ink underline">
                                            ← Back to Auto Approve
                                        </button>
                                    @endif
                                </div>

                                <div>
                                    <label for="credentials" class="label text-xs font-semibold text-muted">
                                        {{ $isNeedsAttention ? 'Paste Credentials (or leave blank to retry Supplier API)' : 'Paste Credentials to Deliver Manually' }}
                                    </label>
                                    <textarea id="credentials"
                                              x-ref="credsInput"
                                              name="credentials"
                                              rows="2"
                                              class="input font-mono text-xs bg-white"
                                              placeholder="email:password (or activation link)">{{ old('credentials') }}</textarea>
                                </div>

                                <div>
                                    <label for="manual_cost" class="label text-xs font-semibold text-muted">
                                        Manual Cost in PKR (for Profit Calculation)
                                    </label>
                                    <input id="manual_cost"
                                           name="manual_cost"
                                           type="number"
                                           step="0.01"
                                           min="0"
                                           value="{{ old('manual_cost', '0') }}"
                                           class="input font-mono text-xs py-2 bg-white"
                                           placeholder="0 (Leave 0 for own free stock)">
                                    <p class="mt-1 text-[10px] text-muted">
                                        When delivered manually, order source becomes <strong>Admin Manual</strong> (not Supplier) and uses this cost.
                                    </p>
                                </div>

                                @if ($isNeedsAttention)
                                    <div class="grid grid-cols-1 gap-2">
                                        <button type="submit" name="do" value="retry" class="btn bg-success text-white hover:opacity-95 py-3 text-xs sm:text-sm font-bold">
                                            🔄 Deliver / Retry Supplier
                                        </button>
                                        <button type="submit" name="do" value="whatsapp_delivered" class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] py-3 text-xs sm:text-sm font-bold">
                                            📲 Mark Delivered on WhatsApp
                                        </button>
                                    </div>
                                @else
                                    <div class="grid grid-cols-1 gap-2">
                                        <button type="submit" name="do" value="manual_deliver" class="btn bg-ink text-white hover:opacity-95 py-2.5 text-xs sm:text-sm font-bold">
                                            Deliver Manually &amp; Skip Supplier →
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </form>
                    @endif

                    @if ($deliveredLines !== [])
                        <div class="rounded-xl border border-line bg-grey-soft/60 p-3.5">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Delivered Credentials</p>
                            <pre class="mt-1.5 font-mono text-xs whitespace-pre-wrap break-all text-ink">{{ implode("\n---\n", $deliveredLines) }}</pre>
                        </div>
                    @endif

                    {{-- Compact Footer Buttons --}}
                    <div class="space-y-2 border-t border-line pt-3.5">
                        @if ($customerPhone !== '')
                            <a href="https://wa.me/{{ $customerPhone }}?text={{ urlencode($customerWaText) }}"
                               target="_blank"
                               rel="noopener"
                               class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] w-full py-2.5 text-xs font-bold">
                                WhatsApp Customer →
                            </a>
                        @endif
                        <div class="grid grid-cols-2 gap-2">
                            <a href="{{ route('filament.admin.resources.orders.view', ['record' => $order->number]) }}" class="btn btn-dark py-2 text-xs font-semibold">
                                Admin Panel
                            </a>
                            <a href="{{ $order->customerUrl() }}" class="btn btn-outline py-2 text-xs font-semibold">
                                Customer View
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-layouts.store>
