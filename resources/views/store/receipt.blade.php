@php
    use App\Enums\OrderStatus;
    $storeName = (string) setting('store_name', 'SellMeTools');
    $rate = max(1.0, (float) ($order->usdt_rate ?: \App\Models\Setting::usdtRate()));
    $totalUsd = number_format((float) $order->total / $rate, 2);
    $isPaid = in_array($order->status, [OrderStatus::Processing, OrderStatus::Completed, OrderStatus::NeedsAttention], true)
        || $order->paid_at !== null;
    $docTitle = $isPaid ? 'Payment Receipt' : 'Order Invoice';
    $paymentStatusLabel = match (true) {
        $isPaid => 'Paid',
        $order->status === OrderStatus::PaymentReview => 'Verification Pending',
        $order->status === OrderStatus::PendingPayment => 'Unpaid · Awaiting Payment',
        default => $order->status->label(),
    };
@endphp
<x-layouts.store :title="$docTitle.' '.$order->number" robots="noindex, nofollow">
    <div class="container-x max-w-3xl py-6 sm:py-10">
        {{-- Top Action Bar (Hidden when printing) --}}
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 print:hidden">
            <a href="{{ $order->customerUrl() }}" class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-semibold text-muted hover:text-ink transition">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                <span>Back to Order</span>
            </a>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" onclick="window.print()" class="btn btn-outline py-2 px-3.5 text-xs font-semibold inline-flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    <span>Print</span>
                </button>
                <a href="{{ route('orders.receipt', ['order' => $order->number, 'token' => $token, 'download' => 'pdf']) }}"
                   class="btn btn-primary py-2 px-4 text-xs font-semibold inline-flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                    <span>Download PDF</span>
                </a>
            </div>
        </div>

        {{-- Executive Clean Paper Invoice / Receipt --}}
        <section class="overflow-hidden rounded-2xl border border-line bg-white shadow-sm print:rounded-none print:border-0 print:shadow-none">
            {{-- Subtle Brand Accent Line --}}
            <div class="h-1.5 w-full {{ $isPaid ? 'bg-[#16a34a]' : 'bg-ink' }}"></div>

            <div class="p-6 sm:p-10 space-y-8">
                {{-- Header: Store Logo on Left, Invoice/Receipt Meta on Right --}}
                <div class="flex flex-col gap-6 border-b border-line pb-7 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 space-y-2">
                        <img src="{{ asset('images/logo.png') }}?v=6"
                             alt="{{ $storeName }}"
                             width="180"
                             height="40"
                             class="h-9 sm:h-10 w-auto object-contain">
                        <p class="text-xs text-muted">
                            A Project by <span class="font-semibold text-ink">AivraSol Softwares</span> · sellmetools.com
                        </p>
                    </div>

                    <div class="min-w-0 sm:text-right">
                        <p class="text-[11px] font-bold uppercase tracking-widest text-muted">{{ $docTitle }}</p>
                        <p class="mt-0.5 font-mono text-lg sm:text-xl font-bold text-ink break-all">{{ $order->number }}</p>
                        <div class="mt-2 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold {{ $isPaid ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : ($order->status === OrderStatus::PaymentReview ? 'bg-amber-50 text-amber-800 border border-amber-200' : 'bg-slate-100 text-slate-700 border border-slate-200') }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $isPaid ? 'bg-emerald-600' : ($order->status === OrderStatus::PaymentReview ? 'bg-amber-500' : 'bg-slate-500') }}"></span>
                            <span>{{ $paymentStatusLabel }}</span>
                        </div>
                    </div>
                </div>

                {{-- Customer & Payment Metadata Grid --}}
                <div class="grid gap-6 sm:grid-cols-2 text-xs sm:text-sm">
                    <div class="min-w-0 space-y-1">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">Billed To</p>
                        <p class="font-semibold text-ink break-words">{{ $order->customer_name ?: 'Valued Customer' }}</p>
                        <p class="text-body break-all">{{ $order->email }}</p>
                        @if ($order->phone)
                            <p class="font-mono text-xs text-muted break-all">+{{ ltrim($order->phone, '+') }}</p>
                        @endif
                    </div>

                    <div class="min-w-0 space-y-1.5 sm:text-right">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">Payment Summary</p>
                        <div class="space-y-1 text-xs">
                            <p class="text-body">
                                <span class="text-muted">Method:</span>
                                <strong class="text-ink">{{ $order->paymentMethod?->name ?? 'Instant / Free' }}</strong>
                            </p>
                            <p class="text-body break-all">
                                <span class="text-muted">Transaction ID:</span>
                                <strong class="font-mono text-ink">{{ $order->latestPayment?->transaction_id ?: 'Not submitted' }}</strong>
                            </p>
                            <p class="text-body">
                                <span class="text-muted">Issued Date:</span>
                                <span class="text-ink">{{ $order->created_at?->format('d M Y, h:i A') }}</span>
                            </p>
                            @if ($isPaid && ($order->paid_at || $order->completed_at))
                                <p class="text-body">
                                    <span class="text-muted">Paid Date:</span>
                                    <span class="text-emerald-700 font-medium">{{ ($order->paid_at ?? $order->completed_at)?->format('d M Y, h:i A') }}</span>
                                </p>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Mobile Responsive Itemized List (< 640px) --}}
                <div class="sm:hidden space-y-3">
                    <p class="border-b border-line-strong pb-2 text-[11px] font-semibold uppercase tracking-wider text-muted">
                        Description
                    </p>
                    <div class="divide-y divide-line">
                        @foreach ($order->items as $item)
                            <div class="py-3 space-y-1">
                                <p class="font-semibold text-ink text-xs leading-snug break-words">{{ $item->product_name }}</p>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-mono text-muted">{{ $item->quantity }} × {{ money($item->unit_price) }}</span>
                                    <span class="font-mono font-semibold text-ink">{{ money($item->line_total) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="border-t-2 border-ink pt-3.5 flex items-baseline justify-between gap-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-ink">{{ $isPaid ? 'Total Paid' : 'Amount Due' }}</span>
                        <div class="text-right">
                            <span class="font-mono text-xl font-bold text-ink">{{ money($order->total) }}</span>
                            <span class="block text-[11px] text-muted">≈ ${{ $totalUsd }} USDT</span>
                        </div>
                    </div>
                </div>

                {{-- Desktop / Tablet Itemized Table (>= 640px) --}}
                <div class="hidden sm:block">
                    <table class="w-full table-fixed text-left text-sm">
                        <thead class="border-y border-line bg-grey-soft/60 text-[11px] font-semibold uppercase tracking-wider text-muted">
                            <tr>
                                <th class="w-[52%] py-3 pl-3 pr-3">Description</th>
                                <th class="w-[10%] py-3 px-2 text-center">Qty</th>
                                <th class="w-[19%] py-3 px-2 text-right">Unit Price</th>
                                <th class="w-[19%] py-3 pl-2 pr-3 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($order->items as $item)
                                <tr>
                                    <td class="py-4 pl-3 pr-3 font-medium text-ink break-words">{{ $item->product_name }}</td>
                                    <td class="py-4 px-2 text-center font-mono text-body">{{ $item->quantity }}</td>
                                    <td class="py-4 px-2 text-right font-mono text-body">{{ money($item->unit_price) }}</td>
                                    <td class="py-4 pl-2 pr-3 text-right font-mono font-semibold text-ink">{{ money($item->line_total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t-2 border-ink">
                            <tr>
                                <td colspan="3" class="pt-4 text-right text-xs font-bold uppercase tracking-wider text-muted">
                                    {{ $isPaid ? 'Total Paid' : 'Amount Due' }}
                                </td>
                                <td class="pt-4 pl-2 pr-3 text-right">
                                    <span class="font-mono text-xl font-bold {{ $isPaid ? 'text-ink' : 'text-brand' }}">{{ money($order->total) }}</span>
                                    <span class="block text-[11px] text-muted">≈ ${{ $totalUsd }} USDT</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if (filled($order->customer_input))
                    <div class="min-w-0 rounded-xl border border-line bg-grey-soft/60 p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">Customer Activation Details</p>
                        <p class="mt-1 font-mono text-xs sm:text-sm text-ink break-all whitespace-pre-wrap">{{ $order->customer_input }}</p>
                    </div>
                @endif

                {{-- Delivered Credentials Summary (Clean Slate Card, Zero Emojis) --}}
                @php
                    $hasDeliveries = $order->items->some(fn ($i) => $i->deliveries->isNotEmpty());
                @endphp
                @if ($hasDeliveries)
                    <div class="min-w-0 rounded-xl border border-line bg-grey-soft/50 p-4 sm:p-5 space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-ink">Delivered Access Credentials</p>
                            <span class="text-[11px] font-medium text-emerald-700">Fulfilled</span>
                        </div>
                        @foreach ($order->items as $item)
                            @foreach ($item->deliveries as $del)
                                @php $credText = trim((string) $del->content); @endphp
                                <div class="min-w-0 rounded-lg border border-line bg-white p-3.5 space-y-2" x-data="copy(@js($credText))">
                                    <div class="flex items-center justify-between gap-2 border-b border-line pb-2 print:hidden">
                                        <span class="text-xs font-medium text-muted truncate">{{ $item->product_name }}</span>
                                        <button type="button" @click="copy" class="shrink-0 inline-flex items-center gap-1 rounded-md border border-line bg-grey-soft px-2.5 py-1 text-[11px] font-semibold text-ink hover:bg-line/50">
                                            <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                        </button>
                                    </div>
                                    <pre class="max-w-full overflow-x-auto font-mono text-xs sm:text-sm text-ink whitespace-pre-wrap break-all leading-relaxed">{{ $credText }}</pre>
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                @endif

                {{-- Clean Footer --}}
                <div class="border-t border-line pt-5 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between text-xs text-muted">
                    <p>Thank you for choosing <strong class="text-ink">{{ $storeName }}</strong>.</p>
                    <p>Issued by <a href="https://aivrasol.com" target="_blank" rel="noopener" class="font-medium text-ink hover:underline">AivraSol Softwares</a> · sellmetools.com</p>
                </div>
            </div>
        </section>
    </div>
</x-layouts.store>
