@php
    $storeName = (string) setting('store_name', 'SellMeTools');
    $waNumber = admin_whatsapp_number();

    $seoTitle = $activeCategory
        ? $activeCategory->seoTitle()
        : ($search !== '' ? "Search Results for “{$search}” in Shop" : 'Shop All AI Tools & Digital Subscriptions in Pakistan');
    $seoDescription = $activeCategory
        ? $activeCategory->seoDescription()
        : 'Browse our complete catalog of AI tools, design software, streaming accounts and cloud subscriptions in Pakistan. Compare live PKR prices and pay with Easypaisa, JazzCash, NayaPay or Binance.';
    $canonicalUrl = $activeCategory
        ? route('categories.show', $activeCategory)
        : route('shop');
    $robotsDirective = ($search !== '' || $sort !== 'featured' || $inStockOnly)
        ? 'noindex, follow'
        : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';

    $itemListSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => $activeCategory ? $activeCategory->name : 'All Digital Tools & Subscriptions in Pakistan',
        'itemListElement' => $products->getCollection()->values()->map(fn ($p, $idx) => [
            '@type' => 'ListItem',
            'position' => $idx + 1,
            'url' => route('products.show', $p),
            'name' => $p->name,
        ])->all(),
    ];
@endphp
@push('seo')
    <script type="application/ld+json">{!! json_encode($itemListSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush
<x-layouts.store
    :title="$seoTitle"
    :description="$seoDescription"
    :canonical="$canonicalUrl"
    :robots="$robotsDirective"
>
    {{-- ================= SHOP HEADER ================= --}}
    <section class="relative overflow-hidden bg-[#0B0D10] text-white py-10 sm:py-14">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(255,255,255,.08),transparent_55%),radial-gradient(ellipse_at_bottom_left,rgba(148,163,184,.10),transparent_50%)]" aria-hidden="true"></div>
        <div class="container-x relative">
            <nav class="text-xs text-white/60 mb-3" aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-2">
                    <li><a href="{{ route('home') }}" class="hover:text-white transition">Home</a></li>
                    <li aria-hidden="true">/</li>
                    @if ($activeCategory)
                        <li><a href="{{ route('shop') }}" class="hover:text-white transition">Shop</a></li>
                        <li aria-hidden="true">/</li>
                        <li class="text-white font-semibold">{{ $activeCategory->name }}</li>
                    @else
                        <li class="text-white font-semibold">Shop All Products</li>
                    @endif
                </ol>
            </nav>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <span class="eyebrow text-white/75 before:bg-white/60">{{ $activeCategory ? 'Category Catalog' : 'Complete Store Catalog' }}</span>
                    <h1 class="display mt-1.5 text-3xl sm:text-5xl lg:text-6xl text-white">
                        @if ($search !== '')
                            Search Results for “{{ $search }}”
                        @elseif ($activeCategory)
                            {{ $activeCategory->name }} in Pakistan
                        @else
                            All Digital Tools &amp; Subscriptions
                        @endif
                    </h1>
                    <p class="mt-2 max-w-2xl text-xs sm:text-sm leading-relaxed text-white/75">
                        @if ($activeCategory)
                            {{ $activeCategory->seoDescription() }}
                        @else
                            Compare live PKR prices and stock across all AI tools, creative software, cloud accounts and digital subscriptions. Instant delivery on your private order page after verification.
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2.5">
                    <span class="inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-3.5 py-2 text-xs font-semibold text-white">
                        <span class="h-2 w-2 rounded-full bg-[#22c55e] live-dot"></span>
                        {{ $products->total() }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }} available
                    </span>
                    @if ($waNumber !== '')
                        <a href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener" class="btn border border-white/25 text-white hover:bg-white/10 px-4 py-2 text-xs font-semibold">
                            Ask on WhatsApp
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- ================= CATALOG FILTER & GRID ================= --}}
    <section id="products" class="container-x py-8 sm:py-12">
        <div class="card p-3.5 sm:p-4">
            <div class="flex flex-col gap-3.5 lg:flex-row lg:items-center lg:justify-between">
                {{-- Category Pills --}}
                <nav class="-mx-1 flex items-center gap-1.5 overflow-x-auto px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Filter by category">
                    <a href="{{ route('shop', array_filter(['q' => $search ?: null, 'sort' => $sort !== 'featured' ? $sort : null, 'in_stock' => $inStockOnly ? 1 : null])) }}"
                       @class([
                           'shrink-0 rounded-full border px-3.5 py-1.5 text-xs sm:text-sm font-semibold transition',
                           'border-brand bg-brand text-white' => ! $activeCategory,
                           'border-line-strong bg-white text-ink hover:border-brand hover:text-brand' => $activeCategory,
                       ])>
                        All Categories
                    </a>
                    @foreach ($categories as $category)
                        <a href="{{ route('shop', array_filter(['category' => $category->slug, 'q' => $search ?: null, 'sort' => $sort !== 'featured' ? $sort : null, 'in_stock' => $inStockOnly ? 1 : null])) }}"
                           @class([
                               'shrink-0 inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs sm:text-sm font-semibold transition whitespace-nowrap',
                               'border-brand bg-brand text-white' => $activeCategory?->is($category),
                               'border-line-strong bg-white text-ink hover:border-brand hover:text-brand' => ! $activeCategory?->is($category),
                           ])>
                            @if ($category->icon)
                                <span>{{ $category->icon }}</span>
                            @endif
                            <span>{{ $category->name }}</span>
                            @if (isset($category->products_count))
                                <span @class([
                                    'rounded-full px-1.5 py-0.2 text-[10px] font-bold',
                                    'bg-white/20 text-white' => $activeCategory?->is($category),
                                    'bg-grey-soft text-muted' => ! $activeCategory?->is($category),
                                ])>{{ $category->products_count }}</span>
                            @endif
                        </a>
                    @endforeach
                </nav>

                {{-- Search + Sort Form --}}
                <form action="{{ route('shop') }}" method="get" class="flex flex-col gap-2.5 sm:flex-row sm:items-center lg:shrink-0" role="search"
                      x-data="liveFilter('#shop-results')" @submit.prevent="run()">
                    @if ($activeCategory)
                        <input type="hidden" name="category" value="{{ $activeCategory->slug }}">
                    @endif

                    <div class="relative w-full sm:w-64">
                        <label for="shop-search" class="sr-only">Search products in shop</label>
                        <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input id="shop-search" type="search" name="q" value="{{ $search }}" placeholder="Filter tools as you type..." autocomplete="off" class="input py-2 pl-10 pr-8 text-sm" @input.debounce.250ms="run()">
                        <span x-show="loading" x-cloak class="absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 animate-spin rounded-full border-2 border-line border-t-brand" aria-hidden="true"></span>
                    </div>

                    <div class="flex items-center gap-2">
                        <label for="shop-sort" class="sr-only">Sort products</label>
                        <select id="shop-sort" name="sort" class="input py-2 pr-8 text-xs sm:text-sm font-medium w-full sm:w-auto" onchange="this.form.submit()">
                            <option value="featured" @selected($sort === 'featured')>Sort: Featured</option>
                            <option value="popular" @selected($sort === 'popular')>Sort: Best Selling</option>
                            <option value="price_asc" @selected($sort === 'price_asc')>Price: Low to High</option>
                            <option value="price_desc" @selected($sort === 'price_desc')>Price: High to Low</option>
                            <option value="newest" @selected($sort === 'newest')>Sort: Newest</option>
                        </select>

                        <label class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-line-strong bg-white px-3 py-2 text-xs font-semibold text-ink hover:border-brand">
                            <input type="checkbox" name="in_stock" value="1" @checked($inStockOnly) onchange="this.form.submit()" class="h-3.5 w-3.5 rounded border-line-strong text-brand focus:ring-brand">
                            <span>In Stock</span>
                        </label>
                    </div>
                </form>
            </div>

            @if ($search !== '' || $activeCategory || $sort !== 'featured' || $inStockOnly)
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-2.5 text-xs text-muted">
                    <span>
                        Showing <strong>{{ $products->total() }}</strong> {{ \Illuminate\Support\Str::plural('result', $products->total()) }}
                        @if ($activeCategory) in <strong>{{ $activeCategory->name }}</strong> @endif
                        @if ($search !== '') matching <strong>“{{ $search }}”</strong> @endif
                    </span>
                    <a href="{{ route('shop') }}" class="font-bold text-brand hover:underline">Reset all filters ×</a>
                </div>
            @endif
        </div>

        <div id="shop-results" aria-live="polite">
            @if ($products->isEmpty())
                <div class="card mt-6 p-10 text-center sm:p-14">
                    <p class="display text-3xl text-ink">No matching products found</p>
                    <p class="mt-2 text-sm text-muted">Try clearing your search filter or switching to another category.</p>
                    <a href="{{ route('shop') }}" class="btn btn-primary mt-5">Show All Products</a>
                </div>
            @else
                <div class="mt-6 grid grid-cols-2 gap-3.5 sm:gap-5 md:grid-cols-3 xl:grid-cols-4">
                    @foreach ($products as $product)
                        <x-product-card :product="$product" />
                    @endforeach
                </div>
                <div class="mt-10">{{ $products->links() }}</div>
            @endif
        </div>
    </section>

    {{-- ================= SHOP SEO & WARRANTY GUIDE ================= --}}
    <section class="border-t border-line bg-white py-12 sm:py-16" aria-labelledby="shop-seo-heading">
        <div class="container-x">
            <div class="grid gap-8 lg:grid-cols-3">
                <div class="card p-6 bg-grey-soft/60">
                    <span class="eyebrow">100% Local Checkout</span>
                    <h2 id="shop-seo-heading" class="display mt-1 text-2xl sm:text-3xl text-ink">Pay in PKR without a credit card</h2>
                    <p class="mt-2.5 text-xs sm:text-sm leading-relaxed text-body">
                        Every subscription in our catalog can be purchased in Pakistani Rupees using <strong>Easypaisa, JazzCash, NayaPay, SadaPay, Raast / Bank Transfer</strong> or <strong>Binance USDT</strong>. No dollar card or international bank tax is required.
                    </p>
                </div>

                <div class="card p-6 bg-grey-soft/60">
                    <span class="eyebrow">Instant Access</span>
                    <h3 class="display mt-1 text-2xl sm:text-3xl text-ink">Automated Order Page Delivery</h3>
                    <p class="mt-2.5 text-xs sm:text-sm leading-relaxed text-body">
                        Stock is reserved the moment you start checkout. Once your Transaction ID (TID) is verified, your login credentials, invite link or activation key appear directly on your private order page and customer dashboard.
                    </p>
                </div>

                <div class="card p-6 bg-grey-soft/60">
                    <span class="eyebrow">Transparent Terms</span>
                    <h3 class="display mt-1 text-2xl sm:text-3xl text-ink">Clear Warranty on Every Tool</h3>
                    <p class="mt-2.5 text-xs sm:text-sm leading-relaxed text-body">
                        Whether you choose a <strong>Full Warranty (FW)</strong> monthly subscription or a <strong>Login Warranty</strong> bulk account, every product page lists exact coverage rules upfront backed by our WhatsApp support desk.
                    </p>
                </div>
            </div>
        </div>
    </section>
</x-layouts.store>

