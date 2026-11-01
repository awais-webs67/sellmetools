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
            'q' => 'How fast will I receive my ChatGPT Plus, Canva Pro or CapCut Pro order?',
            'a' => 'In-stock subscriptions are delivered automatically on your private order page as soon as your payment is verified. Manual wallet transfers are normally checked within 15 minutes during working hours, and you can track live status on your order page.',
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
        <section class="relative bg-[#120408] text-white">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(155,27,48,.55),transparent_55%),radial-gradient(ellipse_at_bottom_left,rgba(94,16,33,.6),transparent_50%)]" aria-hidden="true"></div>
            <div class="pointer-events-none absolute inset-0 opacity-[.07] [background-image:linear-gradient(rgba(255,255,255,.6)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.6)_1px,transparent_1px)] [background-size:40px_40px]" aria-hidden="true"></div>

            <div class="container-x relative py-12 sm:py-16 lg:py-20 text-center">
                <div class="mx-auto max-w-4xl">
                    <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3.5 py-1 text-[11px] font-semibold tracking-wider text-white/90 uppercase">
                        <span class="h-2 w-2 rounded-full bg-[#22c55e] live-dot"></span>
                        Verified Digital Store
                    </span>

                    <h1 class="display mt-4 text-[clamp(34px,7vw,80px)] leading-[0.95] text-white">
                        Premium AI Tools &amp;<br class="hidden sm:block">
                        <span class="text-[#ff6b81]">Subscriptions</span> in Pakistan
                    </h1>

                    <p class="mx-auto mt-4 max-w-2xl text-sm sm:text-base leading-relaxed text-white/75">
                        Buy genuine ChatGPT Plus, Canva Pro, CapCut Pro, Claude, Gemini, Cursor and Netflix at local PKR prices. Pay easily with Easypaisa, JazzCash, NayaPay, SadaPay or Binance for instant delivery on your order page.
                    </p>

                    <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                        <a href="#products" class="btn bg-white text-ink hover:bg-brand-tint px-6 py-3 text-sm font-bold shadow-md">Browse All Products ↓</a>
                        @if ($waNumber !== '')
                            <a href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener" class="btn border border-white/25 text-white hover:bg-white/10 px-6 py-3 text-sm font-semibold">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.5 14.4c-.3-.1-1.8-.9-2-1s-.5-.1-.7.1-.8 1-1 1.2-.4.2-.7.1a8.2 8.2 0 0 1-4-3.5c-.3-.5.3-.5.9-1.6.1-.2 0-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.9.4 3.6 3.6 0 0 0-1.1 2.7 6.3 6.3 0 0 0 1.3 3.3 14.4 14.4 0 0 0 5.5 4.9c2 .9 2.8 1 3.9.8a3.3 3.3 0 0 0 2.1-1.5 2.7 2.7 0 0 0 .2-1.5c-.1-.1-.3-.2-.6-.3zM12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2z"/></svg>
                                Ask on WhatsApp
                            </a>
                        @endif
                    </div>

                    @if ($categories->isNotEmpty())
                        <nav class="mx-auto mt-5 flex max-w-xl flex-wrap items-center justify-center gap-x-2 gap-y-2 text-xs" aria-label="Popular categories">
                            <span class="text-white/55">Popular:</span>
                            @foreach ($categories->take(5) as $category)
                                <a href="{{ route('categories.show', $category) }}#products" class="rounded-full border border-white/15 bg-white/[.06] px-3 py-1 font-semibold text-white/85 transition hover:border-white/40 hover:bg-white/10 active:scale-95">{{ $category->name }}</a>
                            @endforeach
                        </nav>
                    @endif
                </div>
            </div>

            {{-- Payment strip --}}
            @if ($paymentMethods !== [])
                <div class="relative border-t border-white/10 bg-black/25">
                    <div class="container-x flex flex-col items-center justify-center gap-2.5 py-4 sm:flex-row sm:gap-4">
                        <p class="shrink-0 text-[11px] font-semibold tracking-wider text-white/50 uppercase">Pay securely with</p>
                        <div class="flex flex-wrap items-center justify-center gap-1.5">
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
                    <x-product-card :product="$product" />
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

        {{-- ================= SEO GUIDE (visible, helpful text + keyword internal links) ================= --}}
        <section class="border-t border-line bg-white py-12 sm:py-16" aria-labelledby="guide-heading">
            <div class="container-x grid gap-8 lg:grid-cols-[1fr_1fr] lg:gap-14">
                <div>
                    <span class="eyebrow">Buying guide</span>
                    <h2 id="guide-heading" class="display mt-1 text-3xl sm:text-4xl">Buy AI tools &amp; digital subscriptions in Pakistan at local PKR prices</h2>
                    <div class="prose-store mt-4">
                        <p>International USD billing for ChatGPT Plus, Claude Pro, Google AI Pro (Gemini), Canva Pro, CapCut Pro, Cursor or Microsoft 365 often fails on Pakistani debit cards and adds foreign transaction taxes. {{ $storeName }} makes it simple for freelancers, students, creators and agencies across Pakistan to buy verified digital subscriptions in local rupees.</p>
                        <p>You can pay with Easypaisa, JazzCash, NayaPay, SadaPay, bank transfer or Binance USDT. Every product page displays the live price in Pakistan, available stock count and warranty coverage upfront, with credentials delivered straight to your private order page and email.</p>
                    </div>
                </div>
                <div>
                    <h3 class="text-sm font-bold uppercase tracking-wider text-muted">Popular price checks</h3>
                    <ul class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        @foreach ($products->getCollection()->take(10) as $p)
                            <li><a href="{{ route('products.show', $p) }}" class="font-medium text-ink transition hover:text-brand hover:underline">{{ $p->name }} price in Pakistan</a></li>
                        @endforeach
                    </ul>
                    @if ($categories->isNotEmpty())
                        <h3 class="mt-6 text-sm font-bold uppercase tracking-wider text-muted">Browse by category</h3>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($categories as $category)
                                <a href="{{ route('categories.show', $category) }}" class="rounded-full border border-line-strong px-3 py-1.5 text-xs font-semibold text-ink transition hover:border-brand hover:text-brand active:scale-95">{{ $category->name }}</a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif
</x-layouts.store>