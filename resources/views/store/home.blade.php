@php
    use App\Models\PaymentMethod;
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

    // Customer-facing wallet names only (internal methods hidden, "(...)" notes stripped).
    $paymentMethods = $isLanding
        ? Cache::remember('home.payment_methods.v3', 600, fn () => PaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->pluck('name')
            ->reject(fn ($n) => \Illuminate\Support\Str::contains(strtolower($n), ['wallet balance', 'manual', 'whatsapp']))
            ->map(fn ($n) => trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $n)))
            ->unique()
            ->values()
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
            'q' => 'How fast will I receive my digital tool or subscription order?',
            'a' => 'In-stock subscriptions and accounts are delivered automatically on your private order page as soon as your payment is verified. Manual wallet transfers are normally checked within 15 minutes during working hours, and you can track live status on your order page.',
        ],
        [
            'q' => 'Which Pakistani payment methods do you accept?',
            'a' => 'You can pay in PKR using Easypaisa, JazzCash, NayaPay, SadaPay or local bank transfer, as well as USDT via Binance Pay. You do not need an international credit or debit card, and every Transaction ID is protected against duplicate use.',
        ],
        [
            'q' => 'Where do I get my login credentials or activation link after buying?',
            'a' => 'Your delivered account details, invite link or activation steps appear directly on your private order page and are also sent to your email. If you create a customer account, all of your past orders and credentials stay saved in one dashboard.',
        ],
        [
            'q' => 'Do subscriptions include a replacement warranty?',
            'a' => 'Yes. Every product page clearly states its plan type and warranty coverage. If you face any login or subscription issue during the covered period, message our support team on WhatsApp with your Order ID for a fast fix or replacement.',
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

    $categoryBreadcrumbSchema = $activeCategory ? [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Home',
                'item' => route('home'),
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => 'Shop',
                'item' => route('shop'),
            ],
            [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $activeCategory->name,
                'item' => route('categories.show', $activeCategory),
            ],
        ],
    ] : null;
@endphp
@push('seo')
    <script type="application/ld+json">{!! json_encode($itemListSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @if ($isLanding)
        <script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @elseif ($categoryBreadcrumbSchema)
        <script type="application/ld+json">{!! json_encode($categoryBreadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
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
        <section class="surface-hero relative overflow-hidden text-white">
            {{-- Subtle architectural grid --}}
            <div class="pointer-events-none absolute inset-0 opacity-[.08] [background-image:linear-gradient(rgba(255,255,255,.5)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.5)_1px,transparent_1px)] [background-size:44px_44px] [mask-image:radial-gradient(ellipse_at_center,black_30%,transparent_78%)]" aria-hidden="true"></div>

            <div class="container-x relative py-11 sm:py-16 lg:py-20 text-center">
                <div class="mx-auto max-w-4xl">
                    <div class="inline-flex items-center gap-2.5 rounded-full border border-white/20 bg-white/[0.06] px-4 py-1.5 text-[11px] font-semibold tracking-wider text-white uppercase">
                        <span class="h-2 w-2 rounded-full bg-[#22c55e] live-dot"></span>
                        <span>Verified Digital Store</span>
                        <span class="hidden sm:inline text-white/35">·</span>
                        <span class="hidden sm:inline text-dark-accent">Instant Automated Delivery</span>
                    </div>

                    <h1 class="display mt-5 text-[clamp(36px,7.2vw,84px)] leading-[0.94] tracking-tight text-white">
                        Premium AI Tools &amp; <br class="hidden sm:block"><span class="hero-highlight">Subscriptions</span> in Pakistan
                    </h1>

                    <p class="mx-auto mt-4 max-w-2xl text-sm sm:text-base leading-relaxed text-white/75">
                        Buy genuine AI tools, creative software, cloud accounts and digital subscriptions at local PKR prices. Pay easily with Easypaisa, JazzCash, NayaPay, SadaPay or Binance for instant delivery on your order page.
                    </p>

                    <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                        <a href="#products" class="btn btn-hero-primary px-6 py-3 text-sm font-bold">
                            Browse All Products ↓
                        </a>
                        <a href="{{ route('shop') }}" class="btn btn-hero-secondary px-6 py-3 text-sm font-bold">
                            Open Full Shop →
                        </a>
                        @if ($waNumber !== '')
                            <a href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener" class="btn border border-white/20 bg-white/[0.04] text-white hover:bg-white/12 px-5 py-3 text-sm font-semibold backdrop-blur-xs">
                                <svg class="h-4 w-4 text-[#25D366]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.5 14.4c-.3-.1-1.8-.9-2-1s-.5-.1-.7.1-.8 1-1 1.2-.4.2-.7.1a8.2 8.2 0 0 1-4-3.5c-.3-.5.3-.5.9-1.6.1-.2 0-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.9.4 3.6 3.6 0 0 0-1.1 2.7 6.3 6.3 0 0 0 1.3 3.3 14.4 14.4 0 0 0 5.5 4.9c2 .9 2.8 1 3.9.8a3.3 3.3 0 0 0 2.1-1.5 2.7 2.7 0 0 0 .2-1.5c-.1-.1-.3-.2-.6-.3zM12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2z"/></svg>
                                Ask on WhatsApp
                            </a>
                        @endif
                    </div>

                    @if ($categories->isNotEmpty())
                        <nav class="mx-auto mt-6 hidden sm:flex max-w-2xl flex-wrap items-center justify-center gap-2 text-xs" aria-label="Popular categories">
                            <span class="font-medium text-white/55">Categories:</span>
                            @foreach ($categories->take(6) as $category)
                                <a href="{{ route('categories.show', $category) }}#products" class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/[.07] px-3.5 py-1 font-semibold text-white/90 transition hover:border-white/50 hover:bg-white/15 active:scale-95">
                                    @if ($category->icon)
                                        <span>{{ $category->icon }}</span>
                                    @endif
                                    <span>{{ $category->name }}</span>
                                </a>
                            @endforeach
                        </nav>
                    @endif

                    {{-- Desktop Trust Highlights Strip (hidden on mobile to keep mobile hero compact) --}}
                    <div class="mx-auto mt-9 hidden sm:grid max-w-3xl grid-cols-3 gap-3 text-left">
                        <div class="flex items-center gap-3 rounded-xl border border-white/12 bg-white/[0.05] px-4 py-3 backdrop-blur-xs">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 border border-white/20 text-base text-white">⚡</span>
                            <div class="min-w-0">
                                <p class="truncate text-xs font-bold text-white">Instant Order Page</p>
                                <p class="truncate text-[11px] text-white/65">Auto credentials delivery</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 rounded-xl border border-white/12 bg-white/[0.05] px-4 py-3 backdrop-blur-xs">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 border border-white/20 text-base text-white">🇵🇰</span>
                            <div class="min-w-0">
                                <p class="truncate text-xs font-bold text-white">100% Local PKR</p>
                                <p class="truncate text-[11px] text-white/65">No USD card tax required</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 rounded-xl border border-white/12 bg-white/[0.05] px-4 py-3 backdrop-blur-xs">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 border border-white/20 text-base text-white">🛡️</span>
                            <div class="min-w-0">
                                <p class="truncate text-xs font-bold text-white">Verified Warranty</p>
                                <p class="truncate text-[11px] text-white/65">24/7 WhatsApp helpdesk</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Payment strip (Desktop/Tablet only as requested) --}}
            @if ($paymentMethods !== [])
                <div class="relative hidden sm:block border-t border-white/10 bg-black/30 backdrop-blur-xs">
                    <div class="container-x flex flex-col items-center justify-center gap-2.5 py-3.5 sm:flex-row sm:gap-4">
                        <p class="shrink-0 text-[11px] font-semibold tracking-wider text-white/50 uppercase">Pay securely with</p>
                        <div class="flex flex-wrap items-center justify-center gap-1.5">
                            @foreach ($paymentMethods as $pm)
                                <span class="rounded-md border border-white/12 bg-white/[.07] px-2.5 py-1 text-[11px] sm:text-xs font-semibold text-white/90">{{ $pm }}</span>
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
                @if ($isLanding)
                    <h2 class="display mt-1 text-3xl sm:text-5xl">All Products</h2>
                @else
                    <h1 class="display mt-1 text-3xl sm:text-5xl">
                        @if ($search)
                            Results for “{{ $search }}”
                        @elseif ($activeCategory)
                            {{ $activeCategory->name }} Price in Pakistan
                        @else
                            All Products
                        @endif
                    </h1>
                @endif
            </div>
            <div class="flex items-center gap-3">
                <p class="text-xs sm:text-sm text-muted">
                    {{ $products->total() }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }}
                    @if ($search || $activeCategory)
                        · <a href="{{ route('home') }}#products" class="font-semibold text-brand hover:underline">Clear filter</a>
                    @endif
                </p>
                <a href="{{ route('shop') }}" class="text-xs sm:text-sm font-bold text-brand hover:underline">Full Shop →</a>
            </div>
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

                <form action="{{ $activeCategory ? route('categories.show', $activeCategory) : route('home') }}#products" method="get" class="w-full md:w-72 md:shrink-0" role="search"
                      x-data="liveFilter('#catalog-results')" @submit.prevent="run()">
                    <label for="catalog-search" class="sr-only">Search products</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input id="catalog-search" type="search" name="q" value="{{ $search }}" placeholder="Filter products as you type" autocomplete="off" class="input py-2 pl-10 pr-9 text-sm" @input.debounce.300ms="run()">
                        <span x-show="loading" x-cloak class="absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 animate-spin rounded-full border-2 border-line border-t-brand" aria-hidden="true"></span>
                    </div>
                </form>
            </div>
        </div>

        <div id="catalog-results" aria-live="polite">
        @if ($products->isEmpty())
            <div class="card mt-6 p-10 text-center sm:p-14">
                <p class="display text-3xl">No products found</p>
                <p class="mt-2 text-sm">Try a different search or browse all categories.</p>
                <a href="{{ route('home') }}#products" class="btn btn-primary mt-5">View all products</a>
            </div>
        @else
            <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 xl:grid-cols-4">
                @foreach ($products as $product)
                    <x-product-card :product="$product" :priority="$loop->index < 2" />
                @endforeach
            </div>
            <div class="mt-10">{{ $products->links() }}</div>
        @endif
        </div>
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
                    <div class="surface-dark-solid overflow-hidden rounded-2xl p-6 text-white lg:sticky lg:top-24">
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

        {{-- ================= ABOUT & TRUST SECTION ================= --}}
        <section class="surface-banner border-t border-line text-white py-14 sm:py-20" aria-labelledby="about-heading">
            <div class="container-x">
                <div class="grid gap-10 lg:grid-cols-[1.15fr_1fr] lg:items-center">
                    <div>
                        <span class="eyebrow text-dark-accent before:bg-current">About {{ $storeName }}</span>
                        <h2 id="about-heading" class="display mt-1.5 text-3xl sm:text-5xl text-white">Pakistan’s Trusted Marketplace for AI Tools &amp; Subscriptions</h2>
                        <div class="mt-4 space-y-3.5 text-sm sm:text-base leading-relaxed text-white/80">
                            <p>
                                International software, AI platforms, creative suites and cloud services normally charge in USD and require international credit cards that carry heavy cross-border banking taxes or fail outright in Pakistan.
                            </p>
                            <p>
                                Built by <strong>AivraSol Softwares</strong>, {{ $storeName }} bridges that gap by offering verified digital tools, accounts and subscriptions in Pakistani Rupees (PKR). Whether you are a student completing university research, a creator producing client projects, a marketer, or a software developer, our automated platform reserves your stock at checkout and delivers credentials directly to your private order page.
                            </p>
                        </div>
                        <div class="mt-6 flex flex-wrap items-center gap-3">
                            <a href="{{ route('pages.show', 'about') }}" class="btn bg-white text-ink hover:bg-brand-tint px-5 py-2.5 text-xs sm:text-sm font-bold">Read More About Us →</a>
                            <a href="{{ route('pages.show', 'contact') }}" class="btn border border-white/25 text-white hover:bg-white/10 px-5 py-2.5 text-xs sm:text-sm font-semibold">Contact Support Desk</a>
                        </div>
                    </div>

                    <div class="grid gap-3.5 sm:grid-cols-2">
                        @foreach ([
                            ['100% Local PKR Wallets', 'Pay with Easypaisa, JazzCash, NayaPay, SadaPay, Raast bank transfer or Binance USDT without card declines.'],
                            ['Automated Order Delivery', 'In-stock credentials, invite links and license keys are delivered to your private order page and email upon verification.'],
                            ['Clear Warranty Protection', 'Every listing clearly states whether it includes Full Warranty (FW) or Login Warranty, backed by our WhatsApp team.'],
                            ['Permanent Order Vault', 'Sign in to your customer dashboard anytime to filter orders by date and view all past credentials in one place.'],
                        ] as [$cardTitle, $cardDesc])
                            <div class="rounded-2xl border border-white/12 bg-white/[0.05] p-5 backdrop-blur-xs">
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-brand text-sm font-bold text-white">✓</span>
                                <h3 class="mt-3 text-base font-bold text-white">{{ $cardTitle }}</h3>
                                <p class="mt-1.5 text-xs sm:text-sm leading-relaxed text-white/70">{{ $cardDesc }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        {{-- ================= SEO BUYING GUIDE & STRUCTURED PRICE DIRECTORY ================= --}}
        <section class="border-t border-line bg-white py-14 sm:py-20" aria-labelledby="guide-heading">
            <div class="container-x">
                <div class="grid gap-10 lg:grid-cols-[1.15fr_1fr] lg:items-start">
                    <div>
                        <span class="eyebrow">Pakistan Buying Guide</span>
                        <h2 id="guide-heading" class="display mt-1 text-3xl sm:text-5xl text-ink">Buy AI Tools &amp; Digital Subscriptions in Pakistan at Local PKR Prices</h2>
                        <div class="prose-store mt-4 space-y-4 text-sm sm:text-base leading-relaxed text-body">
                            <p>
                                Finding genuine digital subscriptions in Pakistan at fair local prices should not require risky social media deals or complicated dollar cards. On <strong>{{ $storeName }}</strong>, every product page shows the exact price in PKR, real-time stock count, delivery format and warranty terms before you place an order.
                            </p>
                        </div>

                        <div class="mt-6 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-xl border border-line bg-grey-soft/60 p-4 sm:p-5">
                                <h3 class="text-sm sm:text-base font-bold text-ink">AI Assistants, Coding &amp; Research</h3>
                                <p class="mt-1.5 text-xs sm:text-sm leading-relaxed text-body">
                                    Upgrade your workflow with premium AI assistants, coding companions, academic research tools and automation platforms at local Pakistani rates.
                                </p>
                            </div>
                            <div class="rounded-xl border border-line bg-grey-soft/60 p-4 sm:p-5">
                                <h3 class="text-sm sm:text-base font-bold text-ink">Design, Video &amp; Creative Suites</h3>
                                <p class="mt-1.5 text-xs sm:text-sm leading-relaxed text-body">
                                    Get official team invites, pro accounts and creative asset subscriptions so your agency or freelance studio can export high-resolution projects without limits.
                                </p>
                            </div>
                            <div class="rounded-xl border border-line bg-grey-soft/60 p-4 sm:p-5">
                                <h3 class="text-sm sm:text-base font-bold text-ink">Streaming, Mail &amp; Cloud Accounts</h3>
                                <p class="mt-1.5 text-xs sm:text-sm leading-relaxed text-body">
                                    Order verified streaming memberships, productivity suites, ready mail accounts and developer cloud access with instant delivery on your private order page.
                                </p>
                            </div>
                            <div class="rounded-xl border border-line bg-grey-soft/60 p-4 sm:p-5">
                                <h3 class="text-sm sm:text-base font-bold text-ink">Instant PKR Wallet Checkout</h3>
                                <p class="mt-1.5 text-xs sm:text-sm leading-relaxed text-body">
                                    Pay in rupees from anywhere in Pakistan via <strong>Easypaisa, JazzCash, NayaPay, SadaPay</strong> or <strong>Binance Pay (USDT)</strong> and track verification live on screen.
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- Structured Live Price Directory Card (replaces old unstyled floating list) --}}
                    <div class="card overflow-hidden border border-line bg-white shadow-sm" x-data>
                        <div class="surface-dark-solid flex items-center justify-between gap-3 border-b border-line px-5 py-4 text-white">
                            <div>
                                <span class="text-[10px] font-bold tracking-widest text-dark-accent uppercase" x-text="$store.currency.isUsdt ? 'Live USDT Directory' : 'Live PKR Directory'">Live PKR Directory</span>
                                <h3 class="display text-2xl text-white">Popular Price Checks in Pakistan</h3>
                            </div>
                            <a href="{{ route('shop') }}" class="rounded-lg border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/20">
                                Full Catalog →
                            </a>
                        </div>

                        <div class="divide-y divide-line">
                            @forelse ($products->getCollection()->take(8) as $p)
                                @php $dirPrice = (float) $p->priceFor(auth()->user()); @endphp
                                <a href="{{ route('products.show', $p) }}" class="group flex items-center justify-between gap-3 px-5 py-3.5 transition hover:bg-brand-tint/40">
                                    <div class="min-w-0">
                                        <p class="truncate text-xs sm:text-sm font-bold text-ink group-hover:text-brand">
                                            {{ $p->name }} <span class="font-normal text-muted">price in Pakistan</span>
                                        </p>
                                        <div class="mt-0.5 flex items-center gap-2 text-[11px] text-muted">
                                            @if ($p->category)
                                                <span class="font-semibold text-brand">{{ $p->category->name }}</span>
                                                <span>·</span>
                                            @endif
                                            <span class="{{ $p->inStock() ? 'text-success font-medium' : 'text-danger' }}">
                                                {{ $p->inStock() ? 'In Stock' : 'Out of Stock' }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        @if ($p->isFree())
                                            <span class="rounded-lg bg-success/10 px-2.5 py-1 text-xs sm:text-sm font-bold text-success group-hover:bg-brand group-hover:text-white transition">
                                                🎁 FREE
                                            </span>
                                        @elseif ($dirPrice > 0)
                                            <span class="rounded-lg bg-grey-soft px-2.5 py-1 text-xs sm:text-sm font-bold text-ink group-hover:bg-brand group-hover:text-white transition"
                                                  x-text="$store.currency.format({{ $dirPrice }})">
                                                {{ money($dirPrice) }}
                                            </span>
                                        @else
                                            <span class="rounded-lg bg-grey-soft px-2.5 py-1 text-xs font-semibold text-muted group-hover:bg-brand group-hover:text-white transition">
                                                View Plan
                                            </span>
                                        @endif
                                        <svg class="h-4 w-4 text-muted transition group-hover:translate-x-0.5 group-hover:text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                                    </div>
                                </a>
                            @empty
                                <p class="px-5 py-6 text-sm text-muted">Products will appear here once added.</p>
                            @endforelse
                        </div>

                        @if ($categories->isNotEmpty())
                            <div class="border-t border-line bg-grey-soft/60 px-5 py-4">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-muted">Browse by Category</p>
                                <div class="mt-2.5 flex flex-wrap gap-1.5">
                                    @foreach ($categories as $category)
                                        <a href="{{ route('categories.show', $category) }}" class="inline-flex items-center gap-1 rounded-full border border-line-strong bg-white px-3 py-1 text-xs font-semibold text-ink transition hover:border-brand hover:text-brand active:scale-95">
                                            @if ($category->icon)
                                                <span>{{ $category->icon }}</span>
                                            @endif
                                            <span>{{ $category->name }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @elseif ($activeCategory)
        {{-- ================= CATEGORY SEO BUYING GUIDE ================= --}}
        <section class="border-t border-line bg-white py-12 sm:py-16" aria-labelledby="category-guide-heading">
            <div class="container-x">
                <div class="grid gap-8 lg:grid-cols-[1.25fr_1fr] lg:items-start">
                    <div>
                        <span class="eyebrow">{{ $activeCategory->name }} in Pakistan</span>
                        <h2 id="category-guide-heading" class="display mt-1 text-2xl sm:text-4xl text-ink">
                            Buy Genuine {{ $activeCategory->name }} Subscriptions in Pakistan with Local PKR Wallets
                        </h2>
                        <div class="prose-store mt-4 space-y-3.5 text-sm sm:text-base leading-relaxed text-body">
                            <p>
                                Looking for verified <strong>{{ $activeCategory->name }}</strong> plans and accounts in Pakistan without paying steep international credit card markups or cross-border banking taxes? On <strong>{{ $storeName }}</strong>, you can compare live PKR and USDT pricing, check real-time stock availability, and order genuine {{ strtolower($activeCategory->name) }} tools with automated delivery directly on your private order page.
                            </p>
                            <p>
                                Every {{ strtolower($activeCategory->name) }} listing on {{ $storeName }} displays its exact plan type, duration, and replacement warranty terms upfront. You can complete checkout in seconds using <strong>Easypaisa, JazzCash, NayaPay, SadaPay, local bank transfer (Raast)</strong>, or <strong>Binance Pay (USDT)</strong>, and access your saved credentials anytime from your customer dashboard.
                            </p>
                        </div>

                        <div class="mt-6 grid gap-3.5 sm:grid-cols-3">
                            <div class="rounded-xl border border-line bg-grey-soft/60 p-4">
                                <h3 class="text-xs sm:text-sm font-bold text-ink">⚡ Instant Order Page Delivery</h3>
                                <p class="mt-1 text-xs leading-relaxed text-body">
                                    In-stock {{ strtolower($activeCategory->name) }} credentials and activation links appear on your private order page as soon as payment is verified.
                                </p>
                            </div>
                            <div class="rounded-xl border border-line bg-grey-soft/60 p-4">
                                <h3 class="text-xs sm:text-sm font-bold text-ink">🇵🇰 Local PKR &amp; USDT Checkout</h3>
                                <p class="mt-1 text-xs leading-relaxed text-body">
                                    Pay in Pakistani Rupees via Easypaisa, JazzCash, NayaPay, SadaPay, or in USDT via Binance Pay with zero hidden card fees.
                                </p>
                            </div>
                            <div class="rounded-xl border border-line bg-grey-soft/60 p-4">
                                <h3 class="text-xs sm:text-sm font-bold text-ink">🛡️ Clear Warranty &amp; Support</h3>
                                <p class="mt-1 text-xs leading-relaxed text-body">
                                    Backed by AivraSol Softwares with dedicated WhatsApp support and clear warranty coverage on every digital tool.
                                </p>
                            </div>
                        </div>
                    </div>

                    <aside class="card border border-line bg-grey-soft/50 p-5 sm:p-6">
                        <h3 class="display text-xl sm:text-2xl text-ink">Explore Other Categories</h3>
                        <p class="mt-1 text-xs sm:text-sm text-muted">Browse all digital subscription categories available in Pakistan:</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach ($categories as $cat)
                                <a href="{{ route('categories.show', $cat) }}" @class([
                                    'inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition',
                                    'border-brand bg-brand text-white' => $activeCategory->is($cat),
                                    'border-line-strong bg-white text-ink hover:border-brand hover:text-brand' => ! $activeCategory->is($cat),
                                ])>
                                    @if ($cat->icon)
                                        <span>{{ $cat->icon }}</span>
                                    @endif
                                    <span>{{ $cat->name }}</span>
                                </a>
                            @endforeach
                        </div>
                        <div class="mt-5 border-t border-line pt-4 flex flex-wrap items-center justify-between gap-2 text-xs">
                            <a href="{{ route('shop') }}" class="font-bold text-brand hover:underline">Browse Full Shop Catalog →</a>
                            <a href="{{ route('pages.show', 'faq') }}" class="font-semibold text-muted hover:text-ink">Read Buying &amp; Warranty FAQ →</a>
                        </div>
                    </aside>
                </div>
            </div>
        </section>
    @endif
</x-layouts.store>