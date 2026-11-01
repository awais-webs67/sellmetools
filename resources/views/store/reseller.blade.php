@php
    use App\Enums\OrderStatus;
    $waAdmin = admin_whatsapp_number();
@endphp
<x-layouts.store :title="'VIP Reseller Portal – '.$reseller->name" robots="noindex, nofollow">
    <div class="container-x py-6 sm:py-10">
        @if (! $authorized)
            {{-- Secret Reseller Login Screen --}}
            <div class="mx-auto max-w-md py-6 sm:py-12">
                <div class="card overflow-hidden border-2 border-brand/30 shadow-xl">
                    <div class="bg-[#120408] px-6 py-6 text-white">
                        <div class="flex items-center justify-between">
                            <span class="rounded-full bg-brand px-3 py-0.5 text-[11px] font-bold tracking-wider text-white uppercase">
                                🔒 Secret VIP Reseller Portal
                            </span>
                            <span class="font-mono text-xs text-[#ff8fa3]">/{{ $reseller->reseller_slug }}</span>
                        </div>
                        <h1 class="display mt-3 text-3xl sm:text-3xl text-white">{{ $reseller->name }}</h1>
                        <p class="mt-1 text-xs sm:text-sm text-white/75">
                            Sign in with your authorized reseller credentials to unlock wholesale pricing &amp; instant wallet checkout.
                        </p>
                    </div>

                    <form method="post" action="{{ route('reseller.login', $reseller->reseller_slug) }}" class="p-6 space-y-4">
                        @csrf
                        <div>
                            <label for="email" class="label">Reseller Email</label>
                            <input id="email" name="email" type="email" required autofocus class="input" value="{{ old('email') }}" placeholder="reseller@example.com">
                            @error('email')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="password" class="label">Password</label>
                            <input id="password" name="password" type="password" required class="input" placeholder="••••••••">
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-full font-bold">
                            Unlock Reseller Portal →
                        </button>
                    </form>
                </div>
            </div>
        @else
            {{-- Authorized Reseller Dashboard & Special Price Catalog --}}
            <div class="rounded-2xl border border-[#2B0912]/20 bg-[#120408] p-5 sm:p-8 text-white shadow-xl">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full bg-brand px-3 py-0.5 text-[11px] font-bold tracking-wider uppercase text-white">
                                ★ VIP Reseller Partner
                            </span>
                            <span class="font-mono text-xs text-[#ff8fa3]">{{ url('/'.$reseller->reseller_slug) }}</span>
                            @if ((float) $reseller->reseller_discount_percent > 0)
                                <span class="rounded-full bg-white/10 px-2.5 py-0.5 text-xs font-semibold text-[#4ade80]">
                                    {{ number_format((float) $reseller->reseller_discount_percent, 0) }}% Global Wholesale Discount
                                </span>
                            @endif
                        </div>
                        <h1 class="display mt-2 text-3xl sm:text-4xl text-white">{{ $reseller->name }} — Wholesale Portal</h1>
                        <p class="mt-1 text-xs sm:text-sm text-white/75">
                            All prices below reflect your private reseller rates. Buy in 1 click using your Wallet Balance or any payment gateway.
                        </p>
                    </div>

                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 rounded-xl border border-white/15 bg-white/5 p-4">
                        <div>
                            <span class="block text-[10px] font-bold uppercase tracking-wider text-[#ff8fa3]">Reseller Wallet Balance</span>
                            <span class="display text-3xl sm:text-3xl text-white">{{ money($reseller->wallet_balance) }}</span>
                        </div>
                        @if ($waAdmin !== '')
                            <a href="https://wa.me/{{ $waAdmin }}?text={{ urlencode('Assalam o Alaikum! I want to add funds to my Reseller Wallet (@'.$reseller->reseller_slug.' / '.$reseller->email.')') }}"
                               target="_blank"
                               rel="noopener"
                               class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] px-4 py-2.5 text-xs font-bold">
                                ➕ Top-Up Wallet
                            </a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Search & Category Filter --}}
            <div class="card mt-6 p-4">
                <form method="get" action="{{ route('reseller.portal', $reseller->reseller_slug) }}" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <input type="search" name="q" value="{{ $search }}" placeholder="Search wholesale catalog..." class="input flex-1 text-xs sm:text-sm">
                    <select name="category" onchange="this.form.submit()" class="input sm:w-56 text-xs sm:text-sm">
                        <option value="">All Categories</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->slug }}" @selected($categorySlug === $cat->slug)>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-primary px-5 py-2.5 text-xs sm:text-sm">Filter</button>
                </form>
            </div>

            {{-- Reseller Wholesale Product Table / Grid --}}
            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($products as $product)
                    @php
                        $retail = (float) $product->price;
                        $wholesale = $product->priceFor($reseller);
                        $saving = max(0, $retail - $wholesale);
                        $savingPct = $retail > 0 && $saving > 0 ? round(($saving / $retail) * 100) : 0;
                        $canWalletBuy = $product->inStock() && (float) $reseller->wallet_balance >= $wholesale;
                    @endphp
                    <div class="card flex flex-col justify-between p-4 sm:p-5">
                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    @if ($img = $product->imageUrl())
                                        <img src="{{ $img }}" alt="{{ $product->name }}" class="h-12 w-12 shrink-0 rounded-lg object-cover border border-line">
                                    @endif
                                    <div class="min-w-0">
                                        <span class="text-[11px] font-semibold text-muted">{{ $product->category?->name ?? 'Digital Tool' }}</span>
                                        <h2 class="text-sm sm:text-base font-bold text-ink leading-snug break-words">
                                            <a href="{{ route('products.show', $product) }}" class="hover:text-brand">{{ $product->name }}</a>
                                        </h2>
                                    </div>
                                </div>
                                @if ($savingPct > 0)
                                    <span class="badge badge-brand shrink-0 text-[10px]">-{{ $savingPct }}% VIP</span>
                                @endif
                            </div>

                            <div class="mt-4 flex items-baseline justify-between rounded-xl bg-grey-soft px-3.5 py-2.5">
                                <div>
                                    <span class="block text-[10px] uppercase tracking-wider text-muted">Your Reseller Price</span>
                                    <span class="display text-2xl sm:text-3xl text-brand">{{ money($wholesale) }}</span>
                                    @if ($saving > 0)
                                        <span class="ml-1.5 text-xs text-muted line-through">{{ money($retail) }}</span>
                                    @endif
                                </div>
                                <div class="text-right">
                                    @if ($product->inStock())
                                        <span class="badge badge-success text-[10px]">
                                            {{ $product->stock_unlimited ? 'In Stock' : $product->available_stock.' left' }}
                                        </span>
                                    @else
                                        <span class="badge badge-danger text-[10px]">Out of Stock</span>
                                    @endif
                                    @if ($saving > 0)
                                        <span class="mt-1 block text-[11px] font-semibold text-success">Profit: {{ money($saving) }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 space-y-2 pt-2">
                            @if ($product->inStock())
                                @if ($canWalletBuy)
                                    <form method="post" action="{{ route('reseller.wallet-buy', ['slug' => $reseller->reseller_slug, 'product' => $product->slug]) }}" onsubmit="return confirm('Instant buy {{ addslashes($product->name) }} for {{ money($wholesale) }} from your Reseller Wallet?')">
                                        @csrf
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit" class="btn btn-primary w-full py-2.5 text-xs sm:text-sm font-bold">
                                            ⚡ 1-Click Buy with Wallet ({{ money($wholesale) }})
                                        </button>
                                    </form>
                                @endif
                                <a href="{{ route('checkout.create', $product) }}" class="btn btn-outline w-full py-2 text-xs font-semibold">
                                    {{ $canWalletBuy ? 'Or Checkout via Other Gateways →' : 'Buy at Wholesale Price ('.money($wholesale).') →' }}
                                </a>
                            @else
                                <button disabled class="btn w-full bg-line text-muted py-2.5 text-xs cursor-not-allowed">Sold Out</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="card col-span-full p-8 text-center text-muted">No products found matching your filter.</div>
                @endforelse
            </div>

            {{-- Recent Reseller Orders & Credentials --}}
            @if ($orders->isNotEmpty())
                <div class="mt-10">
                    <div class="flex items-center justify-between">
                        <h2 class="display text-2xl sm:text-3xl text-ink">Recent Reseller Orders</h2>
                        <a href="{{ route('account.orders') }}" class="text-xs sm:text-sm font-bold text-brand hover:underline">View All &amp; Search Orders →</a>
                    </div>
                    <div class="mt-4 space-y-3">
                        @foreach ($orders as $order)
                            <div class="card p-4 sm:p-5">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex items-center gap-2.5">
                                        <a href="{{ $order->customerUrl() }}" class="font-mono text-sm font-bold text-ink underline">{{ $order->number }}</a>
                                        <span class="badge {{ $order->status === OrderStatus::Completed ? 'badge-success' : 'badge-brand' }} text-[11px]">{{ $order->status->label() }}</span>
                                        <span class="text-xs text-muted">{{ $order->created_at->diffForHumans() }}</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="font-bold text-ink">{{ money($order->total) }}</span>
                                        <a href="{{ $order->customerUrl() }}" class="btn btn-outline px-3 py-1 text-xs">Receipt</a>
                                    </div>
                                </div>
                                @foreach ($order->items as $item)
                                    @if ($item->deliveries->isNotEmpty())
                                        <div class="mt-2.5 space-y-1.5">
                                            @foreach ($item->deliveries as $delivery)
                                                <div class="flex items-center justify-between gap-2 rounded-lg bg-[#120408] px-3 py-2 text-white" x-data="copy(@js(trim($delivery->content)))">
                                                    <code class="font-mono text-xs text-[#4ade80] break-all">{{ $item->product_name }}: {{ trim($delivery->content) }}</code>
                                                    <button type="button" @click="copy" class="shrink-0 rounded bg-brand px-2.5 py-1 text-[11px] font-bold text-white" x-text="copied ? 'Copied!' : 'Copy'"></button>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-layouts.store>

