@php
    use App\Enums\OrderStatus;
    use App\Models\Order;
    use App\Models\PaymentMethod;
    use App\Models\Product;
    use Illuminate\Support\Facades\Cache;

    $isLanding = ! $search && ! $activeCategory && $products->onFirstPage();

    $seoTitle = $activeCategory
        ? $activeCategory->seoTitle()
        : ($search ? "Search Results for “{$search}”" : null);
    $seoDescription = $activeCategory
        ? $activeCategory->seoDescription()
        : null;
    $canonicalUrl = $activeCategory
        ? route('categories.show', $activeCategory)
        : route('home');
    $robotsDirective = $search !== ''
        ? 'noindex, follow'
        : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';

    // Real store numbers only (cached 10 min) — no fake counters.
    $stats = $isLanding
        ? Cache::remember('home.stats.v2', 600, fn () => [
            'products' => Product::query()->visible()->count(),
            'delivered' => Order::query()->where('status', OrderStatus::Completed)->count(),
        ])
        : ['products' => 0, 'delivered' => 0];

    $paymentMethods = $isLanding
        ? Cache::remember('home.payment_methods.v2', 600, fn () => PaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->pluck('name')
            ->all())
        : [];

    $waNumber = admin_whatsapp_number();
    $storeName = (string) setting('store_name', 'SellMeTools');

    $itemListSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => $activeCategory ? $activeCategory->name : 'Digital Tools & Subscriptions in Pakistan',
        'itemListElement' => $products->getCollection()->values()->map(fn ($p, $idx) => [
            '@type' => 'ListItem',
            'position' => $idx + 1,
            'url' => route('products.show', $p),
            'name' => $p->name,
        ])->all(),
    ];

    $faqs = [
        [
            'q' => 'How fast will I receive my order?',
            'a' => 'In-stock products are delivered automatically on your order page as soon as your payment is verified. Payments that need manual checking are usually verified within 15 minutes during working hours, and you can follow the live status on your order page.',
        ],
        [
            'q' => 'Which payment methods do you accept?',
            'a' => 'You can pay with the methods listed at checkout, including Pakistani wallets such as Easypaisa, JazzCash, NayaPay and SadaPay, bank transfer, and Binance Pay / USDT. Every Transaction ID can only be used once.',
        ],
        [
            'q' => 'Where can I find my credentials later?',
            'a' => 'Your delivered credentials stay on your private order page. If you create an account, all of your orders and credentials are saved in your customer panel.',
        ],
        [
            'q' => 'What happens if something stops working?',
            'a' => 'Each product page lists its warranty terms. If you face an issue within the warranty period, contact us on WhatsApp with your Order ID and we will help you with a fix or replacement.',
        ],
    ];

    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => collect($faqs)->map(fn ($f) => [
            '@type' => 'Question',
            'name' => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ])->all(),
    ];
@endphp
@push('seo')
    <script type="application/ld+json">{!! json_encode($itemListSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @if ($isLanding)
        <script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
@endpush
<x-layouts.store
    :title="$seoTitle"
    :description="$seoDescription"
    :canonical="$canonicalUrl"
    :robots="$robotsDirective"
>
    @if ($isLanding)
        {{-- ================= HERO ================= --}}
        <section class="relative overflow-hidden bg-[#120408] text-white">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(155,27,48,.55),transparent_55%),radial-gradient(ellipse_at_bottom_left,rgba(94,16,33,.6),transparent_50%)]" aria-hidden="true"></div>
            <div class="pointer-events-none absolute inset-0 opacity-[.07] [background-image:linear-gradient(rgba(255,255,255,.6)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.6)_1px,transparent_1px)] [background-size:40px_40px]" aria-hidden="true"></div>

            <div class="container-x relative py-12 sm:py-16 lg:py-20 text-center">
                <div class="mx-auto max-w-3xl">
                    <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3.5 py-1 text-[11px] font-semibold tracking-wider text-white/90 uppercase">
                        <span class="h-2 w-2 rounded-full bg-[#22c55e] live-dot"></span>
                        Verified Digital Store
                    </span>

                    <h1 class="display mt-4 text-[clamp(40px,7vw,80px)] leading-[0.95] text-white">
                        Premium AI Tools &amp;<br class="hidden sm:block">
                        <span class="text-[#ff6b81]">Subscriptions</span> in PKR
                    </h1>

                    <p class="mx-auto mt-4 max-w-xl text-sm sm:text-base leading-relaxed text-white/75">
                        Buy ChatGPT, Canva, CapCut, Cursor and more with local payment methods. Clear prices, live stock and delivery straight to your order page.
                    </p>

                    <form action="{{ route('home') }}#products" method="get" role="search" class="mx-auto mt-7 flex max-w-xl items-center gap-2 rounded-xl bg-white p-1.5 shadow-2xl">
                        <label for="hero-search" class="sr-only">Search products</label>
                        <svg class="ml-2.5 h-5 w-5 shrink-0 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input id="hero-search" type="search" name="q" placeholder="Search e.g. ChatGPT, Canva, CapCut…" class="min-w-0 flex-1 border-0 bg-transparent px-1 py-2.5 text-sm text-ink placeholder:text-muted focus:outline-none focus:ring-0">
                        <button type="submit" class="btn btn-primary shrink-0 px-5 py-2.5 text-sm font-bold">Search</button>
                    </form>

                    <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                        <a href="#products" class="btn bg-white text-ink hover:bg-brand-tint px-6 py-3 text-sm font-bold shadow-md">Browse All Products ↓</a>
                        @if ($waNumber !== '')
                            <a href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener" class="btn border border-white/25 text-white hover:bg-white/10 px-6 py-3 text-sm font-semibold">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.5 14.4c-.3-.1-1.8-.9-2-1s-.5-.1-.7.1-.8 1-1 1.2-.4.2-.7.1a8.2 8.2 0 0 1-4-3.5c-.3-.5.3-.5.9-1.6.1-.2 0-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.9.4 3.6 3.6 0 0 0-1.1 2.7 6.3 6.3 0 0 0 1.3 3.3 14.4 14.4 0 0 0 5.5 4.9c2 .9 2.8 1 3.9.8a3.3 3.3 0 0 0 2.1-1.5 2.7 2.7 0 0 0 .2-1.5c-.1-.1-.3-.2-.6-.3zM12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2z"/></svg>
                                Ask on WhatsApp
                            </a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Payment strip --}}
            @if ($paymentMethods !== [])
                <div class="relative border-t border-white/10 bg-black/25">
                    <div class="container-x flex flex-col items-center gap-2 py-3.5 sm:flex-row sm:justify-between">
                        <p class="shrink-0 text-[11px] font-semibold tracking-wider text-white/50 uppercase">Pay securely with</p>
                        <div class="flex flex-wrap items-center justify-center gap-1.5 sm:justify-end">
                            @foreach ($paymentMethods as $pm)
                                <span class="rounded-md border border-white/10 bg-white/[.06] px-2.5 py-1 text-[11px] sm:text-xs font-semibold text-white/85">{{ $pm }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </section>
    @endif

    {{-- ================= CATALOG ================= --}}
    <section id="products" class="container-x scroll-mt-16 py-8 sm:py-12">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <span class="eyebrow">{{ $search ? 'Search' : ($activeCategory ? 'Category' : 'Catalog') }}</span>
                <h2 class="display mt-1 text-3xl sm:text-5xl">
                    @if ($search)
                        Results for “{{ $search }}”
                    @elseif ($activeCategory)
                        {{ $activeCategory->name }}
                    @else
                        All Products
                    @endif
                </h2>
            </div>
            <p class="text-xs sm:text-sm text-muted">
                {{ $products->total() }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }}
                @if ($search || $activeCategory)
                    · <a href="{{ route('home') }}#products" class="font-semibold text-brand hover:underline">Clear filter</a>
                @endif
            </p>
        </div>

        {{-- Filter bar --}}
        <div class="card mt-4 p-3 sm:p-3.5">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <nav class="-mx-1 flex items-center gap-1.5 overflow-x-auto px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Categories">
                    <a href="{{ route('home') }}#products" @class([
                        'shrink-0 rounded-full border px-3.5 py-1.5 text-xs sm:text-sm font-semibold transition',
                        'border-brand bg-brand text-white' => ! $activeCategory,
                        'border-line-strong bg-white text-ink hover:border-brand hover:text-brand' => $activeCategory,
                    ])>All</a>
                    @foreach ($categories as $category)
                        <a href="{{ route('categories.show', $category) }}#products" @class([
                            'shrink-0 rounded-full border px-3.5 py-1.5 text-xs sm:text-sm font-semibold transition whitespace-nowrap',
                            'border-brand bg-brand text-white' => $activeCategory?->is($category),
                            'border-line-strong bg-white text-ink hover:border-brand hover:text-brand' => ! $activeCategory?->is($category),
                        ])>{{ $category->name }}</a>
                    @endforeach
                </nav>

                <form action="{{ $activeCategory ? route('categories.show', $activeCategory) : route('home') }}#products" method="get" class="w-full md:w-72 md:shrink-0" role="search">
                    <label for="catalog-search" class="sr-only">Search products</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input id="catalog-search" type="search" name="q" value="{{ $search }}" placeholder="Search products…" class="input py-2 pl-10 text-sm">
                    </div>
                </form>
            </div>
        </div>

        @if ($products->isEmpty())
            <div class="card mt-6 p-10 text-center sm:p-14">
                <p class="display text-3xl">No products found</p>
                <p class="mt-2 text-sm">Try a different search or browse all categories.</p>
                <a href="{{ route('home') }}#products" class="btn btn-primary mt-5">View all products</a>
            </div>
        @else
            <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 xl:grid-cols-4">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
            <div class="mt-10">{{ $products->links() }}</div>
        @endif
    </section>

    @if ($isLanding)
        {{-- ================= HOW IT WORKS ================= --}}
        <section class="border-t border-line bg-white py-12 sm:py-16" aria-labelledby="how-heading">
            <div class="container-x">
                <div class="mx-auto max-w-2xl text-center">
                    <span class="eyebrow">Simple process</span>
                    <h2 id="how-heading" class="display mt-1 text-3xl sm:text-5xl">How it works</h2>
                </div>
                <ol class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        ['Choose a product', 'Pick the tool and quantity. Stock is reserved for you during checkout.'],
                        ['Pay locally', 'Send the exact amount via your preferred wallet, bank or Binance.'],
                        ['Submit Transaction ID', 'Enter your TID. Auto methods verify instantly; others are checked by our team.'],
                        ['Get your product', 'Credentials appear on your private order page and in your account.'],
                    ] as $i => [$title, $text])
                        <li class="card relative p-5">
                            <span class="display flex h-10 w-10 items-center justify-center rounded-lg bg-brand text-xl text-white">{{ $i + 1 }}</span>
                            <h3 class="mt-4 text-base font-bold">{{ $title }}</h3>
                            <p class="mt-1 text-sm leading-relaxed">{{ $text }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- ================= FAQ + SUPPORT ================= --}}
        <section class="border-t border-line py-12 sm:py-16" aria-labelledby="faq-heading">
            <div class="container-x grid gap-8 lg:grid-cols-[1fr_380px]">
                <div>
                    <span class="eyebrow">Quick answers</span>
                    <h2 id="faq-heading" class="display mt-1 text-3xl sm:text-5xl">Frequently asked questions</h2>
                    <div class="mt-6 space-y-2.5">
                        @foreach ($faqs as $faq)
                            <details class="group card p-4 sm:p-5" @if ($loop->first) open @endif>
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm sm:text-base font-bold text-ink">
                                    <span>{{ $faq['q'] }}</span>
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-tint text-lg leading-none text-brand transition group-open:rotate-45">+</span>
                                </summary>
                                <p class="mt-2.5 text-sm leading-relaxed text-body">{{ $faq['a'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>

                <aside class="lg:pt-14">
                    <div class="overflow-hidden rounded-2xl bg-[#120408] p-6 text-white lg:sticky lg:top-24">
                        <p class="display text-3xl text-white">Need help choosing?</p>
                        <p class="mt-2 text-sm text-white/70">Message us with the tool you need and we'll tell you the right plan, price and delivery time.</p>
                        <div class="mt-5 space-y-2">
                            @if ($waNumber !== '')
                                <a href="https://wa.me/{{ $waNumber }}?text={{ urlencode('Assalam o Alaikum! I need help choosing a product on '.$storeName.'.') }}" target="_blank" rel="noopener" class="btn w-full bg-[#25D366] py-3 font-bold text-white hover:bg-[#1ebe5b]">
                                    Chat on WhatsApp
                                </a>
                            @endif
                            <a href="{{ route('orders.track') }}" class="btn w-full border border-white/20 py-3 text-white hover:bg-white/10">Track an existing order</a>
                        </div>
                    </div>
                </aside>
            </div>
        </section>
    @endif
</x-layouts.store>
