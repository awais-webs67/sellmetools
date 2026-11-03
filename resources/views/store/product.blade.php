@php
    $reviewsCount = (int) ($product->approved_reviews_count ?? $product->approvedReviews->count());
    $avgRating = $reviewsCount > 0 ? round((float) ($product->approved_reviews_avg_rating ?? $product->approvedReviews->avg('rating')), 1) : 5.0;
    $initialStockLabel = ! $product->inStock()
        ? 'Sold Out (0)'
        : ($product->stock_unlimited ? 'Unlimited Stock' : 'Stock: '.(int) $product->available_stock);

    $productUrl = route('products.show', $product);
    $productImage = $product->imageUrl() ?: asset('favicon.svg');
    $storeName = (string) setting('store_name', 'SellMeTools');
    $schemaPrice = (float) $product->price > 0
        ? (float) $product->price
        : ((float) $product->compare_at_price > 0 ? (float) $product->compare_at_price : 0.0);

    $imageObjectSchema = [
        '@type' => 'ImageObject',
        '@id' => $productUrl.'#primaryimage',
        'url' => $productImage,
        'contentUrl' => $productImage,
        'name' => $product->imageSeoTitle(),
        'caption' => $product->imageSeoAlt(),
        'description' => $product->seoDescription(),
        'width' => 640,
        'height' => 400,
        'representativeOfPage' => true,
        'creditText' => $storeName,
        'creator' => [
            '@type' => 'Organization',
            'name' => $storeName,
            'url' => url('/'),
        ],
        'copyrightNotice' => '© '.date('Y').' '.$storeName.' (AivraSol Softwares)',
        'license' => route('pages.show', 'terms'),
        'acquireLicensePage' => $productUrl,
    ];

    $productSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        'description' => $product->seoDescription(),
        'image' => [$imageObjectSchema, $productImage],
        'sku' => 'SMT-'.$product->id,
        'mpn' => $product->slug,
        'brand' => [
            '@type' => 'Brand',
            'name' => $product->brandName(),
        ],
        'hasMemberProgram' => [
            '@type' => 'MemberProgram',
            'name' => $storeName.' VIP Reseller & Customer Program',
            'description' => 'Instant wallet delivery and wholesale reseller tier pricing in Pakistan.',
        ],
        'offers' => array_filter([
            '@type' => 'Offer',
            'url' => $productUrl,
            'priceCurrency' => 'PKR',
            'price' => $schemaPrice > 0 ? number_format($schemaPrice, 2, '.', '') : ($product->isFree() ? '0.00' : null),
            'priceValidUntil' => now()->addMonths(6)->toDateString(),
            'availability' => $product->inStock()
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => [
                '@type' => 'Organization',
                'name' => $storeName,
            ],
            'shippingDetails' => [
                '@type' => 'OfferShippingDetails',
                'shippingRate' => [
                    '@type' => 'MonetaryAmount',
                    'value' => '0.00',
                    'currency' => 'PKR',
                ],
                'shippingDestination' => [
                    '@type' => 'DefinedRegion',
                    'addressCountry' => ['PK', 'US', 'GB', 'AE', 'CA', 'SA', 'AU'],
                ],
                'deliveryTime' => [
                    '@type' => 'ShippingDeliveryTime',
                    'handlingTime' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => 0,
                        'maxValue' => 0,
                        'unitCode' => 'DAY',
                    ],
                    'transitTime' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => 0,
                        'maxValue' => 0,
                        'unitCode' => 'DAY',
                    ],
                ],
            ],
            'hasMerchantReturnPolicy' => [
                '@type' => 'MerchantReturnPolicy',
                'applicableCountry' => ['PK', 'US', 'GB', 'AE', 'CA', 'SA', 'AU'],
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                'merchantReturnDays' => 7,
                'returnMethod' => 'https://schema.org/ReturnByMail',
                'returnFees' => 'https://schema.org/FreeReturn',
                'merchantReturnLink' => route('pages.show', 'refund'),
            ],
        ], fn ($v) => $v !== null),
    ];

    if ($reviewsCount > 0) {
        $productSchema['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => number_format($avgRating, 1, '.', ''),
            'reviewCount' => $reviewsCount,
            'bestRating' => '5',
            'worstRating' => '1',
        ];
    }

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

    $hasValidPrice = (float) $product->price > 0;
    $priceStr = $hasValidPrice ? money($product->price) : null;
    $usdPriceStr = $hasValidPrice ? number_format((float) $product->price / \App\Models\Setting::usdtRate(), 2) : null;
    $productFaqs = [
        [
            'q' => "Where can I buy a {$product->name} cheap subscription online with instant delivery?",
            'a' => $hasValidPrice
                ? "You can buy a genuine {$product->name} cheap subscription online on {$storeName} for just {$priceStr} (approx. \${$usdPriceStr} USDT) with instant automated delivery on your private order page."
                : "You can buy a genuine {$product->name} cheap subscription online on {$storeName} with instant automated delivery on your private order page as soon as stock is available.",
        ],
        [
            'q' => "What is the price of {$product->name} in Pakistan?",
            'a' => $hasValidPrice
                ? "The current price of {$product->name} in Pakistan is {$priceStr} (approx. \${$usdPriceStr} USDT) on {$storeName}, including instant delivery on your private order page."
                : "Check the live PKR and USDT price of {$product->name} on {$storeName}. As soon as stock is available, you can order with instant automated delivery in Pakistan and worldwide.",
        ],
        [
            'q' => "How to buy {$product->name} online with Easypaisa, JazzCash, or Binance USDT?",
            'a' => "Click Proceed to Checkout, choose Easypaisa, JazzCash, NayaPay, SadaPay, Bank Transfer/Raast, or Binance Pay (USDT), submit your Transaction ID, and receive your credentials automatically on your order page after verification.",
        ],
        [
            'q' => "How is {$product->name} delivered and what warranty is included?",
            'a' => ($product->warranty ? 'Warranty terms: '.preg_replace('/\s+/', ' ', $product->cleanWarrantyText()).'. ' : '')."Your login credentials, invite link, or activation details are delivered directly on your private order page and saved in your customer account dashboard, backed by 24/7 WhatsApp support.",
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
            $isFree = $product->isFree(auth()->user());
            $effectivePrice = $product->priceFor(auth()->user());
            $hasResellerDiscount = ! $isFree && $effectivePrice < (float) $product->price;
        @endphp

        {{-- TOP SECTION: Product Image + Sticky Buy Card --}}
        <div class="mt-4 grid gap-6 lg:grid-cols-[1fr_440px] lg:items-start"
             x-data="stockBadge({{ $product->id }}, {{ (int) $product->available_stock }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, @js(money($effectivePrice)), {{ (float) $effectivePrice }})"
             data-stock-id="{{ $product->id }}">

            {{-- Product Image (Full uncropped image with ambient backdrop) --}}
            <figure class="card surface-dark-solid relative aspect-[16/10] overflow-hidden m-0">
                @if ($img = $product->imageUrl())
                    <div aria-hidden="true" style="background-image: url('{{ $img }}')" class="pointer-events-none absolute inset-0 bg-cover bg-center scale-110 opacity-35 blur-lg"></div>
                    <img src="{{ $img }}" alt="{{ $product->imageSeoAlt() }}" title="{{ $product->imageSeoTitle() }}" width="640" height="400" fetchpriority="high" decoding="async" class="relative z-10 h-full w-full object-contain p-3 sm:p-4">
                @else
                    <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand to-ink">
                        <span class="display px-6 text-center text-3xl sm:text-6xl text-white">{{ $product->name }}</span>
                    </div>
                @endif
                <figcaption class="sr-only">{{ $product->imageSeoAlt() }} - {{ $product->cleanShortDescription() }}</figcaption>
            </figure>

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
                        <p class="mt-2 text-xs sm:text-sm text-body leading-relaxed">{{ $product->cleanShortDescription() }}</p>
                    @endif

                    {{-- Live Price & Stock Status Bar --}}
                    <div class="mt-5 flex flex-wrap items-end justify-between gap-3 rounded-xl bg-grey-soft p-4 border border-line">
                        <div>
                            @if ($isFree)
                                <span class="badge badge-success mb-1.5 text-[10px] font-bold">🎁 FREE GIVEAWAY</span>
                                @if ($product->compare_at_price && $product->compare_at_price > 0)
                                    <p class="text-xs text-muted line-through" x-text="$store.currency.format({{ (float) $product->compare_at_price }})">{{ money($product->compare_at_price) }}</p>
                                @endif
                                <p class="display text-4xl sm:text-5xl text-success">FREE</p>
                                <p class="mt-1 text-[11px] font-semibold text-success">
                                    🎁 100% Free Instant Access · No Payment Required
                                </p>
                            @else
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
                            @endif
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
                            <span x-show="inStock">{{ $isFree ? 'Claim Free Access Now →' : 'Buy Now - Proceed to Checkout →' }}</span>
                            <span x-show="!inStock" x-cloak>Currently Out of Stock</span>
                        </button>
                    </form>

                    @if ($product->warranty)
                        <div class="mt-4 rounded-xl bg-brand-tint/60 border border-brand/20 p-4">
                            <p class="text-xs font-bold uppercase tracking-wider text-brand-text flex items-center gap-1.5">
                                <span>🛡️</span> Warranty &amp; Guarantee Terms
                            </p>
                            <p class="mt-1.5 text-xs leading-5 whitespace-pre-line text-slate break-words">{{ $product->cleanWarrantyText() }}</p>
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
            <section class="card p-5 sm:p-7 shadow-xs space-y-5">
                <div>
                    <h2 class="display text-2xl sm:text-3xl">Product Overview &amp; Delivery Guide</h2>
                    @if ($product->description)
                        @php
                            $rawDesc = $product->cleanDescriptionText();
                            $hasHtmlTags = $rawDesc !== strip_tags($rawDesc);
                        @endphp
                        <div class="prose-store mt-3 text-xs sm:text-sm break-words leading-relaxed text-body">
                            @if ($hasHtmlTags)
                                {!! strip_tags($rawDesc, '<h2><h3><h4><p><br><ul><ol><li><strong><b><em><i><u><s><a><blockquote><code><hr>') !!}
                            @else
                                {!! nl2br(e($rawDesc)) !!}
                            @endif
                        </div>
                    @endif
                    <p class="mt-3 text-xs sm:text-sm leading-relaxed text-body">
                        Looking for a genuine <strong>{{ $product->name }} cheap subscription</strong> with <strong>instant delivery</strong>? Buy <strong>{{ $product->name }}</strong> online in Pakistan and worldwide from <strong>{{ $storeName }}</strong> with local PKR or USDT checkout. Your stock is reserved at checkout and your login credentials, invite link, or activation instructions are delivered directly to your private order page as soon as your payment is verified.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 border-t border-line pt-4">
                    <div class="rounded-xl bg-grey-soft/70 p-3.5 border border-line">
                        <h3 class="text-xs font-bold text-ink">⚡ Automated Order Vault</h3>
                        <p class="mt-1 text-xs leading-relaxed text-body">
                            Access your delivered credentials anytime on your order tracking page or inside your registered customer account dashboard.
                        </p>
                    </div>
                    <div class="rounded-xl bg-grey-soft/70 p-3.5 border border-line">
                        <h3 class="text-xs font-bold text-ink">🇵🇰 Local PKR &amp; USDT Payments</h3>
                        <p class="mt-1 text-xs leading-relaxed text-body">
                            Pay securely using Easypaisa, JazzCash, NayaPay, SadaPay, local bank transfer (Raast), or Binance Pay (USDT).
                        </p>
                    </div>
                </div>

                <div class="border-t border-line pt-4">
                    <h3 class="text-sm font-bold text-ink">Frequently Asked Questions about {{ $product->name }}</h3>
                    <div class="mt-3 space-y-2">
                        @foreach ($productFaqs as $faq)
                            <details class="group rounded-xl border border-line bg-grey-soft/40 p-3.5" @if ($loop->first) open @endif>
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-2 text-xs sm:text-sm font-bold text-ink">
                                    <span>{{ $faq['q'] }}</span>
                                    <span class="text-brand font-bold transition group-open:rotate-45">+</span>
                                </summary>
                                <p class="mt-2 text-xs leading-relaxed text-body">{{ $faq['a'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- Customer Reviews Section --}}
            <section id="reviews" class="card p-5 sm:p-7 scroll-mt-24 shadow-xs">
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
                    <div x-data="{ showAllReviews: false }">
                        <div class="mt-4 divide-y divide-line">
                            @foreach ($product->approvedReviews->take(50) as $revIndex => $rev)
                                <div class="py-3.5 first:pt-0" @if ($revIndex >= 8) x-show="showAllReviews" x-cloak @endif>
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-2">
                                            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-tint text-xs font-bold text-brand uppercase">
                                                {{ mb_substr($rev->customer_name, 0, 1) }}
                                            </div>
                                            <p class="text-xs sm:text-sm font-semibold text-ink">{{ $rev->customer_name }}</p>
                                            <span class="rounded bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700">Verified Buyer</span>
                                        </div>
                                        <span class="text-xs text-amber-500">{{ str_repeat('★', $rev->rating) }}{{ str_repeat('☆', 5 - $rev->rating) }}</span>
                                    </div>
                                    @if ($rev->comment)
                                        <p class="mt-2 text-xs sm:text-sm text-body break-words pl-9">{{ $rev->comment }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if ($product->approvedReviews->count() > 8)
                            <div class="mt-4 pt-2 text-center">
                                <button type="button"
                                        @click="showAllReviews = !showAllReviews"
                                        class="btn btn-outline w-full sm:w-auto px-5 py-2 text-xs font-bold">
                                    <span x-show="!showAllReviews">Show All {{ $reviewsCount }} Verified Reviews ↓</span>
                                    <span x-show="showAllReviews" x-cloak>Show Less Reviews ↑</span>
                                </button>
                            </div>
                        @endif
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
