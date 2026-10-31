<x-layouts.store :title="$activeCategory?->name ?? ($search ? 'Search: '.$search : null)">
    <section id="products" class="container-x py-6 sm:py-10">
        {{-- Top Filter Bar: Categories + Search --}}
        <div class="card mb-6 flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="-mx-1 flex items-center gap-2 overflow-x-auto px-1 pb-1 sm:flex-wrap sm:pb-0" aria-label="Categories">
                <a href="{{ route('home') }}" @class(['btn shrink-0 rounded-full px-4 py-2 text-xs sm:text-sm', 'btn-primary' => ! $activeCategory, 'btn-outline' => $activeCategory])>
                    All Products
                </a>
                @foreach ($categories as $category)
                    <a href="{{ route('home', ['category' => $category->slug]) }}" @class(['btn shrink-0 rounded-full px-4 py-2 text-xs sm:text-sm', 'btn-primary' => $activeCategory?->is($category), 'btn-outline' => ! $activeCategory?->is($category)])>
                        {{ $category->name }}
                    </a>
                @endforeach
            </div>

            <form action="{{ route('home') }}" method="get" class="w-full sm:w-72" role="search">
                @if ($activeCategory)
                    <input type="hidden" name="category" value="{{ $activeCategory->slug }}">
                @endif
                <div class="relative">
                    <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" name="q" value="{{ $search }}" placeholder="Search tools..." class="input pl-10">
                </div>
            </form>
        </div>

        @if ($search || $activeCategory)
            <div class="mb-5 flex items-center justify-between">
                <h1 class="text-lg font-bold text-ink">
                    @if ($search) Search: “{{ $search }}” @else {{ $activeCategory->name }} @endif
                </h1>
                <a href="{{ route('home') }}" class="text-sm font-semibold text-brand hover:underline">Clear filter</a>
            </div>
        @endif

        @if ($products->isEmpty())
            <div class="card p-12 text-center">
                <p class="display text-3xl">No products found</p>
                <p class="mt-2 text-sm">Check back soon or clear your search filter.</p>
                @if ($search || $activeCategory)
                    <a href="{{ route('home') }}" class="btn btn-primary mt-5">Show all products</a>
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
