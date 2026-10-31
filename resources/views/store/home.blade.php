<x-layouts.store :title="$activeCategory?->name ?? ($search ? 'Search: '.$search : null)">
    @if (! $search && ! $activeCategory && $products->onFirstPage())
        {{-- Modern Storefront Hero --}}
        <section class="relative overflow-hidden border-b border-line bg-gradient-to-br from-[#120408] via-[#2B0912] to-[#5E1021] text-white">
            <div class="pointer-events-none absolute -top-32 right-0 h-[420px] w-[420px] rounded-full bg-brand/35 blur-3xl" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-28 -left-20 h-80 w-80 rounded-full bg-[#ff4d6d]/20 blur-3xl" aria-hidden="true"></div>

            <div class="container-x relative py-10 sm:py-14 lg:py-18">
                <div class="grid items-center gap-8 lg:grid-cols-[1.25fr_0.75fr]">
                    <div>
                        <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3.5 py-1 text-xs font-semibold tracking-wider text-brand-tint-2 uppercase backdrop-blur">
                            <span class="h-2 w-2 rounded-full bg-[#22c55e] live-dot"></span>
                            Pakistan's #1 Digital Subscriptions Store
                        </div>

                        <h1 class="display mt-4 text-[clamp(38px,6vw,74px)] leading-[0.95] text-white">
                            Buy Premium AI Tools &amp; <span class="text-[#ff6b81]">Subscriptions</span> Instantly
                        </h1>

                        <p class="mt-3.5 max-w-xl text-sm leading-relaxed text-white/80 sm:text-base">
                            ChatGPT Plus, CapCut Pro, Canva, Cursor, VPNs &amp; Cloud tools at wholesale rates. Pay with Easypaisa, JazzCash, NayaPay, SadaPay or Binance.
                        </p>

                        {{-- Hero Search Bar --}}
                        <form action="{{ route('home') }}#products" method="get" class="mt-6 max-w-xl" role="search">
                            <div class="flex items-center rounded-xl bg-white p-1.5 shadow-2xl">
                                <svg class="ml-3 h-5 w-5 shrink-0 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                                <input type="search" name="q" value="{{ $search }}" placeholder="Search ChatGPT, CapCut, Canva, LinkedIn..." class="w-full border-0 bg-transparent px-3 py-2 text-sm text-ink placeholder:text-muted focus:ring-0 focus:outline-none">
                                <button type="submit" class="btn btn-primary shrink-0 rounded-lg px-5 py-2.5 text-sm">Search</button>
                            </div>
                        </form>

                        @if ($categories->isNotEmpty())
                            <div class="mt-4 flex flex-wrap items-center gap-1.5 sm:gap-2">
                                <span class="text-xs text-white/60">Categories:</span>
                                @foreach ($categories->take(6) as $cat)
                                    <a href="{{ route('home', ['category' => $cat->slug]) }}#products" class="rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-medium text-white/95 transition hover:border-white/40 hover:bg-white/20">
                                        {{ $cat->name }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Right Spotlight Card --}}
                    <div class="hidden lg:block">
                        <div class="rounded-2xl border border-white/15 bg-white/10 p-6 backdrop-blur-md shadow-2xl">
                            <div class="flex items-center justify-between border-b border-white/10 pb-4">
                                <div>
                                    <p class="text-xs font-semibold tracking-wider text-[#ff8fa3] uppercase">Verified Store</p>
                                    <p class="mt-0.5 text-lg font-bold text-white">Why Creators Choose Us</p>
                                </div>
                                <span class="rounded-lg bg-amber-400/20 px-3 py-1 text-sm font-bold text-amber-300">★ 4.9 / 5</span>
                            </div>
                            <div class="mt-4 grid grid-cols-2 gap-3 text-xs">
                                <div class="rounded-xl bg-white/10 p-3.5">
                                    <p class="display text-2xl text-white">10 SEC</p>
                                    <p class="mt-0.5 text-white/75">Live Stock &amp; Price Sync</p>
                                </div>
                                <div class="rounded-xl bg-white/10 p-3.5">
                                    <p class="display text-2xl text-[#4ade80]">INSTANT</p>
                                    <p class="mt-0.5 text-white/75">Auto Credentials Delivery</p>
                                </div>
                                <div class="rounded-xl bg-white/10 p-3.5">
                                    <p class="display text-2xl text-white">LOCAL PAY</p>
                                    <p class="mt-0.5 text-white/75">Easypaisa · JazzCash · USDT</p>
                                </div>
                                <div class="rounded-xl bg-white/10 p-3.5">
                                    <p class="display text-2xl text-[#ff8fa3]">HISTORY</p>
                                    <p class="mt-0.5 text-white/75">Saved Forever in Account</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endif

    <section id="products" class="container-x scroll-mt-20 py-6 sm:py-10">
        {{-- Category Filter Bar + Search --}}
        <div class="card mb-6 flex flex-col gap-3 p-3.5 sm:flex-row sm:items-center sm:justify-between sm:p-4">
            <div class="-mx-1 flex items-center gap-1.5 overflow-x-auto px-1 pb-1 sm:flex-wrap sm:gap-2 sm:pb-0" aria-label="Categories">
                <a href="{{ route('home') }}#products" @class(['btn shrink-0 rounded-full px-3.5 py-1.5 text-xs sm:px-4 sm:py-2 sm:text-sm', 'btn-primary' => ! $activeCategory, 'btn-outline' => $activeCategory])>
                    All Products
                </a>
                @foreach ($categories as $category)
                    <a href="{{ route('home', ['category' => $category->slug]) }}#products" @class(['btn shrink-0 rounded-full px-3.5 py-1.5 text-xs sm:px-4 sm:py-2 sm:text-sm', 'btn-primary' => $activeCategory?->is($category), 'btn-outline' => ! $activeCategory?->is($category)])>
                        {{ $category->name }}
                    </a>
                @endforeach
            </div>

            <form action="{{ route('home') }}#products" method="get" class="w-full sm:w-64" role="search">
                @if ($activeCategory)
                    <input type="hidden" name="category" value="{{ $activeCategory->slug }}">
                @endif
                <div class="relative">
                    <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" name="q" value="{{ $search }}" placeholder="Filter tools..." class="input py-2 pl-10 text-xs sm:text-sm">
                </div>
            </form>
        </div>

        @if ($search || $activeCategory)
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <span class="eyebrow">Catalog</span>
                    <h1 class="display mt-1 text-3xl sm:text-4xl">
                        @if ($search) Search: “{{ $search }}” @else {{ $activeCategory->name }} @endif
                    </h1>
                </div>
                <a href="{{ route('home') }}#products" class="btn btn-outline px-3 py-1.5 text-xs">Show All</a>
            </div>
        @endif

        @if ($products->isEmpty())
            <div class="card p-12 text-center">
                <p class="display text-3xl">No products found</p>
                <p class="mt-2 text-sm">Check back soon or clear your filter.</p>
                @if ($search || $activeCategory)
                    <a href="{{ route('home') }}" class="btn btn-primary mt-5">View all products</a>
                @endif
            </div>
        @else
            {{-- 2 columns on mobile, 3 on tablet/laptop, 4 on desktop --}}
            <div class="grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
            <div class="mt-10">{{ $products->links() }}</div>
        @endif
    </section>
</x-layouts.store>
