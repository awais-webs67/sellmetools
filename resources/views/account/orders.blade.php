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
                <h1 class="display mt-1.5 text-3xl sm:text-4xl">Welcome, {{ $user->name }}</h1>
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

        {{-- Wallet & Order Stats Grid --}}
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card border-2 border-brand/25 bg-ink p-4 sm:p-5 text-white">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold tracking-wider text-[#ff8fa3] uppercase">Account Wallet Balance</span>
                    <span class="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold text-white">Instant Pay</span>
                </div>
                <p class="display mt-2 text-3xl sm:text-3xl text-white">{{ money($user->wallet_balance) }}</p>
                @if ($waAdmin !== '')
                    <a href="https://wa.me/{{ $waAdmin }}?text={{ urlencode('Assalam o Alaikum! I want to top-up my SellMeTools Wallet Balance for account: '.$user->email) }}"
                       target="_blank"
                       rel="noopener"
                       class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-[#4ade80] hover:underline">
                        ➕ Request Wallet Top-Up on WhatsApp →
                    </a>
                @endif
            </div>

            <div class="card p-4 sm:p-5">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted">Total Orders</span>
                <p class="display mt-2 text-3xl sm:text-3xl text-ink">{{ number_format($stats['total']) }}</p>
                <p class="mt-1 text-xs text-muted">All-time purchases</p>
            </div>

            <div class="card p-4 sm:p-5">
                <span class="text-xs font-semibold uppercase tracking-wider text-success">Delivered &amp; Active</span>
                <p class="display mt-2 text-3xl sm:text-3xl text-ink">{{ number_format($stats['completed']) }}</p>
                <p class="mt-1 text-xs text-muted">Instant credentials vault</p>
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

        {{-- Search & Filter Bar --}}
        <div class="card mt-6 p-4 sm:p-5">
            <form method="get" action="{{ route('account.orders') }}" class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="relative flex-1">
                    <input type="search"
                           name="q"
                           value="{{ $search }}"
                           placeholder="Search orders by Order ID (SMT-...), tool name, or delivered email/key..."
                           class="input w-full pr-24 text-xs sm:text-sm">
                    @if ($statusFilter !== 'all')
                        <input type="hidden" name="status" value="{{ $statusFilter }}">
                    @endif
                    <button type="submit" class="btn btn-primary absolute top-1.5 right-1.5 px-3.5 py-1.5 text-xs">
                        Search
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach ([
                        'all' => 'All ('.$stats['total'].')',
                        'completed' => 'Delivered ('.$stats['completed'].')',
                        'pending' => 'In Progress ('.$stats['pending'].')',
                        'cancelled' => 'Closed',
                    ] as $key => $label)
                        <a href="{{ route('account.orders', array_filter(['q' => $search ?: null, 'status' => $key === 'all' ? null : $key])) }}"
                           @class([
                               'rounded-lg px-3 py-2 text-xs font-semibold transition',
                               'bg-brand text-white' => $statusFilter === $key,
                               'bg-grey-soft text-ink hover:bg-line' => $statusFilter !== $key,
                           ])>
                            {{ $label }}
                        </a>
                    @endforeach
                    @if ($search !== '' || $statusFilter !== 'all')
                        <a href="{{ route('account.orders') }}" class="px-2 text-xs font-semibold text-danger hover:underline">Reset</a>
                    @endif
                </div>
            </form>
        </div>

        {{-- Orders List --}}
        @if ($orders->isEmpty())
            <div class="card mt-6 p-10 text-center">
                <p class="text-lg font-bold text-ink">
                    {{ $search !== '' || $statusFilter !== 'all' ? 'No orders matched your search filter' : 'No orders in your history yet' }}
                </p>
                <p class="mt-1 text-xs sm:text-sm text-muted">
                    {{ $search !== '' || $statusFilter !== 'all' ? 'Try clearing your search or switching tabs above.' : 'All tools and subscriptions you purchase will appear here with instant 1-click credential copy.' }}
                </p>
                <a href="{{ route('home') }}#products" class="btn btn-primary mt-5">Explore Tools</a>
            </div>
        @else
            <div class="mt-6 space-y-4">
                @foreach ($orders as $order)
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
                                <span class="text-xs text-muted">{{ $order->created_at->format('d M Y, h:i A') }}</span>
                                @if ($order->paymentMethod)
                                    <span class="rounded bg-grey-soft px-2 py-0.5 text-[11px] font-medium text-muted">{{ $order->paymentMethod->name }}</span>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center gap-2.5">
                                <span class="display text-2xl text-ink">{{ money($order->total) }}</span>
                                @if ($order->status === OrderStatus::PendingPayment)
                                    <a href="{{ $order->customerUrl() }}" class="btn btn-primary px-3.5 py-1.5 text-xs">Pay Now →</a>
                                    <form method="post" action="{{ route('orders.cancel', $order) }}" onsubmit="return confirm('Cancel this unpaid order?')">
                                        @csrf
                                        <input type="hidden" name="token" value="{{ $order->access_token }}">
                                        <button type="submit" class="btn btn-outline px-3 py-1.5 text-xs text-danger hover:border-danger">Cancel</button>
                                    </form>
                                @else
                                    <a href="{{ $order->customerUrl() }}" class="btn btn-outline px-3.5 py-1.5 text-xs">View Receipt →</a>
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
                                                <div class="flex flex-col gap-2 rounded-xl border border-[#2B0912]/15 bg-[#120408] p-3 text-white sm:flex-row sm:items-center sm:justify-between" x-data="copy(@js(trim($delivery->content)))">
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
            <div class="mt-6">{{ $orders->links() }}</div>
        @endif

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
                                    <td class="py-2.5 px-3 text-ink">{{ $tx->note ?: '—' }}</td>
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
