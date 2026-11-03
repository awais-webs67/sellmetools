@php
    use App\Enums\OrderStatus;
    $waAdmin = admin_whatsapp_number();

    $inStockCount = $products->filter(fn ($p) => $p->inStock())->count();
    $maxSavingPct = 0;
    $totalSavingsSum = 0;
    $savingsCount = 0;

    foreach ($products as $p) {
        $ret = (float) $p->price;
        $wh = (float) $p->priceFor($reseller);
        $sav = max(0, $ret - $wh);
        if ($ret > 0 && $sav > 0) {
            $pct = (int) round(($sav / $ret) * 100);
            if ($pct > $maxSavingPct) {
                $maxSavingPct = $pct;
            }
            $totalSavingsSum += $sav;
            $savingsCount++;
        }
    }
    $avgSaving = $savingsCount > 0 ? round($totalSavingsSum / $savingsCount) : 0;
    $portalUrl = url('/'.$reseller->reseller_slug);
@endphp
<x-layouts.store :title="'VIP Reseller Portal: '.$reseller->name" robots="noindex, nofollow">
    <div class="container-x py-6 sm:py-10">
        @if (session('success'))
            <div class="mb-6 flex items-center justify-between gap-3 rounded-xl border border-emerald-500/30 bg-emerald-50 px-4 py-3.5 text-xs sm:text-sm font-semibold text-emerald-900 shadow-xs">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white text-xs font-bold">✓</span>
                    <span>{{ session('success') }}</span>
                </div>
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 flex items-center justify-between gap-3 rounded-xl border border-danger/30 bg-danger-tint px-4 py-3.5 text-xs sm:text-sm font-semibold text-danger shadow-xs">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-danger text-white text-xs font-bold">!</span>
                    <span>{{ session('error') }}</span>
                </div>
            </div>
        @endif

        @if (! $authorized)
            {{-- Redesigned Secret VIP Reseller Login Screen --}}
            <div class="mx-auto max-w-lg py-4 sm:py-10">
                <div class="card overflow-hidden border border-line-strong shadow-xl">
                    <div class="surface-banner px-6 py-7 sm:px-8 sm:py-8 text-white">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 border border-white/20 px-3 py-1 text-[11px] font-bold tracking-wider text-white uppercase backdrop-blur-xs">
                                <span class="h-2 w-2 rounded-full bg-[#4ade80] live-dot"></span>
                                VIP Reseller Partner Portal
                            </span>
                            <span class="rounded-lg bg-white/10 px-2.5 py-1 font-mono text-xs font-semibold text-dark-accent">
                                /{{ $reseller->reseller_slug }}
                            </span>
                        </div>

                        <h1 class="display mt-4 text-3xl sm:text-4xl text-white tracking-wide">{{ $reseller->name }}</h1>
                        <p class="mt-1.5 text-xs sm:text-sm text-white/80 leading-relaxed">
                            Sign in with your authorized partner account to unlock private wholesale rates, 1-click wallet auto-delivery, and your instant credentials vault.
                        </p>

                        <div class="mt-5 grid grid-cols-3 gap-2 pt-4 border-t border-white/15 text-center">
                            <div class="rounded-lg bg-white/[0.06] px-2 py-2">
                                <span class="block text-xs font-bold text-white">⚡ 1-Click</span>
                                <span class="block text-[10px] text-white/70">Wallet Checkout</span>
                            </div>
                            <div class="rounded-lg bg-white/[0.06] px-2 py-2">
                                <span class="block text-xs font-bold text-[#4ade80]">💰 Wholesale</span>
                                <span class="block text-[10px] text-white/70">Partner Rates</span>
                            </div>
                            <div class="rounded-lg bg-white/[0.06] px-2 py-2">
                                <span class="block text-xs font-bold text-white">🔐 24/7 Vault</span>
                                <span class="block text-[10px] text-white/70">Instant Keys</span>
                            </div>
                        </div>
                    </div>

                    <form method="post" action="{{ route('reseller.login', $reseller->reseller_slug) }}" class="p-6 sm:p-8 space-y-4 bg-white" x-data="{ showPass: false }">
                        @csrf
                        <div>
                            <label for="email" class="label text-xs sm:text-sm font-semibold">Reseller Email Address</label>
                            <input id="email" name="email" type="email" required autofocus class="input py-2.5 text-sm" value="{{ old('email') }}" placeholder="partner@example.com">
                            @error('email')<p class="field-error text-xs">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label for="password" class="text-xs sm:text-sm font-semibold text-ink">Password</label>
                                <button type="button" @click="showPass = !showPass" class="text-xs font-semibold text-brand hover:underline cursor-pointer" x-text="showPass ? 'Hide' : 'Show'"></button>
                            </div>
                            <input id="password" name="password" :type="showPass ? 'text' : 'password'" required class="input py-2.5 text-sm" placeholder="Enter your password">
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-full text-sm sm:text-base font-bold shadow-md">
                            Unlock Wholesale Portal →
                        </button>

                        <div class="pt-3 border-t border-line flex items-center justify-between text-xs text-muted">
                            <a href="{{ route('password.request') }}" class="hover:text-ink hover:underline">Forgot password?</a>
                            @if ($waAdmin !== '')
                                <a href="https://wa.me/{{ $waAdmin }}?text={{ urlencode('Hi Admin, I need help signing in to my Reseller Portal (/'.$reseller->reseller_slug.').') }}" target="_blank" rel="noopener" class="font-semibold text-success hover:underline">
                                    Need Reseller Support?
                                </a>
                            @endif
                        </div>
                    </form>
                </div>
            </div>
        @else
            {{-- Authorized VIP Reseller Command Center --}}
            <div x-data="{ tab: 'catalog', viewMode: 'grid', onlyInStock: false }">

                {{-- Top Reseller Header Bar --}}
                <div class="card overflow-hidden border border-line-strong">
                    <div class="surface-banner p-5 sm:p-7 text-white">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 border border-white/20 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-white">
                                        <span class="h-2 w-2 rounded-full bg-[#4ade80] live-dot"></span>
                                        ★ VIP Wholesale Partner
                                    </span>

                                    @if ((float) $reseller->reseller_discount_percent > 0)
                                        <span class="rounded-full bg-[#4ade80]/20 border border-[#4ade80]/40 px-3 py-1 text-[11px] font-bold text-[#4ade80]">
                                            {{ number_format((float) $reseller->reseller_discount_percent, 0) }}% Global Wholesale Discount
                                        </span>
                                    @elseif ($maxSavingPct > 0)
                                        <span class="rounded-full bg-[#4ade80]/20 border border-[#4ade80]/40 px-3 py-1 text-[11px] font-bold text-[#4ade80]">
                                            Up to {{ $maxSavingPct }}% Off Retail
                                        </span>
                                    @endif

                                    <div class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-1 text-xs font-mono text-white/90" x-data="copy(@js($portalUrl))">
                                        <span class="truncate max-w-[190px] sm:max-w-xs">{{ $portalUrl }}</span>
                                        <button type="button" @click="copy" class="rounded bg-white/20 hover:bg-white/30 px-2 py-0.5 text-[10px] font-sans font-bold text-white transition cursor-pointer" x-text="copied ? '✓ Copied' : 'Copy Link'"></button>
                                    </div>
                                </div>

                                <h1 class="display text-3xl sm:text-5xl text-white tracking-wide">
                                    {{ $reseller->name }} · Wholesale Desk
                                </h1>
                                <p class="text-xs sm:text-sm text-white/80 max-w-2xl leading-relaxed">
                                    All catalog prices below automatically reflect your private reseller tier. Use your Wallet Balance for 1-click instant delivery without waiting for payment verification.
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2.5 shrink-0">
                                @if ($waAdmin !== '')
                                    <a href="https://wa.me/{{ $waAdmin }}?text={{ urlencode('Assalam o Alaikum! I want to add funds to my Reseller Wallet (@'.$reseller->reseller_slug.' / '.$reseller->email.')') }}"
                                       target="_blank"
                                       rel="noopener"
                                       class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] px-4 py-2.5 text-xs sm:text-sm font-bold shadow-md">
                                        ➕ Top-Up Wallet on WhatsApp
                                    </a>
                                @endif
                                <a href="{{ route('account.orders') }}" class="btn btn-hero-secondary px-4 py-2.5 text-xs sm:text-sm font-semibold">
                                    Full Order Vault →
                                </a>
                            </div>
                        </div>
                    </div>

                    {{-- 5-Card Reseller KPI Strip --}}
                    <div class="grid grid-cols-2 gap-px bg-line lg:grid-cols-5">
                        {{-- KPI 1: Wallet Balance --}}
                        <div class="bg-white p-4 sm:p-5 col-span-2 sm:col-span-1">
                            <div class="flex items-center justify-between gap-1">
                                <span class="text-[11px] font-bold uppercase tracking-wider text-brand">Available Wallet</span>
                                <span class="rounded-full bg-emerald-50 border border-emerald-200 px-2 py-0.5 text-[10px] font-bold text-emerald-700">1-Click Auto</span>
                            </div>
                            <p class="display mt-1.5 text-2xl sm:text-3xl text-ink" x-text="$store.currency.format({{ (float) $reseller->wallet_balance }})">
                                {{ money($reseller->wallet_balance) }}
                            </p>
                            <p class="mt-0.5 text-[11px] text-muted">
                                ≈ {{ money_usd($reseller->wallet_balance) }} ready to spend
                            </p>
                        </div>

                        {{-- KPI 2: Wholesale Spend --}}
                        <div class="bg-white p-4 sm:p-5">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-muted">Wholesale Spend</span>
                            <p class="display mt-1.5 text-2xl sm:text-3xl text-ink" x-text="$store.currency.format({{ (float) ($stats['total_spent'] ?? 0) }})">
                                {{ money($stats['total_spent'] ?? 0) }}
                            </p>
                            <p class="mt-0.5 text-[11px] text-muted">
                                This month: <strong class="text-ink">{{ money($stats['this_month_spent'] ?? 0) }}</strong>
                            </p>
                        </div>

                        {{-- KPI 3: Delivered Orders --}}
                        <div class="bg-white p-4 sm:p-5">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-success">Delivered Orders</span>
                            <p class="display mt-1.5 text-2xl sm:text-3xl text-ink">{{ number_format($stats['completed'] ?? 0) }}</p>
                            <p class="mt-0.5 text-[11px] text-muted">
                                <strong class="text-ink">{{ number_format($stats['total_items'] ?? 0) }}</strong> licenses delivered
                            </p>
                        </div>

                        {{-- KPI 4: Ready Stock --}}
                        <div class="bg-white p-4 sm:p-5">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-muted">Instant Catalog</span>
                            <p class="display mt-1.5 text-2xl sm:text-3xl text-ink">{{ $inStockCount }} <span class="text-base text-muted font-sans font-normal">/ {{ $products->count() }}</span></p>
                            <p class="mt-0.5 text-[11px] text-muted">
                                Tools in stock right now
                            </p>
                        </div>

                        {{-- KPI 5: Reseller Profit Margin --}}
                        <div class="bg-white p-4 sm:p-5">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">Avg Reseller Margin</span>
                            <p class="display mt-1.5 text-2xl sm:text-3xl text-emerald-700">
                                {{ $avgSaving > 0 ? '+'.money($avgSaving) : ($maxSavingPct > 0 ? $maxSavingPct.'% OFF' : 'VIP Tier') }}
                            </p>
                            <p class="mt-0.5 text-[11px] text-muted">
                                {{ $maxSavingPct > 0 ? 'Up to '.$maxSavingPct.'% below retail price' : 'Custom wholesale pricing' }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Interactive Section Tabs & View Mode Bar --}}
                <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="inline-flex flex-wrap items-center gap-1.5 rounded-xl border border-line bg-white p-1.5 shadow-2xs">
                        <button type="button"
                                @click="tab = 'catalog'"
                                :class="tab === 'catalog' ? 'bg-brand text-white shadow-xs' : 'text-ink hover:bg-grey-soft'"
                                class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-xs sm:text-sm font-bold transition cursor-pointer">
                            <span>📦 Wholesale Catalog</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px]" :class="tab === 'catalog' ? 'bg-white/20 text-white' : 'bg-grey text-slate'">{{ $products->count() }}</span>
                        </button>

                        <button type="button"
                                @click="tab = 'orders'"
                                :class="tab === 'orders' ? 'bg-brand text-white shadow-xs' : 'text-ink hover:bg-grey-soft'"
                                class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-xs sm:text-sm font-bold transition cursor-pointer">
                            <span>🔐 Delivered Credentials</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px]" :class="tab === 'orders' ? 'bg-white/20 text-white' : 'bg-grey text-slate'">{{ $orders->count() }}</span>
                        </button>

                        <button type="button"
                                @click="tab = 'wallet'"
                                :class="tab === 'wallet' ? 'bg-brand text-white shadow-xs' : 'text-ink hover:bg-grey-soft'"
                                class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-xs sm:text-sm font-bold transition cursor-pointer">
                            <span>💳 Wallet Ledger</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px]" :class="tab === 'wallet' ? 'bg-white/20 text-white' : 'bg-grey text-slate'">{{ $walletTransactions->count() }}</span>
                        </button>
                    </div>

                    {{-- View Mode & Stock Filter (visible on Catalog tab) --}}
                    <div x-show="tab === 'catalog'" class="flex flex-wrap items-center gap-2">
                        <button type="button"
                                @click="onlyInStock = !onlyInStock"
                                :class="onlyInStock ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-line-strong bg-white text-ink hover:bg-grey-soft'"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-bold transition cursor-pointer">
                            <span class="h-2 w-2 rounded-full" :class="onlyInStock ? 'bg-emerald-600' : 'bg-muted'"></span>
                            <span>In Stock Only ({{ $inStockCount }})</span>
                        </button>

                        <div class="inline-flex items-center rounded-lg border border-line-strong bg-white p-0.5" role="group" aria-label="Catalog layout">
                            <button type="button"
                                    @click="viewMode = 'grid'"
                                    :class="viewMode === 'grid' ? 'bg-ink text-white' : 'text-muted hover:text-ink'"
                                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-xs font-bold transition cursor-pointer">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                                <span>Cards</span>
                            </button>
                            <button type="button"
                                    @click="viewMode = 'table'"
                                    :class="viewMode === 'table' ? 'bg-ink text-white' : 'text-muted hover:text-ink'"
                                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-xs font-bold transition cursor-pointer">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                                <span>Fast Table</span>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- TAB 1: WHOLESALE CATALOG --}}
                <div x-show="tab === 'catalog'" class="mt-4">
                    {{-- Search & Category Filter Card --}}
                    <div class="card p-4">
                        <form method="get" action="{{ route('reseller.portal', $reseller->reseller_slug) }}" class="flex flex-col gap-3 sm:flex-row sm:items-center"
                              x-data="liveFilter('#reseller-results')" @submit.prevent="run()">
                            <div class="relative flex-1">
                                <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                                <input type="search" name="q" value="{{ $search }}" placeholder="Search wholesale tools by name (e.g. ChatGPT, Canva, CapCut, Netflix)..." autocomplete="off" aria-label="Search wholesale catalog" class="input pl-10 pr-9 text-xs sm:text-sm" @input.debounce.250ms="run()">
                                <span x-show="loading" x-cloak class="absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 animate-spin rounded-full border-2 border-line border-t-brand" aria-hidden="true"></span>
                            </div>
                            <select name="category" @change="run()" aria-label="Category" class="input sm:w-60 text-xs sm:text-sm font-medium">
                                <option value="">All Categories ({{ $products->count() }})</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->slug }}" @selected($categorySlug === $cat->slug)>{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </form>
                    </div>

                    <div id="reseller-results" class="mt-5" aria-live="polite">
                        {{-- CARD GRID VIEW (4 columns on desktop like main store, with full uncropped product image banner) --}}
                        <div x-show="viewMode === 'grid'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            @forelse ($products as $product)
                                @php
                                    $retail = (float) $product->price;
                                    $wholesale = (float) $product->priceFor($reseller);
                                    $saving = max(0, $retail - $wholesale);
                                    $savingPct = $retail > 0 && $saving > 0 ? round(($saving / $retail) * 100) : 0;
                                    $isInStock = $product->inStock();
                                    $walletBal = (float) $reseller->wallet_balance;
                                    $initials = \Illuminate\Support\Str::of($product->name)
                                        ->explode(' ')
                                        ->filter()
                                        ->take(2)
                                        ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
                                        ->implode('');
                                @endphp
                                <article x-show="!onlyInStock || {{ $isInStock ? 'true' : 'false' }}"
                                         x-data="{ qty: 1, max: {{ max(1, (int) $product->max_per_order) }}, unitPrice: {{ $wholesale }}, walletBal: {{ $walletBal }} }"
                                         class="group card flex flex-col justify-between overflow-hidden transition duration-150 hover:-translate-y-0.5 hover:border-brand/45 hover:shadow-md">
                                    <div>
                                        {{-- Full Uncropped Product Image Media Box (object-contain + ambient backdrop) --}}
                                        <a href="{{ route('products.show', $product) }}" class="surface-dark-solid relative block h-36 sm:h-40 overflow-hidden">
                                            @if ($img = $product->imageUrl())
                                                <img src="{{ $img }}" alt="" width="40" height="25" loading="lazy" decoding="async" aria-hidden="true" class="pointer-events-none absolute inset-0 h-full w-full object-cover scale-110 opacity-35 blur-md {{ ! $isInStock ? 'grayscale' : '' }}">
                                                <img src="{{ $img }}" alt="{{ $product->imageSeoAlt() }}" title="{{ $product->imageSeoTitle() }}" width="400" height="250" loading="lazy" decoding="async" class="relative z-10 h-full w-full object-contain p-1.5 transition duration-300 group-hover:scale-[1.03] {{ ! $isInStock ? 'grayscale opacity-65' : '' }}">
                                            @else
                                                <div class="surface-banner relative flex h-full w-full items-center gap-3 overflow-hidden px-4 text-white {{ ! $isInStock ? 'grayscale opacity-60' : '' }}">
                                                    <div class="pointer-events-none absolute inset-0 opacity-15 [background-image:radial-gradient(rgba(255,255,255,0.55)_1px,transparent_1px)] [background-size:14px_14px]"></div>
                                                    <div class="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-white/20 bg-white/10 shadow-inner backdrop-blur-xs">
                                                        <span class="display text-2xl tracking-wider text-white leading-none">{{ $initials }}</span>
                                                    </div>
                                                    <div class="relative min-w-0 flex-1">
                                                        <span class="block truncate text-[10px] font-bold tracking-widest text-white/70 uppercase">
                                                            {{ $product->category?->name ?? 'Digital Tool' }}
                                                        </span>
                                                        <span class="mt-0.5 block truncate text-[11px] font-medium text-white/80">
                                                            Wholesale Partner Access
                                                        </span>
                                                    </div>
                                                </div>
                                            @endif

                                            {{-- Top Overlay Badges (VIP Discount + Live Stock) --}}
                                            <div class="absolute inset-x-2.5 top-2.5 z-20 flex items-center justify-between gap-1.5">
                                                @if ($savingPct > 0)
                                                    <span class="rounded bg-emerald-600 px-2 py-0.5 text-[10px] font-bold tracking-wide text-white shadow-xs">
                                                        -{{ $savingPct }}% VIP
                                                    </span>
                                                @else
                                                    <span class="rounded bg-black/65 backdrop-blur-xs border border-white/15 px-2 py-0.5 text-[10px] font-semibold text-white/95">
                                                        Wholesale
                                                    </span>
                                                @endif

                                                <span class="inline-flex items-center gap-1 rounded-full bg-white/95 px-2.5 py-0.5 text-[10px] font-bold shadow-xs backdrop-blur-xs {{ $isInStock ? 'text-success' : 'text-danger' }}">
                                                    <span class="dot h-1.5 w-1.5 {{ $isInStock ? 'bg-success live-dot' : 'bg-danger' }}"></span>
                                                    <span>{{ ! $isInStock ? 'Sold Out' : ($product->stock_unlimited ? 'In Stock' : $product->available_stock.' in stock') }}</span>
                                                </span>
                                            </div>
                                        </a>

                                        {{-- Card Body --}}
                                        <div class="p-3.5 sm:p-4 pb-0">
                                            <span class="text-[11px] font-semibold text-muted">{{ $product->category?->name ?? 'Digital Tool' }}</span>
                                            <h2 class="mt-0.5 line-clamp-1 text-sm sm:text-[15px] font-bold text-ink leading-snug" title="{{ $product->name }}">
                                                <a href="{{ route('products.show', $product) }}" class="hover:text-brand">{{ $product->name }}</a>
                                            </h2>

                                            {{-- Wholesale vs Retail Price Comparison Box --}}
                                            <div class="mt-3 rounded-xl border border-line bg-grey-soft/80 p-3">
                                                <div class="flex items-baseline justify-between gap-2">
                                                    <div>
                                                        <span class="block text-[10px] font-bold uppercase tracking-wider text-muted">Your Wholesale Rate</span>
                                                        <div class="mt-0.5 flex items-baseline gap-1.5">
                                                            <span class="display text-2xl text-ink leading-none" x-text="$store.currency.format(unitPrice * qty)">
                                                                {{ money($wholesale) }}
                                                            </span>
                                                            @if ($saving > 0)
                                                                <span class="text-xs text-muted line-through" x-text="$store.currency.format({{ $retail }} * qty)">
                                                                    {{ money($retail) }}
                                                                </span>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    @if ($saving > 0)
                                                        <div class="text-right">
                                                            <span class="block text-[10px] font-bold uppercase tracking-wider text-emerald-700">Your Profit</span>
                                                            <span class="mt-0.5 inline-block rounded-md bg-emerald-100/80 px-2 py-0.5 text-[11px] font-bold text-emerald-800" x-text="'+' + $store.currency.format({{ $saving }} * qty)">
                                                                +{{ money($saving) }}
                                                            </span>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Quantity Selector & 1-Click Buy Actions --}}
                                    <div class="p-3.5 sm:p-4 pt-3 space-y-2">
                                        @if ($isInStock)
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="text-xs font-semibold text-muted">Quantity</span>
                                                <div class="inline-flex items-center rounded-lg border border-line-strong bg-white">
                                                    <button type="button" @click="qty = Math.max(1, qty - 1)" :disabled="qty <= 1" class="px-2.5 py-1 text-sm font-bold text-ink hover:bg-grey-soft disabled:opacity-30 cursor-pointer">−</button>
                                                    <span class="w-8 text-center text-xs font-bold text-ink" x-text="qty">1</span>
                                                    <button type="button" @click="qty = Math.min(max, qty + 1)" :disabled="qty >= max" class="px-2.5 py-1 text-sm font-bold text-ink hover:bg-grey-soft disabled:opacity-30 cursor-pointer">+</button>
                                                </div>
                                            </div>

                                            <template x-if="walletBal >= (unitPrice * qty)">
                                                <form method="post" action="{{ route('reseller.wallet-buy', ['slug' => $reseller->reseller_slug, 'product' => $product->slug]) }}"
                                                      @submit="return confirm('Instant buy ' + qty + 'x {{ addslashes($product->name) }} for ' + $store.currency.format(unitPrice * qty) + ' from your Reseller Wallet?')">
                                                    @csrf
                                                    <input type="hidden" name="quantity" :value="qty">
                                                    <button type="submit" class="btn btn-primary w-full py-2 text-xs font-bold shadow-xs">
                                                        <span>⚡ 1-Click Wallet Buy</span>
                                                        <span class="opacity-90" x-text="'(' + $store.currency.format(unitPrice * qty) + ')'">({{ money($wholesale) }})</span>
                                                    </button>
                                                </form>
                                            </template>

                                            <a :href="'{{ route('checkout.create', $product) }}?qty=' + qty"
                                               :class="walletBal >= (unitPrice * qty) ? 'btn btn-outline w-full py-1.5 text-[11px] font-semibold' : 'btn btn-primary w-full py-2 text-xs font-bold'"
                                               class="block text-center">
                                                <span x-show="walletBal >= (unitPrice * qty)">Or Checkout via Other Gateways →</span>
                                                <span x-show="walletBal < (unitPrice * qty)" x-cloak>
                                                    Buy at Wholesale (<span x-text="$store.currency.format(unitPrice * qty)">{{ money($wholesale) }}</span>) →
                                                </span>
                                            </a>
                                        @else
                                            <button disabled class="btn w-full bg-grey text-muted py-2 text-xs font-semibold cursor-not-allowed">
                                                Currently Out of Stock
                                            </button>
                                        @endif
                                    </div>
                                </article>
                            @empty
                                <div class="card col-span-full p-10 text-center text-muted">
                                    No wholesale products found matching your search filter.
                                </div>
                            @endforelse
                        </div>

                        {{-- FAST WHOLESALE TABLE VIEW --}}
                        <div x-show="viewMode === 'table'" x-cloak class="card overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="border-b border-line bg-grey-soft text-[11px] font-bold uppercase tracking-wider text-muted">
                                            <th class="py-3.5 pl-4 pr-3">Product / Tool</th>
                                            <th class="px-3 py-3.5">Retail Rate</th>
                                            <th class="px-3 py-3.5">Your Wholesale Rate</th>
                                            <th class="px-3 py-3.5">Your Margin</th>
                                            <th class="px-3 py-3.5">Live Stock</th>
                                            <th class="py-3.5 pl-3 pr-4 text-right">Instant Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-line text-xs sm:text-sm">
                                        @forelse ($products as $product)
                                            @php
                                                $retail = (float) $product->price;
                                                $wholesale = (float) $product->priceFor($reseller);
                                                $saving = max(0, $retail - $wholesale);
                                                $savingPct = $retail > 0 && $saving > 0 ? round(($saving / $retail) * 100) : 0;
                                                $isInStock = $product->inStock();
                                                $canWalletBuy = $isInStock && (float) $reseller->wallet_balance >= $wholesale;
                                                $initials = \Illuminate\Support\Str::of($product->name)
                                                    ->explode(' ')
                                                    ->filter()
                                                    ->take(2)
                                                    ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
                                                    ->implode('');
                                            @endphp
                                            <tr x-show="!onlyInStock || {{ $isInStock ? 'true' : 'false' }}" class="hover:bg-grey-soft/60 transition">
                                                <td class="py-3.5 pl-4 pr-3">
                                                    <div class="flex items-center gap-3">
                                                        @if ($img = $product->imageUrl())
                                                            <div class="surface-dark-solid h-10 w-16 shrink-0 overflow-hidden rounded-lg border border-line p-0.5">
                                                                <img src="{{ $img }}" alt="{{ $product->imageSeoAlt() }}" width="64" height="40" loading="lazy" class="h-full w-full object-contain">
                                                            </div>
                                                        @else
                                                            <div class="surface-banner flex h-10 w-16 shrink-0 items-center justify-center rounded-lg border border-line text-white">
                                                                <span class="display text-sm tracking-wider">{{ $initials }}</span>
                                                            </div>
                                                        @endif
                                                        <div>
                                                            <a href="{{ route('products.show', $product) }}" class="font-bold text-ink hover:text-brand">{{ $product->name }}</a>
                                                            <span class="block text-[11px] text-muted">{{ $product->category?->name ?? 'Digital Tool' }}</span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-3 py-3.5 text-muted line-through whitespace-nowrap" x-text="$store.currency.format({{ $retail }})">
                                                    {{ money($retail) }}
                                                </td>
                                                <td class="px-3 py-3.5 font-bold text-ink whitespace-nowrap" x-text="$store.currency.format({{ $wholesale }})">
                                                    {{ money($wholesale) }}
                                                </td>
                                                <td class="px-3 py-3.5 whitespace-nowrap">
                                                    @if ($saving > 0)
                                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 text-xs font-bold text-emerald-700">
                                                            +<span x-text="$store.currency.format({{ $saving }})">{{ money($saving) }}</span> ({{ $savingPct }}%)
                                                        </span>
                                                    @else
                                                        <span class="text-xs text-muted">Standard</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-3.5 whitespace-nowrap">
                                                    @if ($isInStock)
                                                        <span class="badge badge-success text-[10px]">
                                                            <span class="dot bg-success"></span>
                                                            {{ $product->stock_unlimited ? 'Available' : $product->available_stock.' left' }}
                                                        </span>
                                                    @else
                                                        <span class="badge badge-danger text-[10px]">Sold Out</span>
                                                    @endif
                                                </td>
                                                <td class="py-3.5 pl-3 pr-4 text-right whitespace-nowrap">
                                                    @if ($isInStock)
                                                        <div class="inline-flex items-center justify-end gap-1.5">
                                                            @if ($canWalletBuy)
                                                                <form method="post" action="{{ route('reseller.wallet-buy', ['slug' => $reseller->reseller_slug, 'product' => $product->slug]) }}" onsubmit="return confirm('Instant buy 1x {{ addslashes($product->name) }} for {{ money($wholesale) }} from your Reseller Wallet?')">
                                                                    @csrf
                                                                    <input type="hidden" name="quantity" value="1">
                                                                    <button type="submit" class="btn btn-primary px-3 py-1.5 text-xs font-bold">
                                                                        ⚡ 1-Click Wallet
                                                                    </button>
                                                                </form>
                                                            @endif
                                                            <a href="{{ route('checkout.create', $product) }}" class="btn btn-outline px-3 py-1.5 text-xs font-semibold">
                                                                Checkout →
                                                            </a>
                                                        </div>
                                                    @else
                                                        <span class="text-xs font-semibold text-muted">Out of Stock</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="6" class="p-8 text-center text-muted">No products found.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- TAB 2: DELIVERED ORDERS & CREDENTIALS VAULT --}}
                <div x-show="tab === 'orders'" x-cloak class="mt-4 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="display text-2xl sm:text-3xl text-ink">Recent Reseller Orders &amp; Delivered Keys</h2>
                            <p class="text-xs sm:text-sm text-muted">Copy delivered customer credentials in 1 click or open the full order receipt.</p>
                        </div>
                        <a href="{{ route('account.orders') }}" class="btn btn-outline text-xs font-bold">
                            Open Full Order Vault &amp; Date Filter →
                        </a>
                    </div>

                    @forelse ($orders as $order)
                        <div class="card p-4 sm:p-5 space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2.5">
                                    <a href="{{ $order->customerUrl() }}" class="font-mono text-sm font-bold text-ink hover:text-brand underline">
                                        {{ $order->number }}
                                    </a>
                                    <span class="badge {{ $order->status === OrderStatus::Completed ? 'badge-success' : 'badge-brand' }} text-[11px]">
                                        {{ $order->status->label() }}
                                    </span>
                                    <span class="text-xs text-muted">{{ $order->created_at->diffForHumans() }}</span>
                                    @if ($order->paymentMethod)
                                        <span class="rounded bg-grey-soft border border-line px-2 py-0.5 text-[11px] font-medium text-slate">
                                            {{ $order->paymentMethod->name }}
                                        </span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="font-bold text-ink text-sm" x-text="$store.currency.format({{ (float) $order->total }})">{{ money($order->total) }}</span>
                                    <a href="{{ $order->customerUrl() }}" class="btn btn-outline px-3 py-1.5 text-xs font-semibold">Open Order →</a>
                                </div>
                            </div>

                            @foreach ($order->items as $item)
                                @if ($item->deliveries->isNotEmpty())
                                    <div class="space-y-2 pt-1">
                                        @foreach ($item->deliveries as $delivery)
                                            <div class="surface-dark-solid flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 rounded-xl px-4 py-3 text-white" x-data="copy(@js(trim($delivery->content)))">
                                                <div class="min-w-0">
                                                    <span class="block text-[10px] font-bold uppercase tracking-wider text-white/60">{{ $item->product_name }}</span>
                                                    <code class="mt-0.5 block font-mono text-xs sm:text-sm text-[#4ade80] break-all whitespace-pre-wrap">{{ trim($delivery->content) }}</code>
                                                </div>
                                                <button type="button" @click="copy" class="shrink-0 rounded-lg bg-white/15 hover:bg-white/25 px-3 py-1.5 text-xs font-bold text-white transition cursor-pointer" x-text="copied ? '✓ Copied!' : '📋 Copy Credentials'"></button>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @empty
                        <div class="card p-10 text-center space-y-3">
                            <p class="text-sm font-semibold text-ink">No reseller orders placed yet.</p>
                            <p class="text-xs text-muted">Select any tool from the Wholesale Catalog tab to place your first 1-click reseller order.</p>
                            <button type="button" @click="tab = 'catalog'" class="btn btn-primary text-xs">Browse Wholesale Catalog →</button>
                        </div>
                    @endforelse
                </div>

                {{-- TAB 3: WALLET LEDGER --}}
                <div x-show="tab === 'wallet'" x-cloak class="mt-4 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="display text-2xl sm:text-3xl text-ink">Reseller Wallet Ledger</h2>
                            <p class="text-xs sm:text-sm text-muted">Recent wallet top-ups, bonuses, and 1-click wholesale order deductions.</p>
                        </div>
                        @if ($waAdmin !== '')
                            <a href="https://wa.me/{{ $waAdmin }}?text={{ urlencode('Assalam o Alaikum! I want to add funds to my Reseller Wallet (@'.$reseller->reseller_slug.' / '.$reseller->email.')') }}"
                               target="_blank"
                               rel="noopener"
                               class="btn bg-[#25D366] text-white hover:bg-[#1ebe5b] px-4 py-2 text-xs font-bold">
                                ➕ Top-Up Wallet via WhatsApp
                            </a>
                        @endif
                    </div>

                    <div class="card overflow-hidden">
                        @if ($walletTransactions->isNotEmpty())
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="border-b border-line bg-grey-soft text-[11px] font-bold uppercase tracking-wider text-muted">
                                            <th class="py-3 pl-4 pr-3">Date</th>
                                            <th class="px-3 py-3">Description</th>
                                            <th class="px-3 py-3 text-right">Amount</th>
                                            <th class="py-3 pl-3 pr-4 text-right">Balance After</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-line text-xs sm:text-sm">
                                        @foreach ($walletTransactions as $tx)
                                            @php $isCredit = (float) $tx->amount >= 0; @endphp
                                            <tr class="hover:bg-grey-soft/60">
                                                <td class="py-3 pl-4 pr-3 text-muted whitespace-nowrap">
                                                    {{ $tx->created_at?->format('d M Y, h:i A') }}
                                                </td>
                                                <td class="px-3 py-3 font-medium text-ink">
                                                    {{ $tx->description ?: ($isCredit ? 'Wallet Top-Up' : 'Wholesale Order Payment') }}
                                                </td>
                                                <td class="px-3 py-3 text-right font-bold whitespace-nowrap {{ $isCredit ? 'text-success' : 'text-ink' }}">
                                                    {{ $isCredit ? '+' : '' }}{{ money($tx->amount) }}
                                                </td>
                                                <td class="py-3 pl-3 pr-4 text-right font-mono text-xs text-muted whitespace-nowrap">
                                                    {{ money($tx->balance_after) }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="p-10 text-center space-y-3">
                                <p class="text-sm font-semibold text-ink">No wallet transactions recorded yet.</p>
                                <p class="text-xs text-muted">Top up your Reseller Wallet via WhatsApp to unlock instant 1-click order delivery without waiting for manual verification.</p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Always-Visible Recent Delivered Credentials Preview Below Catalog --}}
                @if ($orders->isNotEmpty())
                    <div x-show="tab === 'catalog'" class="mt-10 border-t border-line pt-8">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="display text-2xl sm:text-3xl text-ink">Latest Delivered Orders</h2>
                                <p class="text-xs text-muted">Quick access to your most recent wholesale purchases</p>
                            </div>
                            <button type="button" @click="tab = 'orders'" class="text-xs sm:text-sm font-bold text-brand hover:underline cursor-pointer">
                                View All {{ $orders->count() }} Recent Orders →
                            </button>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach ($orders->take(4) as $order)
                                <div class="card p-4 flex flex-col justify-between gap-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-2">
                                            <a href="{{ $order->customerUrl() }}" class="font-mono text-xs sm:text-sm font-bold text-ink hover:text-brand underline">{{ $order->number }}</a>
                                            <span class="badge {{ $order->status === OrderStatus::Completed ? 'badge-success' : 'badge-brand' }} text-[10px]">{{ $order->status->label() }}</span>
                                        </div>
                                        <span class="text-xs font-bold text-ink" x-text="$store.currency.format({{ (float) $order->total }})">{{ money($order->total) }}</span>
                                    </div>

                                    @foreach ($order->items as $item)
                                        @foreach ($item->deliveries as $delivery)
                                            <div class="surface-dark-solid flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-white" x-data="copy(@js(trim($delivery->content)))">
                                                <code class="font-mono text-xs text-[#4ade80] truncate">{{ $item->product_name }}: {{ trim($delivery->content) }}</code>
                                                <button type="button" @click="copy" class="shrink-0 rounded bg-white/15 hover:bg-white/25 px-2.5 py-1 text-[10px] font-bold text-white cursor-pointer" x-text="copied ? 'Copied!' : 'Copy'"></button>
                                            </div>
                                        @endforeach
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

            </div>
        @endif
    </div>
</x-layouts.store>
