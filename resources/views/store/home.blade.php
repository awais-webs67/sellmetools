@php
    use App\Models\PaymentMethod;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Str;

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

    $paymentMethods = $isLanding
        ? Cache::remember('home.payment_methods.v3', 600, fn () => PaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->pluck('name')
            ->reject(fn ($n) => Str::contains(Str::lower($n), ['wallet balance', 'manual', 'whatsapp']))
            ->map(fn ($n) => trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $n)))
            ->unique()
            ->values()
            ->all())
        : [];

    // Hero showcase uses real products from the current page — no extra queries.
    $showcase = $isLanding ? $products->getCollection()->take(3)->values() : collect();

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
        <section class="relative overflow-hidden border-b border-line bg-paper">
            <div class="pointer-events-none absolute -top-40 -right-40 h-[520px] w-[520px] rounded-full bg-[radial-gradient(closest-side,rgba(138,15,39,.10),transparent)]" aria-hidden="true"></div>

            <div class="container-x relative grid items-center gap-12 py-12 sm:py-16 lg:grid-cols-[1.1fr_.9fr] lg:gap-16 lg:py-24">
                <div>
                    <p class="eyebrow">Digital tools &amp; subscriptions</p>

                    <h1 class="display mt-4 text-[clamp(2.4rem,5.4vw,4.4rem)] leading-[1.02]">
                        Premium software,<br>
                        <span class="text-brand">priced in rupees.</span>
                    </h1>

                    <p class="mt-5 max-w-lg text-[15px] leading-relaxed text-body sm:text-base">
                        ChatGPT, Canva, CapCut, Cursor and more — paid with the wallet you already use. Clear prices, live stock, and your access delivered to a private order page.
                    </p>

                    <form action="{{ route('home') }}#products" method="get" role="search" class="mt-8 flex max-w-lg items-center gap-1 rounded-full border border-line-strong bg-white p-1.5 pl-5 transition focus-within:border-ink focus-within:ring-4 focus-within:ring-ink/5">
                        <label for="hero-search" class="sr-only">Search products</label>
                        <svg class="h-4.5 w-4.5 shrink-0 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input id="hero-search" type="search" name="q" placeholder="What are you looking for?" class="min-w-0 flex-1 border-0 bg-transparent px-2 py-2 text-[15px] text-ink placeholder:text-muted focus:outline-none focus:ring-0">
                        <button type="submit" class="btn btn-primary shrink-0 px-5">Search</button>
                    </form>

                    @if ($categories->isNotEmpty())
                        <p class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
                            <span>Popular:</span>
                            @foreach ($categories->take(4) as $category)
                                <a href="{{ route('categories.show', $category) }}#products" class="link-underline text-ink">{{ $category->name }}</a>
                            @endforeach
                        </p>
                    @endif
                </div>

                {{-- Showcase: real products, stacked like a shelf --}}
                @if ($showcase->isNotEmpty())
                    <div class="relative hidden lg:block" aria-hidden="true">
                        <div class="absolute inset-6 -rotate-3 rounded-3xl bg-brand/90"></div>
                        <div class="relative rounded-3xl border border-line bg-white p-3 shadow-[0_30px_60px_-30px_rgba(23,18,15,.35)]">
                            <div class="flex items-center justify-between px-3 pt-2 pb-3">
                                <p class="text-sm font-semibold text-ink">Available now</p>
                                <span class="inline-flex items-center gap-1.5 text-xs text-success"><span class="dot h-1.5 w-1.5 bg-success live-dot"></span>Live stock</span>
                            </div>
                            <ul class="divide-y divide-line">
                                @foreach ($showcase as $item)
                                    <li class="flex items-center gap-4 rounded-xl px-3 py-3">
                                        <div class="h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-paper">
                                            @if ($img = $item->imageUrl())
                                                <img src="{{ $img }}" alt="" class="h-full w-full object-cover" loading="lazy">
                                            @else
                                                <div class="flex h-full w-full items-center justify-center bg-[linear-gradient(140deg,#8A0F27,#3B0611)] text-sm font-semibold text-white">{{ mb_substr($item->name, 0, 1) }}</div>
                                            @endif
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $item->name }}</p>
                                            <p class="truncate text-xs text-muted">{{ $item->category?->name ?? 'Digital product' }}</p>
                                        </div>
                                        <p class="font-[family-name:var(--font-display)] text-base font-semibold tracking-tight text-ink">{{ money($item->priceFor(auth()->user())) }}</p>
                                    </li>
                                @endforeach
                            </ul>
                            <a href="#products" class="mt-2 flex items-center justify-center gap-2 rounded-xl bg-paper py-3 text-sm font-semibold text-ink transition hover:bg-grey" tabindex="-1">
                                Browse the full catalogue
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M6 13l6 6 6-6"/></svg>
                            </a>
                        </div>
                    </div>
                @endif
            </div>

            @if ($paymentMethods !== [])
                <div class="relative border-t border-line bg-white/60">
                    <div class="container-x flex flex-col items-center gap-3 py-4 sm:flex-row sm:gap-6">
                        <p class="shrink-0 text-xs font-medium text-muted">Pay with</p>
                        <ul class="flex flex-wrap items-center justify-center gap-x-6 gap-y-1.5 sm:justify-start">
                            @foreach ($paymentMethods as $pm)
                                <li class="text-sm font-semibold text-slate/80">{{ $pm }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </section>
    @endif

    {{-- ================= CATALOG ================= --}}
    <section id="products" class="container-x scroll-mt-20 py-10 sm:py-14">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="display text-3xl sm:text-[2.5rem]">
                    @if ($search)
                        Results for “{{ $search }}”
                    @elseif ($activeCategory)
                        {{ $activeCategory->name }}
                    @else
                        Shop all tools
                    @endif
                </h2>
                <p class="mt-1.5 text-sm text-muted">
                    {{ $products->total() }} {{ Str::plural('product', $products->total()) }}
                    @if ($search || $activeCategory)
                        · <a href="{{ route('home') }}#products" class="link-underline text-ink">Clear filter</a>
                    @endif
                </p>
            </div>

            <form action="{{ $activeCategory ? route('categories.show', $activeCategory) : route('home') }}#products" method="get" class="w-full sm:w-72" role="search">
                <label for="catalog-search" class="sr-only">Search products</label>
                <div class="relative">
                    <svg class="pointer-events-none absolute top-1/2 left-4 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input id="catalog-search" type="search" name="q" value="{{ $search }}" placeholder="Search products" class="input rounded-full pl-10">
                </div>
            </form>
        </div>

        <nav class="mt-6 flex gap-6 overflow-x-auto border-b border-line [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Categories">
            <a href="{{ route('home') }}#products" @class(['tab', 'tab-active' => ! $activeCategory]) @if (! $activeCategory) aria-current="page" @endif>All</a>
            @foreach ($categories as $category)
                <a href="{{ route('categories.show', $category) }}#products"
                   @class(['tab', 'tab-active' => $activeCategory?->is($category)])
                   @if ($activeCategory?->is($category)) aria-current="page" @endif>{{ $category->name }}</a>
            @endforeach
        </nav>

        @if ($products->isEmpty())
            <div class="mt-10 rounded-2xl border border-dashed border-line-strong px-6 py-16 text-center">
                <p class="display text-2xl">Nothing matched that search</p>
                <p class="mt-2 text-sm">Try another name, or browse every category.</p>
                <a href="{{ route('home') }}#products" class="btn btn-outline mt-6">View all products</a>
            </div>
        @else
            <div class="mt-8 grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 xl:grid-cols-4">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
            <div class="mt-12">{{ $products->links() }}</div>
        @endif
    </section>

    @if ($isLanding)
        {{-- ================= HOW IT WORKS ================= --}}
        <section class="border-t border-line bg-white py-16 sm:py-24" aria-labelledby="how-heading">
            <div class="container-x grid gap-10 lg:grid-cols-[.8fr_1.2fr] lg:gap-20">
                <div>
                    <p class="eyebrow">How it works</p>
                    <h2 id="how-heading" class="display mt-3 text-3xl sm:text-[2.5rem]">From checkout to access in four short steps.</h2>
                    <p class="mt-4 max-w-sm text-[15px] leading-relaxed">No accounts to set up, no card required. Pay locally and follow every step on your order page.</p>
                </div>
                <ol class="grid gap-x-10 sm:grid-cols-2">
                    @foreach ([
                        ['Choose a product', 'Pick the tool and quantity. Stock is held for you while you check out.'],
                        ['Pay locally', 'Send the exact amount with your wallet, bank or Binance.'],
                        ['Share your Transaction ID', 'Automatic methods verify instantly; others are checked by our team.'],
                        ['Get your access', 'Credentials appear on your private order page and in your account.'],
                    ] as $i => [$title, $text])
                        <li class="border-t border-line py-6">
                            <span class="font-[family-name:var(--font-display)] text-sm font-semibold text-brand">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                            <h3 class="mt-2 text-base font-semibold">{{ $title }}</h3>
                            <p class="mt-1 text-sm leading-relaxed">{{ $text }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- ================= FAQ ================= --}}
        <section class="border-t border-line py-16 sm:py-24" aria-labelledby="faq-heading">
            <div class="container-x grid gap-10 lg:grid-cols-[.8fr_1.2fr] lg:gap-20">
                <div>
                    <p class="eyebrow">Questions</p>
                    <h2 id="faq-heading" class="display mt-3 text-3xl sm:text-[2.5rem]">Frequently asked questions</h2>
                    <p class="mt-4 max-w-sm text-[15px] leading-relaxed">
                        Can't find your answer? <a href="{{ route('pages.show', 'faq') }}" class="link-underline text-ink">Read the full FAQ</a>
                        or <a href="{{ route('pages.show', 'contact') }}" class="link-underline text-ink">contact us</a>.
                    </p>
                </div>
                <div class="border-b border-line">
                    @foreach ($faqs as $faq)
                        <details class="group border-t border-line" @if ($loop->first) open @endif>
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-[15px] font-semibold text-ink sm:text-base [&::-webkit-details-marker]:hidden">
                                <span>{{ $faq['q'] }}</span>
                                <svg class="h-5 w-5 shrink-0 text-muted transition-transform duration-200 group-open:rotate-45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                            </summary>
                            <p class="-mt-1 max-w-2xl pb-5 text-[15px] leading-relaxed">{{ $faq['a'] }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ================= HELP BAND ================= --}}
        <section class="container-x pb-4">
            <div class="relative overflow-hidden rounded-3xl bg-brand px-6 py-10 text-white sm:px-12 sm:py-14">
                <div class="pointer-events-none absolute -right-20 -bottom-24 h-72 w-72 rounded-full border-[40px] border-white/[.06]" aria-hidden="true"></div>
                <div class="relative flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
                    <div class="max-w-xl">
                        <h2 class="display text-2xl text-white sm:text-[2rem]">Not sure which plan you need?</h2>
                        <p class="mt-2 text-[15px] text-white/75">Tell us what you're working on and we'll recommend the right tool, price and delivery time.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        @if ($waNumber !== '')
                            <a href="https://wa.me/{{ $waNumber }}?text={{ urlencode('Assalam o Alaikum! I need help choosing a product on '.$storeName.'.') }}" target="_blank" rel="noopener" class="btn btn-lg bg-white text-ink hover:bg-paper">
                                <svg class="h-4 w-4 text-[#25D366]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.5 14.4c-.3-.1-1.8-.9-2-1s-.5-.1-.7.1-.8 1-1 1.2-.4.2-.7.1a8.2 8.2 0 0 1-4-3.5c-.3-.5.3-.5.9-1.6.1-.2 0-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.9.4 3.6 3.6 0 0 0-1.1 2.7 6.3 6.3 0 0 0 1.3 3.3 14.4 14.4 0 0 0 5.5 4.9c2 .9 2.8 1 3.9.8a3.3 3.3 0 0 0 2.1-1.5 2.7 2.7 0 0 0 .2-1.5c-.1-.1-.3-.2-.6-.3zM12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2z"/></svg>
                                Chat on WhatsApp
                            </a>
                        @endif
                        <a href="{{ route('orders.track') }}" class="btn btn-lg border border-white/30 text-white hover:bg-white/10">Track an order</a>
                    </div>
                </div>
            </div>
        </section>
    @endif
</x-layouts.store>
