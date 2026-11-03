@php
    use App\Enums\OrderStatus;
    $storeName = (string) setting('store_name', 'SellMeTools');
    $rate = max(1.0, (float) ($order->usdt_rate ?: \App\Models\Setting::usdtRate()));
    $totalUsd = number_format((float) $order->total / $rate, 2);
@endphp
<x-layouts.store :title="'Receipt '.$order->number" robots="noindex, nofollow">
    <div class="container-x max-w-3xl py-4 sm:py-10">
        {{-- Top Action Bar (Hidden when printing) --}}
        <div class="mb-4 sm:mb-5 flex flex-wrap items-center justify-between gap-2.5 print:hidden">
            <a href="{{ $order->customerUrl() }}" class="btn btn-outline py-2 px-3.5 text-xs sm:text-sm font-semibold">
                ← Back to Order
            </a>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" onclick="window.print()" class="btn btn-outline py-2 px-3.5 text-xs sm:text-sm font-bold">
                    🖨️ Print
                </button>
                <a href="{{ route('orders.receipt', ['order' => $order->number, 'token' => $token, 'download' => 'pdf']) }}"
                   class="btn btn-primary py-2 px-4 text-xs sm:text-sm font-bold shadow-sm">
                    📥 Download PDF
                </a>
            </div>
        </div>

        {{-- Official Printable Receipt Card --}}
        <section class="card overflow-hidden border-2 border-line bg-white shadow-lg print:border print:shadow-none">
            {{-- Dark Executive Header --}}
            <div class="surface-dark-solid px-4 py-5 sm:px-8 sm:py-6 text-white flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-widest text-[#4ade80]">
                        ✓ Official Payment &amp; Order Receipt
                    </span>
                    <h1 class="display mt-2 text-2xl sm:text-4xl text-white break-words">{{ $storeName }}</h1>
                    <p class="mt-0.5 text-xs text-white/70 break-words">
                        A Project by <a href="https://aivrasol.com" target="_blank" rel="noopener" class="font-semibold text-white underline">AivraSol Softwares</a> · https://sellmetools.com
                    </p>
                </div>

                <div class="min-w-0 sm:text-right sm:shrink-0">
                    <span class="block text-[10px] font-bold uppercase tracking-widest text-white/60">Receipt / Order #</span>
                    <span class="font-mono text-base sm:text-xl font-extrabold text-white break-all">{{ $order->number }}</span>
                    <div class="mt-1">
                        <span @class([
                            'badge text-xs',
                            'badge-success' => $order->status === OrderStatus::Completed,
                            'badge-brand' => $order->status !== OrderStatus::Completed,
                        ])>{{ $order->status->label() }}</span>
                    </div>
                </div>
            </div>

            <div class="p-4 sm:p-8 space-y-5 sm:space-y-6">
                {{-- Customer & Payment Metadata Grid (min-w-0 + break-all prevents mobile overflow) --}}
                <div class="grid gap-4 sm:grid-cols-2 rounded-xl border border-line bg-grey-soft/60 p-3.5 sm:p-4 text-xs sm:text-sm">
                    <div class="min-w-0 space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Billed To Customer</p>
                        <p class="font-bold text-ink break-words">{{ $order->customer_name ?: 'Valued Customer' }}</p>
                        <p class="text-body break-all">{{ $order->email }}</p>
                        @if ($order->phone)
                            <p class="font-mono text-xs text-muted break-all">WhatsApp: +{{ $order->phone }}</p>
                        @endif
                    </div>

                    <div class="min-w-0 space-y-1 border-t border-line pt-3 sm:border-t-0 sm:pt-0 sm:text-right">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Payment &amp; Verification</p>
                        <p class="font-bold text-ink break-words">{{ $order->paymentMethod?->name ?? 'Instant / Free' }}</p>
                        <p class="font-mono text-xs text-body break-all">TID: <strong>{{ $order->latestPayment?->transaction_id ?? 'N/A' }}</strong></p>
                        <p class="text-xs text-muted">Date: {{ $order->created_at?->format('d M Y, h:i A') }}</p>
                    </div>
                </div>

                {{-- Mobile Responsive Itemized List (< 640px) --}}
                <div class="sm:hidden space-y-3">
                    <p class="border-b-2 border-ink pb-2 text-[11px] font-bold uppercase tracking-wider text-muted">
                        Order Items
                    </p>
                    <div class="divide-y divide-line">
                        @foreach ($order->items as $item)
                            <div class="py-3 space-y-1.5">
                                <p class="font-bold text-ink text-xs leading-snug break-words">{{ $item->product_name }}</p>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-mono text-muted">{{ $item->quantity }} × {{ money($item->unit_price) }}</span>
                                    <span class="font-mono font-bold text-ink">{{ money($item->line_total) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="border-t-2 border-ink pt-3 flex items-baseline justify-between gap-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-muted">Total Paid</span>
                        <div class="text-right">
                            <span class="display text-2xl text-ink">{{ money($order->total) }}</span>
                            <span class="block text-[11px] font-semibold text-muted">≈ ${{ $totalUsd }} USDT</span>
                        </div>
                    </div>
                </div>

                {{-- Desktop / Tablet Itemized Table (>= 640px) --}}
                <div class="hidden sm:block">
                    <table class="w-full table-fixed text-left text-sm">
                        <thead class="border-b-2 border-ink text-[11px] font-bold uppercase tracking-wider text-muted">
                            <tr>
                                <th class="w-[50%] py-2.5 pr-3">Product / Subscription</th>
                                <th class="w-[10%] py-2.5 px-2 text-center">Qty</th>
                                <th class="w-[20%] py-2.5 px-2 text-right">Unit Price</th>
                                <th class="w-[20%] py-2.5 pl-2 text-right">Line Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($order->items as $item)
                                <tr>
                                    <td class="py-3.5 pr-3 font-bold text-ink break-words">{{ $item->product_name }}</td>
                                    <td class="py-3.5 px-2 text-center font-mono">{{ $item->quantity }}</td>
                                    <td class="py-3.5 px-2 text-right font-mono">{{ money($item->unit_price) }}</td>
                                    <td class="py-3.5 pl-2 text-right font-mono font-bold text-ink">{{ money($item->line_total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t-2 border-ink">
                            <tr>
                                <td colspan="3" class="pt-4 text-right text-xs font-bold uppercase tracking-wider text-muted">Total Amount Paid</td>
                                <td class="pt-4 pl-2 text-right">
                                    <span class="display text-2xl sm:text-3xl text-ink">{{ money($order->total) }}</span>
                                    <span class="block text-[11px] font-semibold text-muted">≈ ${{ $totalUsd }} USDT</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if (filled($order->customer_input))
                    <div class="min-w-0 rounded-xl border border-line bg-grey-soft p-3.5 sm:p-4">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Submitted Activation Details</p>
                        <p class="mt-1 font-mono text-xs sm:text-sm font-semibold text-ink break-all whitespace-pre-wrap">{{ $order->customer_input }}</p>
                    </div>
                @endif

                {{-- Delivered Credentials Summary --}}
                @php
                    $hasDeliveries = $order->items->some(fn ($i) => $i->deliveries->isNotEmpty());
                @endphp
                @if ($hasDeliveries)
                    <div class="min-w-0 rounded-xl bg-ink p-3.5 sm:p-5 text-white space-y-3 overflow-hidden">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-[#4ade80]">🔑 Delivered Access Credentials</p>
                        @foreach ($order->items as $item)
                            @foreach ($item->deliveries as $del)
                                @php $credText = trim((string) $del->content); @endphp
                                <div class="min-w-0 rounded-lg bg-white/10 p-3 space-y-2" x-data="copy(@js($credText))">
                                    <div class="flex items-center justify-between gap-2 border-b border-white/10 pb-1.5 print:hidden">
                                        <span class="text-[10px] font-semibold text-white/70 truncate">{{ $item->product_name }}</span>
                                        <button type="button" @click="copy" class="shrink-0 rounded bg-white/15 px-2.5 py-0.5 text-[10px] font-bold text-white hover:bg-white/25" x-text="copied ? '✓ Copied!' : '📋 Copy'">📋 Copy</button>
                                    </div>
                                    <pre class="max-w-full overflow-x-auto font-mono text-xs sm:text-sm text-[#4ade80] whitespace-pre-wrap break-all leading-relaxed">{{ $credText }}</pre>
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                @endif

                <div class="border-t border-line pt-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between text-[11px] text-muted">
                    <p>Thank you for purchasing from <strong>{{ $storeName }}</strong>!</p>
                    <p>Built by <a href="https://aivrasol.com" target="_blank" rel="noopener" class="font-semibold text-ink hover:underline">AivraSol Softwares</a></p>
                </div>
            </div>
        </section>
    </div>
</x-layouts.store>

