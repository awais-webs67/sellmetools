<x-layouts.store :title="$activeCategory?->name ?? ($search ? 'Search: '.$search : null)">
    @if (! $search && ! $activeCategory && $products->onFirstPage())
        {{-- Premium Storefront Hero --}}
        <section class="relative overflow-hidden border-b border-line bg-gradient-to-br from-ink via-[#220910] to-[#4A0D1A] text-white">
            <div class="pointer-events-none absolute -top-28 -right-28 h-96 w-96 rounded-full bg-brand/30 blur-3xl" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-24 -left-24 h-80 w-80 rounded-full bg-brand/20 blur-3xl" aria-hidden="true"></div>

            <div class="container-x relative py-12 sm:py-16 lg:py-20">
                <div class="mx-auto max-w-3xl text-center">
                    <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3.5 py-1 text-xs font-semibold tracking-wider text-brand-tint-2 uppercase backdrop-blur">
                        <span class="h-2 w-2 rounded-full bg-[#22c55e]"></span>
                        Official Digital Store
                    </span>

                    <h1 class="display mt-5 text-[clamp(42px,6.5vw,76px)] leading-[0.96] text-white">
                        Premium AI Tools &amp; <span class="text-[#ff6b81]">Subscriptions</span>
                    </h1>

                    <p class="mx-auto mt-4 max-w-xl text-sm leading-relaxed text-white/80 sm:text-base">
                        Get authentic subscriptions, creative suites, and developer tools at wholesale prices in Pakistan. Pay easily via Easypaisa, JazzCash, NayaPay, SadaPay or Binance.
                    </p>

                    {{-- Hero Search Bar --}}
                    <form action="{{ route('home') }}#products" method="get" class="mx-auto mt-7 max-w-xl" role="search">
                        <div class="flex items-center rounded-xl bg-white p-1.5 shadow-2xl">
                            <svg class="ml-3 h-5 w-5 shrink-0 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                            <input type="search" name="q" value="{{ $search }}" placeholder="Search ChatGPT Plus, CapCut Pro, Canva, Netflix..." class="w-full border-0 bg-transparent px-3 py-2 text-sm text-ink placeholder:text-muted focus:ring-0 focus:outline-none">
                            <button type="submit" class="btn btn-primary shrink-0 rounded-lg px-5 py-2.5 text-sm">Search</button>
                        </div>
                    </form>

                    @if ($categories->isNotEmpty())
                        <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                            <span class="text-xs text-white/60">Popular:</span>
                            @foreach ($categories->take(5) as $cat)
                                <a href="{{ route('home', ['category' => $cat->slug]) }}#products" class="rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-white/90 transition hover:border-white/40 hover:bg-white/15">
                                    {{ $cat->name }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif

    <section id="products" class="container-x scroll-mt-20 py-8 sm:py-12">
        {{-- Category Filter Bar + Search --}}
        <div class="card mb-7 flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="-mx-1 flex items-center gap-2 overflow-x-auto px-1 pb-1 sm:flex-wrap sm:pb-0" aria-label="Categories">
                <a href="{{ route('home') }}#products" @class(['btn shrink-0 rounded-full px-4 py-2 text-xs sm:text-sm', 'btn-primary' => ! $activeCategory, 'btn-outline' => $activeCategory])>
                    All Products
                </a>
                @foreach ($categories as $category)
                    <a href="{{ route('home', ['category' => $category->slug]) }}#products" @class(['btn shrink-0 rounded-full px-4 py-2 text-xs sm:text-sm', 'btn-primary' => $activeCategory?->is($category), 'btn-outline' => ! $activeCategory?->is($category)])>
                        {{ $category->name }}
                    </a>
                @endforeach
            </div>

            <form action="{{ route('home') }}#products" method="get" class="w-full sm:w-72" role="search">
                @if ($activeCategory)
                    <input type="hidden" name="category" value="{{ $activeCategory->slug }}">
                @endif
                <div class="relative">
                    <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" name="q" value="{{ $search }}" placeholder="Filter products..." class="input pl-10">
                </div>
            </form>
        </div>

        @if ($search || $activeCategory)
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <span class="eyebrow">Catalog</span>
                    <h1 class="display mt-1 text-4xl">
                        @if ($search) Search: “{{ $search }}” @else {{ $activeCategory->name }} @endif
                    </h1>
                </div>
                <a href="{{ route('home') }}#products" class="btn btn-outline px-3.5 py-2 text-xs">Show All</a>
            </div>
        @else
            <div class="mb-6 flex items-end justify-between">
                <div>
                    <span class="eyebrow">Featured Catalog</span>
                    <h2 class="display mt-1 text-4xl sm:text-5xl">Available Tools &amp; Plans</h2>
                </div>
                <span class="hidden text-xs font-medium text-muted sm:inline-flex sm:items-center sm:gap-1.5">
                    <span class="dot live-dot bg-success"></span> Live stock
                </span>
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
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
            <div class="mt-10">{{ $products->links() }}</div>
        @endif
    </section>
</x-layouts.store>
