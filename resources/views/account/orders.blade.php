@php
    use App\Enums\OrderStatus;
    $user = auth()->user();
    $waAdmin = admin_whatsapp_number();
@endphp
<x-layouts.store title="Customer Panel & Order Vault" robots="noindex, nofollow">
    <div class="container-x py-6 sm:py-10">
        {{-- Top Header & VIP Reseller Banner --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="eyebrow">Customer Control Panel</span>
                    @if ($user->is_reseller)
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-900 px-2.5 py-0.5 text-[11px] font-semibold text-white">
                            <svg class="h-3 w-3 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <span>VIP Reseller Account ({{ (float) $user->reseller_discount_percent > 0 ? number_format((float) $user->reseller_discount_percent, 0).'% Global Discount' : 'Custom Pricing' }})</span>
                        </span>
                    @endif
                </div>
                <h1 class="mt-1.5 text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Welcome, {{ $user->name }}</h1>
                <p class="mt-1 text-xs sm:text-sm text-slate-500">
                    {{ $user->email }}
                    @if ($user->phone) · WhatsApp: <span class="font-mono font-semibold text-slate-900">+{{ $user->phone }}</span> @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                @if ($user->is_reseller && filled($user->reseller_slug))
                    <a href="{{ route('reseller.portal', $user->reseller_slug) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3.5 py-2 text-xs sm:text-sm font-semibold text-white hover:bg-slate-800 transition">
                        <svg class="h-3.5 w-3.5 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        <span>Open Reseller Portal (/{{ $user->reseller_slug }})</span>
                    </a>
                @endif
                <a href="{{ route('home') }}#products" class="btn btn-primary text-xs sm:text-sm inline-flex items-center gap-1.5">
                    <span>Browse Tools</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </a>
            </div>
        </div>

        {{-- Wallet & Complete Purchasing Stats Grid --}}
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4 sm:p-5 text-white shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold tracking-wider text-slate-400 uppercase">Wallet Balance</span>
                    <span class="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold text-slate-200">Instant Pay</span>
                </div>
                <p class="mt-2 font-mono text-2xl font-bold tracking-tight text-white tabular-nums">{{ money($user->wallet_balance) }}</p>
                @if ($waAdmin !== '')
                    <a href="https://wa.me/{{ $waAdmin }}?text={{ urlencode('Assalam o Alaikum! I want to top-up my SellMeTools Wallet Balance for account: '.$user->email) }}"
                       target="_blank"
                       rel="noopener"
                       class="mt-3 inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-400 hover:underline">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                        <span>Top-Up Wallet</span>
                    </a>
                @endif
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-5 shadow-2xs">
                <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Total Spent</span>
                <p class="mt-2 font-mono text-2xl font-bold tracking-tight text-slate-900 tabular-nums">{{ money($stats['total_spent'] ?? 0) }}</p>
                <p class="mt-1 text-[11px] text-slate-500">
                    ≈ {{ money_usd($stats['total_spent'] ?? 0) }} · This month: <strong class="text-slate-900">{{ money($stats['this_month_spent'] ?? 0) }}</strong>
                </p>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-5 shadow-2xs">
                <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Total Orders</span>
                <p class="mt-2 font-mono text-2xl font-bold tracking-tight text-slate-900 tabular-nums">{{ number_format($stats['total']) }}</p>
                <p class="mt-1 text-[11px] text-slate-500">
                    <strong class="text-slate-900">{{ number_format($stats['total_items'] ?? 0) }}</strong> tools &amp; licenses purchased
                </p>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-5 shadow-2xs">
                <span class="text-[11px] font-semibold uppercase tracking-wider text-emerald-600">Delivered &amp; Active</span>
                <p class="mt-2 font-mono text-2xl font-bold tracking-tight text-slate-900 tabular-nums">{{ number_format($stats['completed']) }}</p>
                <p class="mt-1 text-[11px] text-slate-500">
                    {{ number_format($stats['pending']) }} in progress · Instant vault
                </p>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 sm:p-5 shadow-2xs" x-data="{ editingProfile: false }">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">WhatsApp Profile</span>
                    <button type="button" @click="editingProfile = !editingProfile" class="text-xs font-semibold text-brand hover:underline" x-text="editingProfile ? 'Cancel' : 'Edit'"></button>
                </div>
                <div x-show="!editingProfile" class="mt-2">
                    <p class="font-mono text-base font-bold text-slate-900">{{ $user->phone ?: 'Not set' }}</p>
                    <p class="mt-1 text-[11px] text-slate-500">Exact format: <code>923XXXXXXXXX</code></p>
                </div>
                <form x-show="editingProfile" x-cloak method="post" action="{{ route('account.profile.update') }}" class="mt-2 space-y-2">
                    @csrf
                    <input type="hidden" name="name" value="{{ $user->name }}">
                    <input type="tel" name="phone" required value="{{ $user->phone }}" placeholder="923001234567" class="input py-1.5 font-mono text-xs">
                    <button type="submit" class="btn btn-primary w-full py-1.5 text-xs">Save WhatsApp Number</button>
                </form>
            </div>
        </div>

        {{-- Search & Filters: results update live while typing, no Search button needed --}}
        <form method="get" action="{{ route('account.orders') }}" role="search"
              class="card mt-6 p-4 sm:p-5"
              x-data="liveFilter('#orders-results')" @submit.prevent="run()">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div class="relative flex-1">
                    <label for="order-search" class="sr-only">Search your orders</label>
                    <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input id="order-search" type="search" name="q" value="{{ $search }}" autocomplete="off"
                           placeholder="Search by Order ID (SMT-...), tool name, or delivered email/key"
                           class="input w-full pl-10 pr-10 text-xs sm:text-sm"
                           @input.debounce.350ms="run()">
                    <span x-show="loading" x-cloak class="absolute top-1/2 right-3.5 h-4 w-4 -translate-y-1/2 animate-spin rounded-full border-2 border-line border-t-brand" aria-hidden="true"></span>
                </div>

                <div class="flex flex-wrap items-center gap-1.5" role="radiogroup" aria-label="Order status">
                    @foreach ([
                        'all' => 'All ('.$stats['total'].')',
                        'completed' => 'Delivered ('.$stats['completed'].')',
                        'pending' => 'In Progress ('.$stats['pending'].')',
                        'cancelled' => 'Closed',
                    ] as $key => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="status" value="{{ $key }}" class="peer sr-only" @checked($statusFilter === $key) @change="run()">
                            <span class="block rounded-lg bg-grey-soft px-3 py-2 text-xs font-semibold text-ink transition select-none hover:bg-line active:scale-95 peer-checked:bg-brand peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="mt-3 flex flex-col gap-2.5 border-t border-line pt-3 sm:flex-row sm:items-center" x-data="{ range: @js($range) }">
                <span class="shrink-0 text-[11px] font-bold uppercase tracking-wider text-muted">Order date</span>
                <div class="flex flex-wrap items-center gap-1.5" role="radiogroup" aria-label="Order date">
                    @foreach ($dateRanges as $key => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="range" value="{{ $key }}" class="peer sr-only" x-model="range" @change="if (range !== 'custom') run()">
                            <span class="block rounded-full border border-line-strong bg-white px-3 py-1.5 text-xs font-semibold text-ink transition select-none hover:border-brand hover:text-brand active:scale-95 peer-checked:border-ink peer-checked:bg-ink peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <div x-show="range === 'custom'" x-cloak class="flex flex-wrap items-center gap-2 sm:ml-auto">
                    <label class="sr-only" for="date-from">From</label>
                    <input id="date-from" type="date" name="from" value="{{ $from?->toDateString() }}" max="{{ now()->toDateString() }}"
                           class="input w-auto py-1.5 text-xs" :disabled="range !== 'custom'" @change="run()">
                    <span class="text-xs text-muted">to</span>
                    <label class="sr-only" for="date-to">To</label>
                    <input id="date-to" type="date" name="to" value="{{ $to?->toDateString() }}" max="{{ now()->toDateString() }}"
                           class="input w-auto py-1.5 text-xs" :disabled="range !== 'custom'" @change="run()">
                </div>
            </div>
        </form>

        {{-- Orders List (swapped in place by the live filter) --}}
        <div id="orders-results" class="mt-6" aria-live="polite">
            <div class="flex items-center justify-between gap-3 text-xs text-muted">
                <p>
                    <span class="font-semibold text-ink">{{ $orders->total() }}</span> {{ \Illuminate\Support\Str::plural('order', $orders->total()) }}
                    @if ($range !== 'all')
                        · {{ $range === 'custom' ? trim(($from?->format('d M Y') ?? 'Start').' to '.($to?->format('d M Y') ?? 'Today')) : $dateRanges[$range] }}
                    @endif
                </p>
                @if ($filtersActive)
                    <a href="{{ route('account.orders') }}" class="font-semibold text-danger hover:underline">Clear filters</a>
                @endif
            </div>

            @if ($orders->isEmpty())
                <div class="card mt-4 p-10 text-center">
                    <p class="text-lg font-bold text-ink">
                        {{ $filtersActive ? 'No orders matched these filters' : 'No orders in your history yet' }}
                    </p>
                    <p class="mt-1 text-xs sm:text-sm text-muted">
                        {{ $filtersActive ? 'Try another search, status or date range.' : 'Tools and subscriptions you buy will appear here with 1-click credential copy.' }}
                    </p>
                    <a href="{{ route('home') }}#products" class="btn btn-primary mt-5">Explore Tools</a>
                </div>
            @else
                @foreach ($groupedOrders as $date => $dayOrders)
                    @php
                        $day = \Illuminate\Support\Carbon::parse($date);
                        $dayLabel = $day->isToday() ? 'Today' : ($day->isYesterday() ? 'Yesterday' : $day->format('l, d M Y'));
                    @endphp
                    <section class="mt-5" aria-label="Orders on {{ $dayLabel }}">
                        <h2 class="sticky top-16 z-10 -mx-1 flex items-center gap-3 bg-grey-soft/95 px-1 py-2 text-[11px] font-bold uppercase tracking-wider text-muted backdrop-blur lg:top-[76px]">
                            <span class="text-ink">{{ $dayLabel }}</span>
                            <span class="h-px flex-1 bg-line" aria-hidden="true"></span>
                            <span class="font-semibold normal-case tracking-normal">{{ $dayOrders->count() }} {{ \Illuminate\Support\Str::plural('order', $dayOrders->count()) }}</span>
                        </h2>

                        <div class="mt-2 space-y-4">
                            @foreach ($dayOrders as $order)
                                <div class="card overflow-hidden p-4 sm:p-6">
                                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-4">
                                        <div class="flex flex-wrap items-center gap-2.5">
                                            <a href="{{ $order->customerUrl() }}" class="font-mono text-sm sm:text-base font-bold text-ink hover:text-brand underline">
                                                {{ $order->number }}
                                            </a>
                                            <span @class([
                                                'badge text-xs',
                                                'badge-success' => $order->status === OrderStatus::Completed,
                                                'badge-brand' => in_array($order->status, OrderStatus::holdingStock(), true) || $order->status === OrderStatus::NeedsAttention,
                                                'badge-muted' => $order->status->isFinal() && $order->status !== OrderStatus::Completed,
                                            ])>{{ $order->status->label() }}</span>
                                            <time class="text-xs text-muted" datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->format('h:i A') }}</time>
                                            @if ($order->paymentMethod)
                                                <span class="rounded bg-grey-soft px-2 py-0.5 text-[11px] font-medium text-muted">{{ $order->paymentMethod->name }}</span>
                                            @endif
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-mono text-lg sm:text-xl font-bold text-slate-900 tabular-nums mr-1">{{ money($order->total) }}</span>
                                            <a href="{{ route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token]) }}"
                                               target="_blank"
                                               class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                                                <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                                <span>View Receipt</span>
                                            </a>
                                            <a href="{{ route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token, 'download' => 'pdf']) }}"
                                               class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                                                <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                                <span>PDF</span>
                                            </a>
                                            @if ($order->status === OrderStatus::PendingPayment)
                                                <a href="{{ $order->customerUrl() }}" class="btn btn-primary px-3.5 py-1.5 text-xs">Pay Now</a>
                                                <form method="post" action="{{ route('orders.cancel', $order) }}" onsubmit="return confirm('Cancel this unpaid order?')">
                                                    @csrf
                                                    <input type="hidden" name="token" value="{{ $order->access_token }}">
                                                    <button type="submit" class="btn btn-outline px-3 py-1.5 text-xs text-danger hover:border-danger">Cancel</button>
                                                </form>
                                            @else
                                                <a href="{{ $order->customerUrl() }}" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 transition">
                                                    <span>Order Details</span>
                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                                </a>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="mt-4 space-y-3">
                                        @foreach ($order->items as $item)
                                            <div>
                                                <div class="flex items-center justify-between gap-2">
                                                    <p class="text-xs sm:text-sm font-bold text-slate-900">
                                                        {{ $item->product_name }} <span class="font-normal text-slate-500">× {{ $item->quantity }}</span>
                                                    </p>
                                                    <span class="font-mono text-xs text-slate-500">{{ money($item->unit_price) }} each</span>
                                                </div>

                                                @if ($item->deliveries->isNotEmpty())
                                                    <div class="mt-2.5 grid gap-2">
                                                        @foreach ($item->deliveries as $delivery)
                                                            <div class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3.5 sm:flex-row sm:items-center sm:justify-between" x-data="copy(@js(trim($delivery->content)))">
                                                                <div class="min-w-0">
                                                                    <div class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                                                                        <svg class="h-3 w-3 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                                                        <span>Delivered Credential</span>
                                                                    </div>
                                                                    <code class="mt-1 block font-mono text-xs sm:text-sm font-semibold break-all whitespace-pre-wrap text-slate-900">{{ trim($delivery->content) }}</code>
                                                                </div>
                                                                <button type="button"
                                                                        class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white shrink-0 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition"
                                                                        @click="copy">
                                                                    <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                                                    <span x-text="copied ? 'Copied' : 'Copy Credential'">Copy Credential</span>
                                                                </button>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
                <div class="mt-6">{{ $orders->links() }}</div>
            @endif
        </div>

        {{-- Wallet Ledger History --}}
        @if ($walletTransactions->isNotEmpty())
            <div class="card mt-8 overflow-hidden p-4 sm:p-6">
                <h2 class="text-base sm:text-lg font-bold text-ink">Recent Wallet Transactions</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-xs sm:text-sm">
                        <thead class="border-b border-line text-muted uppercase text-[11px]">
                            <tr>
                                <th class="py-2 pr-3">Date</th>
                                <th class="py-2 px-3">Type</th>
                                <th class="py-2 px-3">Note</th>
                                <th class="py-2 px-3 text-right">Amount</th>
                                <th class="py-2 pl-3 text-right">Balance After</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($walletTransactions as $tx)
                                @php $isCredit = in_array($tx->type, ['topup', 'refund'], true); @endphp
                                <tr>
                                    <td class="py-2.5 pr-3 text-muted whitespace-nowrap">{{ $tx->created_at->format('d M Y, h:i A') }}</td>
                                    <td class="py-2.5 px-3">
                                        <span @class(['badge text-[10px]', 'badge-success' => $isCredit, 'badge-brand' => ! $isCredit])>
                                            {{ strtoupper(str_replace('_', ' ', $tx->type)) }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3 text-ink">{{ $tx->note ?: '-' }}</td>
                                    <td class="py-2.5 px-3 text-right font-mono font-bold {{ $isCredit ? 'text-success' : 'text-danger' }}">
                                        {{ $isCredit ? '+' : '-' }}{{ money($tx->amount) }}
                                    </td>
                                    <td class="py-2.5 pl-3 text-right font-mono font-semibold text-ink">{{ money($tx->balance_after) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-layouts.store>
