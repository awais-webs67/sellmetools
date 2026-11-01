@php
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

    $itemListSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => $activeCategory ? $activeCategory->name : 'Featured Digital Tools & Subscriptions in Pakistan',
        'itemListElement' => $products->getCollection()->values()->map(fn ($p, $idx) => [
            '@type' => 'ListItem',
            'position' => $idx + 1,
            'url' => route('products.show', $p),
            'name' => $p->name,
        ])->all(),
    ];

    $faqs = [
        [
            'q' => 'How fast do I receive my subscription credentials after payment?',
            'a' => 'Delivery is 100% automated and instant! As soon as your payment via Easypaisa, JazzCash, NayaPay, SadaPay, or Binance Pay is verified, your login credentials or activation link appear immediately on your order screen and are saved permanently in your Customer Panel.',
        ],
        [
            'q' => 'Which payment methods are accepted in Pakistan?',
            'a' => 'We accept Easypaisa, JazzCash, NayaPay, SadaPay, Bank Transfer / Raast IBAN, Binance Pay, USDT (TRC20), and Account Wallet Balance in Pakistani Rupees (PKR).',
        ],
        [
            'q' => 'Do all tools and subscriptions come with a replacement warranty?',
            'a' => 'Yes! Every subscription (ChatGPT Plus, Canva Pro, CapCut Pro, Netflix, Cursor, VPNs, and Cloud tools) includes our full term warranty and instant WhatsApp support.',
        ],
    ];

    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => collect($faqs)->map(fn ($f) => [
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
    <script type="application/ld+json">{!! json_encode($itemListSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @if (! $search && ! $activeCategory)
        <script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
@endpush
<x-layouts.store
    :title="$seoTitle"
    :description="$seoDescription"
    :canonical="$canonicalUrl"
    :robots="$robotsDirective"
>
    @if (! $search && ! $activeCategory && $products->onFirstPage())
        {{-- Clean, Focused Storefront Hero --}}
        <section class="relative overflow-hidden border-b border-line bg-gradient-to-br from-[#120408] via-[#2B0912] to-[#5E1021] text-white">
            <div class="pointer-events-none absolute -top-28 right-0 h-80 w-80 rounded-full bg-brand/30 blur-3xl" aria-hidden="true"></div>
            <div class="container-x relative py-8 sm:py-12">
                <div class="mx-auto max-w-3xl text-center">
                    <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3.5 py-1 text-[11px] font-semibold tracking-wider text-brand-tint-2 uppercase">
                        <span class="h-2 w-2 rounded-full bg-[#22c55e] live-dot"></span>
                        Instant Automated Delivery · Pakistan
                    </div>

                    <h1 class="display mt-3 text-[clamp(34px,5.5vw,64px)] leading-[0.96] text-white">
                        Premium AI Tools &amp; <span class="text-[#ff6b81]">Subscriptions</span>
                    </h1>

                    <p class="mx-auto mt-2.5 max-w-xl text-xs sm:text-sm leading-relaxed text-white/80">
                        Genuine ChatGPT Plus, CapCut Pro, Canva, Cursor &amp; Cloud subscriptions at wholesale PKR rates. Pay via Easypaisa, JazzCash, NayaPay, SadaPay or Binance.
                    </p>
                </div>
            </div>
        </section>
    @endif

    <section id="products" class="container-x scroll-mt-20 py-6 sm:py-10">
        {{-- Clean Category Filter + Search Bar --}}
        <div class="card mb-6 flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4">
            <div class="-mx-1 flex items-center gap-1.5 overflow-x-auto px-1 pb-1 sm:flex-wrap sm:gap-2 sm:pb-0" aria-label="Categories">
                <a href="{{ route('home') }}#products" @class(['btn shrink-0 rounded-full px-3.5 py-1.5 text-xs sm:px-4 sm:py-2 sm:text-sm', 'btn-primary' => ! $activeCategory, 'btn-outline' => $activeCategory])>
                    All Tools
                </a>
                @foreach ($categories as $category)
                    <a href="{{ route('categories.show', $category) }}#products" @class(['btn shrink-0 rounded-full px-3.5 py-1.5 text-xs sm:px-4 sm:py-2 sm:text-sm', 'btn-primary' => $activeCategory?->is($category), 'btn-outline' => ! $activeCategory?->is($category)])>
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
                    <input type="search" name="q" value="{{ $search }}" placeholder="Search tools..." class="input py-2 pl-10 text-xs sm:text-sm">
                </div>
            </form>
        </div>

        @if ($search || $activeCategory)
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <span class="eyebrow">Catalog</span>
                    <h2 class="display mt-1 text-2xl sm:text-4xl">
                        @if ($search) Search: “{{ $search }}” @else {{ $activeCategory->name }} @endif
                    </h2>
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
            <div class="grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
            <div class="mt-10">{{ $products->links() }}</div>
        @endif
    </section>

    @if (! $search && ! $activeCategory && $products->onFirstPage())
        <section class="border-t border-line bg-grey-soft/60 py-10 sm:py-14" aria-labelledby="faq-heading">
            <div class="container-x mx-auto max-w-3xl">
                <div class="text-center">
                    <span class="eyebrow">Quick Answers</span>
                    <h2 id="faq-heading" class="display mt-1 text-2xl sm:text-4xl text-ink">Frequently Asked Questions</h2>
                </div>
                <div class="mt-6 space-y-2.5">
                    @foreach ($faqs as $faq)
                        <details class="group card p-4">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-xs sm:text-sm font-bold text-ink">
                                <span>{{ $faq['q'] }}</span>
                                <span class="text-brand transition group-open:rotate-45 text-lg leading-none">+</span>
                            </summary>
                            <p class="mt-2 text-xs sm:text-sm leading-relaxed text-body">{{ $faq['a'] }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</x-layouts.store>
