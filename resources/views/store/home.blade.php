<x-layouts.store :title="$activeCategory?->name ?? ($search ? 'Search: '.$search : null)">
    @if (! $search && ! $activeCategory && $products->onFirstPage())
        {{-- Hero --}}
        <section class="relative overflow-hidden border-b border-line bg-grey-soft">
            <div class="pointer-events-none absolute -top-24 -right-24 h-96 w-96 rounded-full bg-brand/10 blur-3xl" aria-hidden="true"></div>
            <div class="container-x relative grid items-center gap-10 py-14 lg:grid-cols-[1.2fr_1fr] lg:py-20">
                <div>
                    <span class="eyebrow">Instant digital delivery</span>
                    <h1 class="display mt-4 text-[clamp(46px,7vw,92px)]">Premium tools.<br><span class="text-brand">Delivered in minutes.</span></h1>
                    <p class="mt-5 max-w-xl text-lg">{{ setting('store_tagline') }} AI tools, design suites, VPNs and subscriptions – pay locally and get your credentials on screen as soon as payment is verified.</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="#products" class="btn btn-primary btn-lg">Browse products</a>
                        <a href="{{ route('orders.track') }}" class="btn btn-outline btn-lg">Track an order</a>
                    </div>
                </div>
                <dl class="grid grid-cols-2 gap-4">
                    @foreach ([
                        ['Live stock', 'Real-time availability – what you see is what we have.', 'M4 12h4l3-8 4 16 3-8h2'],
                        ['Auto delivery', 'Credentials appear instantly after payment is verified.', 'M5 13l4 4L19 7'],
                        ['Local payments', 'Easypaisa, JazzCash, NayaPay, SadaPay & Binance.', 'M3 7h18v10H3zM3 11h18'],
                        ['Warranty', 'Every product lists its warranty clearly up-front.', 'M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z'],
                    ] as [$t, $d, $icon])
                        <div class="card p-5">
                            <svg class="h-6 w-6 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                            <dt class="mt-3 font-semibold text-ink">{{ $t }}</dt>
                            <dd class="mt-1 text-sm leading-6">{{ $d }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </section>
    @endif

    <section id="products" class="container-x scroll-mt-24 py-12 lg:py-16">
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <span class="eyebrow">{{ $search ? 'Search results' : 'Shop' }}</span>
                <h2 class="display mt-2 text-5xl">
                    @if ($search) “{{ $search }}” @elseif ($activeCategory) {{ $activeCategory->name }} @else All products @endif
                </h2>
            </div>
            <p class="flex items-center gap-2 text-sm"><span class="dot live-dot bg-success"></span> Stock updates live</p>
        </div>

        @if ($categories->isNotEmpty())
            <nav class="-mx-5 mt-6 flex gap-2 overflow-x-auto px-5 pb-2 md:mx-0 md:flex-wrap md:px-0" aria-label="Categories">
                <a href="{{ route('home') }}#products" @class(['btn shrink-0 rounded-full px-4 py-2', 'btn-dark' => ! $activeCategory, 'btn-outline' => $activeCategory])>All</a>
                @foreach ($categories as $category)
                    <a href="{{ route('home', ['category' => $category->slug]) }}#products" @class(['btn shrink-0 rounded-full px-4 py-2', 'btn-dark' => $activeCategory?->is($category), 'btn-outline' => ! $activeCategory?->is($category)])>{{ $category->name }}</a>
                @endforeach
            </nav>
        @endif

        @if ($products->isEmpty())
            <div class="card mt-8 p-12 text-center">
                <p class="display text-3xl">Nothing here yet</p>
                <p class="mt-2">Try another search or category.</p>
                <a href="{{ route('home') }}" class="btn btn-primary mt-6">View all products</a>
            </div>
        @else
            <div class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
            <div class="mt-10">{{ $products->links() }}</div>
        @endif
    </section>

    @if (! $search && ! $activeCategory)
        <section class="border-y border-line bg-grey-soft">
            <div class="container-x py-14">
                <span class="eyebrow">How it works</span>
                <h2 class="display mt-2 text-5xl">Three steps. No waiting.</h2>
                <ol class="mt-8 grid gap-5 md:grid-cols-3">
                    @foreach ([
                        ['Choose a product', 'Pick what you need. Stock is reserved for you for 15 minutes while you pay.'],
                        ['Pay & submit TID', 'Send the exact amount to our account and enter your transaction ID.'],
                        ['Get it instantly', 'As soon as payment is verified your item is delivered on the order page.'],
                    ] as $i => [$t, $d])
                        <li class="card p-6">
                            <span class="display text-5xl text-brand">0{{ $i + 1 }}</span>
                            <h3 class="mt-2 text-lg font-semibold">{{ $t }}</h3>
                            <p class="mt-1 text-sm leading-6">{{ $d }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>
    @endif
</x-layouts.store>

