@php
    $reviewsCount = (int) ($product->approved_reviews_count ?? $product->approvedReviews->count());
    $avgRating = $reviewsCount > 0 ? round((float) ($product->approved_reviews_avg_rating ?? $product->approvedReviews->avg('rating')), 1) : 5.0;
    $initialStockLabel = ! $product->inStock()
        ? 'Sold Out (0)'
        : ($product->stock_unlimited ? 'Unlimited Stock' : 'Stock: '.(int) $product->available_stock);

    $productUrl = route('products.show', $product);
    $productImage = $product->imageUrl() ?: asset('favicon.svg');

    $productSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        'description' => $product->seoDescription(),
        'image' => [$productImage],
        'sku' => 'SMT-'.$product->id,
        'mpn' => $product->slug,
        'brand' => [
            '@type' => 'Brand',
            'name' => $product->category?->name ?: setting('store_name', 'SellMeTools'),
        ],
        'offers' => [
            '@type' => 'Offer',
            'url' => $productUrl,
            'priceCurrency' => 'PKR',
            'price' => number_format((float) $product->price, 2, '.', ''),
            'priceValidUntil' => now()->addMonths(6)->toDateString(),
            'availability' => $product->inStock()
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => [
                '@type' => 'Organization',
                'name' => setting('store_name', 'SellMeTools'),
            ],
        ],
        'aggregateRating' => [
            '@type' => 'AggregateRating',
            'ratingValue' => number_format($avgRating, 1, '.', ''),
            'reviewCount' => max(1, $reviewsCount),
            'bestRating' => '5',
            'worstRating' => '1',
        ],
    ];

    if ($product->approvedReviews->isNotEmpty()) {
        $productSchema['review'] = $product->approvedReviews->take(5)->map(fn ($rev) => [
            '@type' => 'Review',
            'author' => [
                '@type' => 'Person',
                'name' => $rev->customer_name,
            ],
            'datePublished' => $rev->created_at?->toDateString() ?: now()->toDateString(),
            'reviewBody' => $rev->comment,
            'reviewRating' => [
                '@type' => 'Rating',
                'ratingValue' => (string) $rev->rating,
                'bestRating' => '5',
                'worstRating' => '1',
            ],
        ])->values()->all();
    }

    $breadcrumbItems = [
        [
            '@type' => 'ListItem',
            'position' => 1,
            'name' => 'Home',
            'item' => route('home'),
        ],
    ];
    if ($product->category) {
        $breadcrumbItems[] = [
            '@type' => 'ListItem',
            'position' => 2,
            'name' => $product->category->name,
            'item' => route('categories.show', $product->category),
        ];
    }
    $breadcrumbItems[] = [
        '@type' => 'ListItem',
        'position' => count($breadcrumbItems) + 1,
        'name' => $product->name,
        'item' => $productUrl,
    ];

    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $breadcrumbItems,
    ];

    $storeName = setting('store_name', 'SellMeTools');
    $priceStr = money($product->price);
    $productFaqs = [
        [
            'q' => "What is the price of {$product->name} in Pakistan?",
            'a' => "You can buy {$product->name} in Pakistan for just {$priceStr} on {$storeName} with instant delivery.",
        ],
        [
            'q' => "How to buy {$product->name} in Pakistan with Easypaisa, JazzCash, or Binance?",
            'a' => "Click Buy Now, choose Easypaisa, JazzCash, NayaPay, SadaPay, Bank Transfer/Raast, or Binance Pay, and receive your credentials automatically after verification.",
        ],
    ];

    $productFaqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => collect($productFaqs)->map(fn ($f) => [
            '@type' => 'Question',
            'name' => $f['q'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $f['a'],
            ],
        ])->all(),
    ];
@endphp
@push('seo')
    <meta property="product:price:amount" content="{{ number_format((float) $product->price, 2, '.', '') }}">
    <meta property="product:price:currency" content="PKR">
    <meta property="product:availability" content="{{ $product->inStock() ? 'in stock' : 'out of stock' }}">
    <script type="application/ld+json">{!! json_encode($productSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    <script type="application/ld+json">{!! json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    <script type="application/ld+json">{!! json_encode($productFaqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush
<x-layouts.store
    :title="$product->seoTitle()"
    :description="$product->seoDescription()"
    :keywords="$product->seoKeywords()"
    :image="$productImage"
    :type="'product'"
    :canonical="$productUrl"
>
    <div class="container-x py-5 sm:py-8">
        {{-- Breadcrumb Navigation --}}
        <nav class="text-xs sm:text-sm mb-4" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2 text-muted">
                <li><a href="{{ route('home') }}" class="hover:text-ink transition">Shop</a></li>
                @if ($product->category)
                    <li aria-hidden="true" class="text-muted/60">/</li>
                    <li><a href="{{ route('categories.show', $product->category) }}" class="hover:text-ink transition">{{ $product->category->name }}</a></li>
                @endif
                <li aria-hidden="true" class="text-muted/60">/</li>
                <li class="text-ink font-semibold truncate max-w-[200px] sm:max-w-none" aria-current="page">{{ $product->name }}</li>
            </ol>
        </nav>

        @php
            $effectivePrice = $product->priceFor(auth()->user());
            $hasResellerDiscount = $effectivePrice < (float) $product->price;
        @endphp

        {{-- TOP SECTION: Product Image + Sticky Buy Card --}}
        <div class="mt-4 grid gap-6 lg:grid-cols-[1fr_440px] lg:items-start"
             x-data="stockBadge({{ $product->id }}, {{ (int) $product->available_stock }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, @js(money($effectivePrice)), {{ (float) $effectivePrice }})"
             data-stock-id="{{ $product->id }}">

            {{-- Product Image (Full uncropped image with ambient backdrop) --}}
            <div class="card relative aspect-[16/10] overflow-hidden bg-[#140408]">
                @if ($img = $product->imageUrl())
                    <img src="{{ $img }}" alt="" aria-hidden="true" class="pointer-events-none absolute inset-0 h-full w-full scale-110 object-cover opacity-35 blur-lg">
                    <img src="{{ $img }}" alt="{{ $product->name }}" class="relative z-10 h-full w-full object-contain p-3 sm:p-4">
                @else
                    <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand to-ink">
                        <span class="display px-6 text-center text-3xl sm:text-6xl text-white">{{ $product->name }}</span>
                    </div>
                @endif
            </div>

            {{-- Main Buy Box (Sticky on Desktop) --}}
            <aside class="lg:sticky lg:top-24">
                <div class="card p-5 sm:p-7 shadow-lg border-2 border-line">
                    <div class="flex items-center justify-between gap-2 border-b border-line pb-3">
                        <span class="eyebrow truncate">{{ $product->category?->name ?? 'Software & AI' }}</span>
                        <a href="#reviews" class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-amber-500 hover:underline">
                            <span>★</span>
                            <span class="text-ink font-bold">{{ number_format($avgRating, 1) }}</span>
                            <span class="text-muted">({{ $reviewsCount }} reviews)</span>
                        </a>
                    </div>

                    <h1 class="mt-3 text-2xl sm:text-3xl font-bold text-ink leading-tight break-words">{{ $product->name }}</h1>
                    @if ($product->short_description)
                        <p class="mt-2 text-xs sm:text-sm text-body leading-relaxed">{{ $product->short_description }}</p>
                    @endif

                    {{-- Live Price & Stock Status Bar --}}
                    <div class="mt-5 flex flex-wrap items-end justify-between gap-3 rounded-xl bg-grey-soft p-4 border border-line">
                        <div>
                            @if ($hasResellerDiscount)
                                <p class="text-xs text-muted">Retail: <span class="line-through" x-text="$store.currency.format({{ (float) $product->price }})">{{ money($product->price) }}</span> <span class="badge badge-brand ml-1 text-[10px]">VIP Reseller Rate</span></p>
                            @elseif ($product->compare_at_price && $product->compare_at_price > $effectivePrice)
                                <p class="text-xs text-muted line-through" x-text="$store.currency.format({{ (float) $product->compare_at_price }})">{{ money($product->compare_at_price) }}</p>
                            @endif
                            <p class="display text-4xl sm:text-5xl text-brand" x-text="priceText">{{ money($effectivePrice) }}</p>
                            <p class="mt-1 text-[11px] font-semibold text-muted">
                                <span x-text="$store.currency.isUsdt ? '≈ ' + $store.currency.symbol + ' ' + Math.round(unitPrice).toLocaleString('en-US') + ' PKR' : '≈ $' + (unitPrice / $store.currency.rate).toFixed(2) + ' USDT'">
                                    ≈ ${{ number_format($effectivePrice / \App\Models\Setting::usdtRate(), 2) }} USDT
                                </span>
                                · Both PKR &amp; USDT accepted
                            </p>
                        </div>
                        <span :class="inStock ? 'badge badge-success' : 'badge badge-danger'" class="text-xs py-1 px-3">
                            <span class="dot" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
                            <span x-text="label" class="font-bold">{{ $initialStockLabel }}</span>
                        </span>
                    </div>

                    {{-- Add to Cart / Buy Form --}}
                    <form method="get" action="{{ route('checkout.create', $product) }}" class="mt-5 space-y-4" x-data="{ qty: 1, max: {{ $product->max_per_order }} }">
                        <div class="flex items-center justify-between gap-4">
                            <label class="text-xs sm:text-sm font-semibold text-ink" for="qty">Quantity</label>
                            <div class="flex w-36 items-center rounded-lg border border-line-strong bg-white shadow-xs">
                                <button type="button" class="px-3.5 py-2 text-base font-bold text-ink hover:bg-grey-soft transition active:scale-90 disabled:opacity-30" @click="qty = Math.max(1, qty - 1)" :disabled="qty <= 1" aria-label="Decrease">−</button>
                                <input id="qty" name="qty" type="number" min="1" :max="max" x-model.number="qty" class="w-full border-0 text-center font-bold text-ink focus:ring-0 focus:outline-none text-sm" inputmode="numeric">
                                <button type="button" class="px-3.5 py-2 text-base font-bold text-ink hover:bg-grey-soft transition active:scale-90 disabled:opacity-30" @click="qty = Math.min(max, data.unlimited ? max : Math.max(1, data.qty), qty + 1)" :disabled="qty >= max || (!data.unlimited && qty >= data.qty)" aria-label="Increase">+</button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-full text-base font-bold shadow-md" :disabled="!inStock">
                            <span x-show="inStock">Proceed to Checkout →</span>
                            <span x-show="!inStock" x-cloak>Currently Out of Stock</span>
                        </button>
                    </form>

                    @if ($product->warranty)
                        <div class="mt-4 rounded-xl bg-brand-tint/60 border border-brand/20 p-4">
                            <p class="text-xs font-bold uppercase tracking-wider text-brand-text flex items-center gap-1.5">
                                <span>🛡️</span> Warranty &amp; Guarantee Terms
                            </p>
                            <p class="mt-1.5 text-xs leading-5 whitespace-pre-line text-slate break-words">{{ $product->warranty }}</p>
                        </div>
                    @endif

                    <ul class="mt-4 space-y-2 text-xs text-body border-t border-line pt-3.5">
                        <li class="flex items-center gap-2 font-medium">
                            <svg class="h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg>
                            Instant credentials on order page after verification
                        </li>
                        <li class="flex items-center gap-2 font-medium">
                            <svg class="h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg>
                            Easypaisa · JazzCash · NayaPay · SadaPay · Binance USDT
                        </li>
                    </ul>
                </div>
            </aside>
        </div>

        {{-- SECONDARY DETAILS & REVIEWS BELOW --}}
        <div class="mt-10 grid gap-6 lg:grid-cols-2">
            @if ($product->description)
                <section class="card p-5 sm:p-7 shadow-xs">
                    <h2 class="display text-2xl sm:text-3xl">Product Overview</h2>
                    <div class="prose-store mt-4 text-xs sm:text-sm break-words leading-relaxed text-body">{!! nl2br(e(strip_tags($product->description))) !!}</div>
                </section>
            @endif

            {{-- Customer Reviews Section --}}
            <section id="reviews" @class(['card p-5 sm:p-7 scroll-mt-24 shadow-xs', 'lg:col-span-2' => ! $product->description])>
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-4">
                    <div>
                        <h2 class="display text-2xl sm:text-3xl">Customer Reviews</h2>
                        <p class="mt-0.5 text-xs sm:text-sm text-muted">
                            Verified ratings from active buyers
                        </p>
                    </div>
                    <div class="flex items-center gap-1.5 rounded-full bg-amber-500/10 px-3.5 py-1 text-sm font-bold text-amber-900 border border-amber-500/20">
                        <span class="text-amber-500">★</span>
                        <span>{{ number_format($avgRating, 1) }} / 5.0</span>
                        @if ($reviewsCount > 0)
                            <span class="text-muted font-normal text-xs">({{ $reviewsCount }})</span>
                        @endif
                    </div>
                </div>

                @if ($product->approvedReviews->isNotEmpty())
                    <div class="mt-4 divide-y divide-line">
                        @foreach ($product->approvedReviews->take(8) as $rev)
                            <div class="py-3.5 first:pt-0">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <div class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-tint text-xs font-bold text-brand uppercase">
                                            {{ mb_substr($rev->customer_name, 0, 1) }}
                                        </div>
                                        <p class="text-xs sm:text-sm font-semibold text-ink">{{ $rev->customer_name }}</p>
                                    </div>
                                    <span class="text-xs text-amber-500">{{ str_repeat('★', $rev->rating) }}{{ str_repeat('☆', 5 - $rev->rating) }}</span>
                                </div>
                                @if ($rev->comment)
                                    <p class="mt-2 text-xs sm:text-sm text-body break-words pl-9">{{ $rev->comment }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="mt-6 rounded-xl bg-grey-soft p-6 text-center text-xs sm:text-sm text-muted">
                        No reviews yet, be the first to share your experience with this tool!
                    </div>
                @endif

                <form method="post" action="{{ route('products.reviews.store', $product) }}" class="mt-6 border-t border-line pt-5 space-y-3.5">
                    @csrf
                    <h3 class="text-sm font-bold text-ink">Leave a Review</h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <input id="customer_name" name="customer_name" required class="input py-2.5 text-xs sm:text-sm" value="{{ old('customer_name', auth()->user()?->name) }}" placeholder="Your Name">
                        </div>
                        <div>
                            <select id="rating" name="rating" class="input py-2.5 text-xs sm:text-sm">
                                <option value="5">★★★★★ (5/5 Excellent)</option>
                                <option value="4">★★★★☆ (4/5 Good)</option>
                                <option value="3">★★★☆☆ (3/5 Average)</option>
                                <option value="2">★★☆☆☆ (2/5 Poor)</option>
                                <option value="1">★☆☆☆☆ (1/5 Bad)</option>
                            </select>
                        </div>
                    </div>
                    <textarea id="comment" name="comment" rows="2" required class="input text-xs sm:text-sm" placeholder="Write your feedback...">{{ old('comment') }}</textarea>
                    <button type="submit" class="btn btn-outline text-xs sm:text-sm py-2 px-5 font-bold">Submit Review</button>
                </form>
            </section>
        </div>

        @if ($related->isNotEmpty())
            <section class="mt-12">
                <div class="flex items-end justify-between">
                    <div>
                        <span class="eyebrow">Recommendations</span>
                        <h2 class="display mt-1 text-2xl sm:text-4xl">Related Tools</h2>
                    </div>
                    <a href="{{ route('home') }}#products" class="text-xs sm:text-sm font-semibold text-brand hover:underline">View All →</a>
                </div>
                <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-4">
                    @foreach ($related as $item)
                        <x-product-card :product="$item" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.store>
