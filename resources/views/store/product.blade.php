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
            'a' => "You can buy {$product->name} in Pakistan for just {$priceStr} on {$storeName}. We offer wholesale rates in Pakistani Rupees (PKR) and USDT with zero hidden charges.",
        ],
        [
            'q' => "How to buy {$product->name} in Pakistan with Easypaisa, JazzCash, or Binance?",
            'a' => "Click 'Buy Now' on this page, enter your email, choose Easypaisa, JazzCash, NayaPay, SadaPay, Bank Transfer/Raast, or Binance Pay, and submit your unique Transaction ID (TID). Your order is verified and delivered automatically.",
        ],
        [
            'q' => "How fast will I receive {$product->name} after payment?",
            'a' => "Delivery is instant! As soon as your payment is verified, your {$product->name} login credentials or activation link appear immediately on your order screen and are saved forever in your account history.",
        ],
        [
            'q' => "Does {$product->name} include a replacement warranty?",
            'a' => $product->warranty
                ? trim($product->warranty)
                : "Yes, {$product->name} comes with our full replacement warranty and fast WhatsApp support in Pakistan.",
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

    $speakableSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => $product->seoTitle(),
        'url' => $productUrl,
        'speakable' => [
            '@type' => 'SpeakableSpecification',
            'cssSelector' => ['h1', '#product-aeo-faq'],
        ],
    ];
@endphp
@push('seo')
    <meta property="product:price:amount" content="{{ number_format((float) $product->price, 2, '.', '') }}">
    <meta property="product:price:currency" content="PKR">
    <meta property="product:availability" content="{{ $product->inStock() ? 'in stock' : 'out of stock' }}">
    <script type="application/ld+json">{!! json_encode($productSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    <script type="application/ld+json">{!! json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    <script type="application/ld+json">{!! json_encode($productFaqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    <script type="application/ld+json">{!! json_encode($speakableSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush
<x-layouts.store
    :title="$product->seoTitle()"
    :description="$product->seoDescription()"
    :keywords="$product->seoKeywords()"
    :image="$productImage"
    :type="'product'"
    :canonical="$productUrl"
>
    <div class="container-x py-6 sm:py-10">
        <nav class="text-xs sm:text-sm" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2 text-muted">
                <li><a href="{{ route('home') }}" class="hover:text-ink">Shop</a></li>
                @if ($product->category)
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('categories.show', $product->category) }}" class="hover:text-ink">{{ $product->category->name }}</a></li>
                @endif
                <li aria-hidden="true">/</li>
                <li class="text-ink font-medium" aria-current="page">{{ $product->name }}</li>
            </ol>
        </nav>

        <div class="mt-5 grid gap-8 lg:grid-cols-[1fr_440px]"
             x-data="stockBadge({{ $product->id }}, {{ (int) $product->available_stock }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, @js(money($product->price)))"
             data-stock-id="{{ $product->id }}">
            <div>
                <div class="card aspect-[16/10] overflow-hidden bg-grey-soft">
                    @if ($img = $product->imageUrl())
                        <img src="{{ $img }}" alt="{{ $product->name }}" class="h-full w-full object-cover">
                    @else
                        <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand to-ink">
                            <span class="display px-6 text-center text-4xl sm:text-6xl text-white">{{ $product->name }}</span>
                        </div>
                    @endif
                </div>

                <div class="mt-8 space-y-8">
                    @if ($product->description)
                        <section class="card p-5 sm:p-6">
                            <h2 class="text-lg font-bold">Product Details</h2>
                            <div class="prose-store mt-3">{!! nl2br(e(strip_tags($product->description))) !!}</div>
                        </section>
                    @endif

                    {{-- Customer Reviews Section --}}
                    <section id="reviews" class="card p-5 sm:p-6 scroll-mt-24">
                        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-line pb-4">
                            <div>
                                <h2 class="text-lg font-bold">Customer Reviews</h2>
                                <p class="mt-0.5 text-xs text-muted">
                                    <span class="font-bold text-amber-500">★ {{ number_format($avgRating, 1) }}</span> out of 5
                                    @if ($reviewsCount > 0) · Based on {{ $reviewsCount }} review(s) @endif
                                </p>
                            </div>
                        </div>

                        @if ($product->approvedReviews->isNotEmpty())
                            <div class="mt-4 divide-y divide-line">
                                @foreach ($product->approvedReviews->take(10) as $rev)
                                    <div class="py-4 first:pt-0">
                                        <div class="flex items-center justify-between gap-2">
                                            <p class="text-sm font-semibold text-ink">{{ $rev->customer_name }}</p>
                                            <span class="text-xs text-amber-500">{{ str_repeat('★', $rev->rating) }}{{ str_repeat('☆', 5 - $rev->rating) }}</span>
                                        </div>
                                        @if ($rev->comment)
                                            <p class="mt-1 text-sm text-body">{{ $rev->comment }}</p>
                                        @endif
                                        <p class="mt-1 text-[11px] text-muted">{{ $rev->created_at->diffForHumans() }}</p>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-4 text-sm text-muted">No reviews yet — be the first to review this product below!</p>
                        @endif

                        {{-- Write a review form --}}
                        <form method="post" action="{{ route('products.reviews.store', $product) }}" class="mt-6 border-t border-line pt-5 space-y-3">
                            @csrf
                            <h3 class="text-sm font-bold text-ink">Write a Review</h3>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="label text-xs" for="customer_name">Your Name</label>
                                    <input id="customer_name" name="customer_name" required class="input py-2 text-sm" value="{{ old('customer_name', auth()->user()?->name) }}" placeholder="Ali Khan">
                                </div>
                                <div>
                                    <label class="label text-xs" for="rating">Rating</label>
                                    <select id="rating" name="rating" class="input py-2 text-sm">
                                        <option value="5">★★★★★ (5/5 Excellent)</option>
                                        <option value="4">★★★★☆ (4/5 Good)</option>
                                        <option value="3">★★★☆☆ (3/5 Average)</option>
                                        <option value="2">★★☆☆☆ (2/5 Poor)</option>
                                        <option value="1">★☆☆☆☆ (1/5 Bad)</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="label text-xs" for="comment">Your Experience</label>
                                <textarea id="comment" name="comment" rows="2" required class="input text-sm" placeholder="Fast delivery, working great...">{{ old('comment') }}</textarea>
                            </div>
                            <button type="submit" class="btn btn-outline text-xs">Submit Review</button>
                        </form>
                    </section>

                    {{-- Auto-Generated AEO / GEO Product Q&A Section --}}
                    <section id="product-aeo-faq" class="card p-5 sm:p-6">
                        <h2 class="text-lg font-bold">Frequently Asked Questions about {{ $product->name }}</h2>
                        <div class="mt-4 space-y-2.5">
                            @foreach ($productFaqs as $faq)
                                <details class="group rounded-xl border border-line bg-grey-soft/60 p-3.5 sm:p-4" @if ($loop->first) open @endif>
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-xs sm:text-sm font-bold text-ink">
                                        <span>{{ $faq['q'] }}</span>
                                        <span class="text-brand transition group-open:rotate-45 text-base leading-none">+</span>
                                    </summary>
                                    <p class="mt-2 text-xs sm:text-sm leading-relaxed text-body">{{ $faq['a'] }}</p>
                                </details>
                            @endforeach
                        </div>

                        <div class="mt-5 border-t border-line pt-4">
                            <p class="text-[11px] font-semibold tracking-wider text-muted uppercase">Popular Searches in Pakistan</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ([
                                    "Buy {$product->name} in Pakistan",
                                    "{$product->name} Price in Pakistan ({$priceStr})",
                                    "{$product->name} Easypaisa JazzCash",
                                    "{$product->name} Instant Delivery",
                                    $product->category ? "Cheap {$product->category->name} Pakistan" : null,
                                ] as $tag)
                                    @if ($tag)
                                        <a href="{{ $productUrl }}" class="rounded-full border border-line bg-white px-2.5 py-1 text-[11px] text-muted hover:border-brand hover:text-brand">{{ $tag }}</a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <aside class="lg:sticky lg:top-24 lg:self-start">
                <div class="card p-5 sm:p-6">
                    <div class="flex items-center justify-between gap-2">
                        @if ($product->category)
                            <p class="text-xs font-semibold tracking-wider text-brand uppercase">{{ $product->category->name }}</p>
                        @endif
                        <a href="#reviews" class="inline-flex items-center gap-1 text-xs font-semibold text-amber-500 hover:underline">
                            ★ <span class="text-ink">{{ number_format($avgRating, 1) }}</span>
                            <span class="text-muted">({{ $reviewsCount }})</span>
                        </a>
                    </div>

                    <h1 class="mt-1.5 text-xl sm:text-2xl font-bold leading-tight">{{ $product->name }}</h1>
                    @if ($product->short_description)
                        <p class="mt-2 text-sm text-body">{{ $product->short_description }}</p>
                    @endif

                    <div class="mt-5 flex items-end justify-between gap-4 border-y border-line py-4">
                        <div>
                            @if ($product->compare_at_price && $product->compare_at_price > $product->price)
                                <p class="text-xs text-muted line-through">{{ money($product->compare_at_price) }}</p>
                            @endif
                            <p class="display text-4xl sm:text-5xl text-ink" x-text="priceText">{{ money($product->price) }}</p>
                        </div>
                        <span :class="inStock ? 'badge badge-success' : 'badge badge-danger'" class="text-xs sm:text-sm">
                            <span class="dot" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
                            <span x-text="label">{{ $initialStockLabel }}</span>
                        </span>
                    </div>

                    <form method="get" action="{{ route('checkout.create', $product) }}" class="mt-5 space-y-4" x-data="{ qty: 1, max: {{ $product->max_per_order }} }">
                        <div>
                            <label class="label" for="qty">Quantity</label>
                            <div class="flex w-36 items-center rounded-lg border border-line-strong">
                                <button type="button" class="px-3 py-2 text-lg text-ink disabled:opacity-40" @click="qty = Math.max(1, qty - 1)" :disabled="qty <= 1" aria-label="Decrease">−</button>
                                <input id="qty" name="qty" type="number" min="1" :max="max" x-model.number="qty" class="w-full border-0 text-center font-semibold text-ink focus:ring-0 focus:outline-none" inputmode="numeric">
                                <button type="button" class="px-3 py-2 text-lg text-ink disabled:opacity-40" @click="qty = Math.min(max, data.unlimited ? max : Math.max(1, data.qty), qty + 1)" :disabled="qty >= max || (!data.unlimited && qty >= data.qty)" aria-label="Increase">+</button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-full" :disabled="!inStock">
                            <span x-show="inStock">Buy Now</span>
                            <span x-show="!inStock" x-cloak>Sold Out</span>
                        </button>
                    </form>

                    @if ($product->warranty)
                        <div class="mt-5 rounded-xl bg-brand-tint p-4">
                            <p class="text-xs font-bold uppercase tracking-wider text-brand-text">Warranty &amp; Terms</p>
                            <p class="mt-1 text-xs sm:text-sm leading-6 whitespace-pre-line text-slate">{{ $product->warranty }}</p>
                        </div>
                    @endif

                    <ul class="mt-5 space-y-2 text-xs sm:text-sm">
                        <li class="flex gap-2"><svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg> Instant credentials delivery after payment</li>
                        <li class="flex gap-2"><svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg> Easypaisa · JazzCash · NayaPay · SadaPay · Binance</li>
                        <li class="flex gap-2"><svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg> Saved permanently in your account order history</li>
                    </ul>
                </div>
            </aside>
        </div>

        @if ($related->isNotEmpty())
            <section class="mt-14">
                <h2 class="display text-3xl sm:text-4xl">Related Tools</h2>
                <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-4">
                    @foreach ($related as $item)
                        <x-product-card :product="$item" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.store>
