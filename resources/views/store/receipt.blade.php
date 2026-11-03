@php
    use App\Enums\OrderStatus;
    $storeName = (string) setting('store_name', 'SellMeTools');
    $rate = max(1.0, (float) ($order->usdt_rate ?: \App\Models\Setting::usdtRate()));
    $totalUsd = number_format((float) $order->total / $rate, 2);
@endphp
<x-layouts.store :title="'Receipt '.$order->number" robots="noindex, nofollow">
    <div class="container-x max-w-3xl py-6 sm:py-10">
        {{-- Top Action Bar (Hidden when printing) --}}
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 print:hidden">
            <a href="{{ $order->customerUrl() }}" class="btn btn-outline py-2 px-4 text-xs sm:text-sm font-semibold">
                ← Back to Order Page
            </a>
            <div class="flex flex-wrap items-center gap-2.5">
                <button type="button" onclick="window.print()" class="btn btn-outline py-2 px-4 text-xs sm:text-sm font-bold">
                    🖨️ Print Receipt
                </button>
                <a href="{{ route('orders.receipt', ['order' => $order->number, 'token' => $token, 'download' => 'pdf']) }}"
                   class="btn btn-primary py-2 px-5 text-xs sm:text-sm font-bold shadow-sm">
                    📥 Download PDF Receipt
                </a>
            </div>
        </div>

        {{-- Official Printable Receipt Card --}}
        <section class="card overflow-hidden border-2 border-line bg-white shadow-lg print:border print:shadow-none">
            {{-- Dark Executive Header --}}
            <div class="surface-dark-solid px-6 py-6 sm:px-8 text-white flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-widest text-[#4ade80]">
                        ✓ Official Payment &amp; Order Receipt
                    </span>
                    <h1 class="display mt-2 text-3xl sm:text-4xl text-white">{{ $storeName }}</h1>
                    <p class="mt-0.5 text-xs text-white/70">
                        A Project by <a href="https://aivrasol.com" target="_blank" rel="noopener" class="font-semibold text-white underline">AivraSol Softwares</a> · {{ url('/') }}
                    </p>
                </div>

                <div class="sm:text-right">
                    <span class="block text-[10px] font-bold uppercase tracking-widest text-white/60">Receipt / Order #</span>
                    <span class="font-mono text-lg sm:text-xl font-extrabold text-white">{{ $order->number }}</span>
                    <div class="mt-1">
                        <span @class([
                            'badge text-xs',
                            'badge-success' => $order->status === OrderStatus::Completed,
                            'badge-brand' => $order->status !== OrderStatus::Completed,
                        ])>{{ $order->status->label() }}</span>
                    </div>
                </div>
            </div>

            <div class="p-6 sm:p-8 space-y-6">
                {{-- Customer & Payment Metadata Grid --}}
                <div class="grid gap-4 sm:grid-cols-2 rounded-xl border border-line bg-grey-soft/60 p-4 text-xs sm:text-sm">
                    <div class="space-y-1">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Billed To Customer</p>
                        <p class="font-bold text-ink">{{ $order->customer_name ?: 'Valued Customer' }}</p>
                        <p class="text-body">{{ $order->email }}</p>
                        @if ($order->phone)
                            <p class="font-mono text-xs text-muted">WhatsApp: +{{ $order->phone }}</p>
                        @endif
                    </div>

                    <div class="space-y-1 sm:text-right">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Payment &amp; Verification</p>
                        <p class="font-bold text-ink">{{ $order->paymentMethod?->name ?? 'Instant / Free' }}</p>
                        <p class="font-mono text-xs text-body">TID: <strong>{{ $order->latestPayment?->transaction_id ?? 'N/A' }}</strong></p>
                        <p class="text-xs text-muted">Date: {{ $order->created_at?->format('d M Y, h:i A') }}</p>
                    </div>
                </div>

                {{-- Itemized Table --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs sm:text-sm">
                        <thead class="border-b-2 border-ink text-[11px] font-bold uppercase tracking-wider text-muted">
                            <tr>
                                <th class="py-2.5 pr-3">Product / Subscription</th>
                                <th class="py-2.5 px-3 text-center">Qty</th>
                                <th class="py-2.5 px-3 text-right">Unit Price</th>
                                <th class="py-2.5 pl-3 text-right">Line Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($order->items as $item)
                                <tr>
                                    <td class="py-3.5 pr-3 font-bold text-ink">{{ $item->product_name }}</td>
                                    <td class="py-3.5 px-3 text-center font-mono">{{ $item->quantity }}</td>
                                    <td class="py-3.5 px-3 text-right font-mono">{{ money($item->unit_price) }}</td>
                                    <td class="py-3.5 pl-3 text-right font-mono font-bold text-ink">{{ money($item->line_total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t-2 border-ink">
                            <tr>
                                <td colspan="3" class="pt-4 text-right text-xs font-bold uppercase tracking-wider text-muted">Total Amount Paid</td>
                                <td class="pt-4 pl-3 text-right">
                                    <span class="display text-2xl sm:text-3xl text-ink">{{ money($order->total) }}</span>
                                    <span class="block text-[11px] font-semibold text-muted">≈ ${{ $totalUsd }} USDT</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if (filled($order->customer_input))
                    <div class="rounded-xl border border-line bg-grey-soft p-4">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted">Submitted Activation Details</p>
                        <p class="mt-1 font-mono text-xs sm:text-sm font-semibold text-ink break-all whitespace-pre-wrap">{{ $order->customer_input }}</p>
                    </div>
                @endif

                {{-- Delivered Credentials Summary --}}
                @php
                    $hasDeliveries = $order->items->some(fn ($i) => $i->deliveries->isNotEmpty());
                @endphp
                @if ($hasDeliveries)
                    <div class="rounded-xl bg-ink p-4 sm:p-5 text-white space-y-3">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-[#4ade80]">🔑 Delivered Access Credentials</p>
                        @foreach ($order->items as $item)
                            @foreach ($item->deliveries as $del)
                                <pre class="rounded-lg bg-white/10 p-3 font-mono text-xs sm:text-sm text-white whitespace-pre-wrap break-all">{{ trim($del->content) }}</pre>
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

