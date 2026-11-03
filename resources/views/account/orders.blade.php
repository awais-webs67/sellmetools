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
                        <span class="badge badge-brand">★ VIP Reseller Account ({{ (float) $user->reseller_discount_percent > 0 ? number_format((float) $user->reseller_discount_percent, 0).'% Global Discount' : 'Custom Pricing' }})</span>
                    @endif
                </div>
                <h1 class="display mt-1.5 text-3xl sm:text-5xl">Welcome, {{ $user->name }}</h1>
                <p class="mt-1 text-xs sm:text-sm text-muted">
                    {{ $user->email }}
                    @if ($user->phone) · WhatsApp: <span class="font-mono font-semibold text-ink">+{{ $user->phone }}</span> @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                @if ($user->is_reseller && filled($user->reseller_slug))
                    <a href="{{ route('reseller.portal', $user->reseller_slug) }}" class="btn btn-dark text-xs sm:text-sm">
                        ⚡ Open My Reseller Portal (/{{ $user->reseller_slug }})
                    </a>
                @endif
                <a href="{{ route('home') }}#products" class="btn btn-primary text-xs sm:text-sm">Browse Tools →</a>
            </div>
        </div>

        {{-- Wallet & Complete Purchasing Stats Grid --}}
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="card border-2 border-brand/25 bg-ink p-4 sm:p-5 text-white">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold tracking-wider text-[#ff8fa3] uppercase">Wallet Balance</span>
                    <span class="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold text-white">Instant Pay</span>
                </div>
                <p class="display mt-2 text-2xl sm:text-3xl text-white">{{ money($user->wallet_balance) }}</p>
                @if ($waAdmin !== '')
                    <a href="https://wa.me/{{ $waAdmin }}?text={{ urlencode('Assalam o Alaikum! I want to top-up my SellMeTools Wallet Balance for account: '.$user->email) }}"
                       target="_blank"
                       rel="noopener"
                       class="mt-3 inline-flex items-center gap-1 text-[11px] font-bold text-[#4ade80] hover:underline">
                        ➕ Top-Up Wallet →
                    </a>
                @endif
            </div>

            <div class="card p-4 sm:p-5">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted">Total Spent</span>
                <p class="display mt-2 text-2xl sm:text-3xl text-ink">{{ money($stats['total_spent'] ?? 0) }}</p>
                <p class="mt-1 text-[11px] text-muted">
                    ≈ {{ money_usd($stats['total_spent'] ?? 0) }} · This month: <strong class="text-ink">{{ money($stats['this_month_spent'] ?? 0) }}</strong>
                </p>
            </div>

            <div class="card p-4 sm:p-5">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted">Total Orders</span>
                <p class="display mt-2 text-2xl sm:text-3xl text-ink">{{ number_format($stats['total']) }}</p>
                <p class="mt-1 text-[11px] text-muted">
                    <strong class="text-ink">{{ number_format($stats['total_items'] ?? 0) }}</strong> tools &amp; licenses purchased
                </p>
            </div>

            <div class="card p-4 sm:p-5">
                <span class="text-xs font-semibold uppercase tracking-wider text-success">Delivered &amp; Active</span>
                <p class="display mt-2 text-2xl sm:text-3xl text-ink">{{ number_format($stats['completed']) }}</p>
                <p class="mt-1 text-[11px] text-muted">
                    {{ number_format($stats['pending']) }} in progress · Instant vault
                </p>
            </div>

            <div class="card p-4 sm:p-5" x-data="{ editingProfile: false }">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-muted">WhatsApp Profile</span>
                    <button type="button" @click="editingProfile = !editingProfile" class="text-xs font-bold text-brand hover:underline" x-text="editingProfile ? 'Cancel' : 'Edit'"></button>
                </div>
                <div x-show="!editingProfile" class="mt-2">
                    <p class="font-mono text-base font-bold text-ink">{{ $user->phone ?: 'Not set' }}</p>
                    <p class="mt-1 text-[11px] text-muted">Exact format: <code>923XXXXXXXXX</code></p>
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
                                            <span class="display text-2xl text-ink mr-1">{{ money($order->total) }}</span>
                                            <a href="{{ route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token]) }}"
                                               target="_blank"
                                               class="btn btn-outline px-3 py-1.5 text-xs font-semibold">
                                                🧾 View Receipt
                                            </a>
                                            <a href="{{ route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token, 'download' => 'pdf']) }}"
                                               class="btn btn-outline px-3 py-1.5 text-xs font-semibold">
                                                📥 PDF
                                            </a>
                                            @if ($order->status === OrderStatus::PendingPayment)
                                                <a href="{{ $order->customerUrl() }}" class="btn btn-primary px-3.5 py-1.5 text-xs">Pay Now →</a>
                                                <form method="post" action="{{ route('orders.cancel', $order) }}" onsubmit="return confirm('Cancel this unpaid order?')">
                                                    @csrf
                                                    <input type="hidden" name="token" value="{{ $order->access_token }}">
                                                    <button type="submit" class="btn btn-outline px-3 py-1.5 text-xs text-danger hover:border-danger">Cancel</button>
                                                </form>
                                            @else
                                                <a href="{{ $order->customerUrl() }}" class="btn btn-primary px-3.5 py-1.5 text-xs">Order Vault →</a>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="mt-4 space-y-3">
                                        @foreach ($order->items as $item)
                                            <div>
                                                <div class="flex items-center justify-between gap-2">
                                                    <p class="text-xs sm:text-sm font-bold text-ink">
                                                        {{ $item->product_name }} <span class="font-normal text-muted">× {{ $item->quantity }}</span>
                                                    </p>
                                                    <span class="text-xs text-muted">{{ money($item->unit_price) }} each</span>
                                                </div>

                                                @if ($item->deliveries->isNotEmpty())
                                                    <div class="mt-2.5 grid gap-2">
                                                        @foreach ($item->deliveries as $delivery)
                                                            <div class="surface-dark-solid flex flex-col gap-2 rounded-xl border border-line p-3 text-white sm:flex-row sm:items-center sm:justify-between" x-data="copy(@js(trim($delivery->content)))">
                                                                <div class="min-w-0">
                                                                    <span class="block text-[10px] font-bold uppercase tracking-wider text-[#ff8fa3]">🔑 Delivered Credential</span>
                                                                    <code class="mt-0.5 block font-mono text-xs sm:text-sm font-semibold break-all text-[#4ade80]">{{ trim($delivery->content) }}</code>
                                                                </div>
                                                                <button type="button"
                                                                        class="btn bg-brand text-white hover:bg-brand-hover shrink-0 px-3.5 py-1.5 text-xs font-bold"
                                                                        @click="copy"
                                                                        x-text="copied ? '✓ Copied!' : 'Copy Credential'">
                                                                    Copy Credential
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
