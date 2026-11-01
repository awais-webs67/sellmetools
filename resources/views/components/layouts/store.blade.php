@props([
    'title' => null,
    'description' => null,
    'keywords' => null,
    'image' => null,
    'type' => 'website',
    'canonical' => null,
    'robots' => 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1',
])
@php
    $storeName = setting('store_name', 'SellMeTools');
    $defaultSeoTitle = setting('seo_title') ?: setting('store_tagline');
    $pageTitle = $title ? "{$title} | {$storeName}" : "{$storeName} – {$defaultSeoTitle}";
    $metaDescription = $description ?: (setting('seo_description') ?: setting('store_tagline'));
    $metaKeywords = $keywords ?: setting('seo_keywords');
    $canonicalUrl = $canonical ?: url()->current();
    $logoSetting = setting('store_logo');
    $defaultOgImage = $logoSetting
        ? (\Illuminate\Support\Str::startsWith($logoSetting, ['http://', 'https://']) ? $logoSetting : url('/media/'.ltrim($logoSetting, '/')))
        : asset('favicon.svg');
    $ogImage = $image ?: $defaultOgImage;
    $announcement = setting('announcement');
    $googleVerify = setting('google_site_verification');
    $gaId = setting('google_analytics_id');

    $orgSchema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'OnlineStore',
        '@id' => url('/').'#organization',
        'name' => $storeName,
        'url' => url('/'),
        'logo' => $defaultOgImage,
        'description' => $metaDescription,
        'currenciesAccepted' => 'PKR, USDT',
        'paymentAccepted' => 'Easypaisa, JazzCash, NayaPay, SadaPay, Bank Transfer, Raast, Binance Pay, USDT',
        'priceRange' => 'PKR 100 - PKR 25000',
        'areaServed' => [
            '@type' => 'Country',
            'name' => 'Pakistan',
        ],
        'contactPoint' => array_filter([
            '@type' => 'ContactPoint',
            'contactType' => 'customer support',
            'telephone' => setting('support_whatsapp') ?: null,
            'email' => setting('support_email') ?: null,
            'availableLanguage' => ['English', 'Urdu'],
        ]),
    ]);

    $websiteSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        '@id' => url('/').'#website',
        'url' => url('/'),
        'name' => $storeName,
        'description' => $metaDescription,
        'publisher' => ['@id' => url('/').'#organization'],
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => route('home').'?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en-PK">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    @if ($metaKeywords)
        <meta name="keywords" content="{{ $metaKeywords }}">
    @endif
    <meta name="robots" content="{{ $robots }}">
    <meta name="author" content="{{ $storeName }}">
    <meta name="geo.region" content="PK">
    <meta name="geo.placename" content="Pakistan">
    <meta name="theme-color" content="#9B1B30">

    <link rel="canonical" href="{{ $canonicalUrl }}">
    <link rel="alternate" hreflang="en-pk" href="{{ $canonicalUrl }}">
    <link rel="alternate" hreflang="x-default" href="{{ $canonicalUrl }}">

    {{-- Open Graph / Facebook / WhatsApp Preview --}}
    <meta property="og:site_name" content="{{ $storeName }}">
    <meta property="og:locale" content="en_PK">
    <meta property="og:type" content="{{ $type }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="{{ $ogImage }}">

    {{-- Twitter / X Card --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    @if ($googleVerify)
        <meta name="google-site-verification" content="{{ $googleVerify }}">
    @endif

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="sitemap" type="application/xml" title="Sitemap" href="{{ route('sitemap') }}">

    {{-- Global Organization & WebSite JSON-LD Schema --}}
    <script type="application/ld+json">{!! json_encode($orgSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    <script type="application/ld+json">{!! json_encode($websiteSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @stack('seo')

    @if ($gaId)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($gaId) }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', @json($gaId));
        </script>
    @endif

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>window.SMT = { stockUrl: @json(route('stock', auth()->user()?->is_reseller ? ['reseller' => auth()->id()] : [])), syncUrl: @json(route('stock.sync')) };</script>
</head>
<body class="flex min-h-screen flex-col">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded focus:bg-ink focus:px-4 focus:py-2 focus:text-white">Skip to content</a>

    @if ($announcement)
        <div class="bg-ink text-center text-xs font-medium text-white sm:text-sm">
            <div class="container-x py-2">{{ $announcement }}</div>
        </div>
    @endif

    {{-- Background Tab Return Banner: Syncs prices & stock in-place WITHOUT clearing form inputs --}}
    <div x-data x-show="$store.stock.stale || $store.stock.justSynced" x-cloak
         class="sticky top-0 z-50 border-b border-brand/30 bg-[#1F070E] text-white shadow-lg">
        <div class="container-x flex flex-wrap items-center justify-between gap-2 py-2.5 text-xs sm:text-sm">
            <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-[#22c55e] live-dot"></span>
                <span x-show="!$store.stock.justSynced">
                    <strong>Welcome back!</strong> Sync live prices &amp; stock now (your typed order details will stay safe).
                </span>
                <span x-show="$store.stock.justSynced" class="font-semibold text-[#4ade80]">
                    ✓ Latest prices &amp; live stock synced! Your form details are untouched.
                </span>
            </div>
            <div class="flex items-center gap-2">
                <button type="button"
                        @click="$store.stock.manualSync()"
                        :disabled="$store.stock.syncing"
                        class="btn btn-primary px-3.5 py-1.5 text-xs font-bold">
                    <span x-show="!$store.stock.syncing">↻ Sync Live Price &amp; Stock</span>
                    <span x-show="$store.stock.syncing">Syncing...</span>
                </button>
                <button type="button" @click="$store.stock.stale = false" class="px-2 py-1 text-white/60 hover:text-white" aria-label="Dismiss">✕</button>
            </div>
        </div>
    </div>

    <header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-line bg-white/95 shadow-[0_1px_2px_rgba(0,0,0,.06)] backdrop-blur">
        <div class="container-x flex h-16 items-center justify-between gap-6 lg:h-[76px]">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5" aria-label="{{ $storeName }} home">
                <x-logo-mark class="h-8 w-8" />
                <span class="display text-[28px] leading-none text-ink">{{ $storeName }}</span>
            </a>

            <form action="{{ route('home') }}" method="get" class="hidden max-w-md flex-1 md:block" role="search">
                <label for="site-search" class="sr-only">Search products</label>
                <div class="relative">
                    <svg class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input id="site-search" type="search" name="q" value="{{ request('q') }}" placeholder="Search ChatGPT, Canva, CapCut..." class="input pl-9">
                </div>
            </form>

            <nav class="hidden items-center gap-1 lg:flex" aria-label="Main">
                <a href="{{ route('home') }}" class="btn btn-ghost">Shop</a>
                <a href="{{ route('orders.track') }}" class="btn btn-ghost">Track order</a>
                @auth
                    @if (auth()->user()->is_reseller && filled(auth()->user()->reseller_slug))
                        <a href="{{ route('reseller.portal', auth()->user()->reseller_slug) }}" class="btn btn-ghost font-bold text-brand">★ Reseller Portal</a>
                    @endif
                    <a href="{{ route('account.orders') }}" class="btn btn-ghost">
                        My Account
                        @if ((float) auth()->user()->wallet_balance > 0)
                            <span class="ml-1 rounded-full bg-brand px-2 py-0.5 text-[11px] font-bold text-white">{{ money(auth()->user()->wallet_balance) }}</span>
                        @endif
                    </a>
                    <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-ghost">Logout</button></form>
                @else
                    <a href="{{ route('login') }}" class="btn btn-ghost">Login</a>
                    <a href="{{ route('register') }}" class="btn btn-primary">Create account</a>
                @endauth
            </nav>

            <button type="button" class="btn btn-outline px-3 py-2 lg:hidden" @click="open = !open" :aria-expanded="open" aria-controls="mobile-nav">
                <svg x-show="!open" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                <svg x-show="open" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                <span class="sr-only">Menu</span>
            </button>
        </div>

        <div id="mobile-nav" x-show="open" x-cloak x-transition.origin.top class="border-t border-line bg-white lg:hidden">
            <div class="container-x space-y-1 py-4">
                <form action="{{ route('home') }}" method="get" class="mb-3 md:hidden" role="search">
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search products..." class="input" aria-label="Search products">
                </form>
                <a href="{{ route('home') }}" class="block rounded px-3 py-2 font-medium text-ink hover:bg-grey-soft">Shop</a>
                <a href="{{ route('orders.track') }}" class="block rounded px-3 py-2 font-medium text-ink hover:bg-grey-soft">Track order</a>
                @auth
                    @if (auth()->user()->is_reseller && filled(auth()->user()->reseller_slug))
                        <a href="{{ route('reseller.portal', auth()->user()->reseller_slug) }}" class="block rounded px-3 py-2 font-bold text-brand hover:bg-grey-soft">★ My Reseller Portal (/{{ auth()->user()->reseller_slug }})</a>
                    @endif
                    <a href="{{ route('account.orders') }}" class="block rounded px-3 py-2 font-medium text-ink hover:bg-grey-soft">
                        My Account &amp; Orders
                        @if ((float) auth()->user()->wallet_balance > 0)
                            <span class="ml-1 rounded-full bg-brand px-2 py-0.5 text-[11px] font-bold text-white">{{ money(auth()->user()->wallet_balance) }}</span>
                        @endif
                    </a>
                    <form method="post" action="{{ route('logout') }}">@csrf<button class="block w-full rounded px-3 py-2 text-left font-medium text-ink hover:bg-grey-soft">Logout</button></form>
                @else
                    <a href="{{ route('login') }}" class="block rounded px-3 py-2 font-medium text-ink hover:bg-grey-soft">Login</a>
                    <a href="{{ route('register') }}" class="btn btn-primary mt-2 w-full">Create account</a>
                @endauth
            </div>
        </div>
    </header>

    <main id="main" class="flex-1">
        @if (session('success') || session('error'))
            <div class="container-x pt-6">
                @if (session('success'))
                    <div class="rounded-md border border-success/20 bg-success-tint px-4 py-3 text-sm font-medium text-success" role="status">{{ session('success') }}</div>
                @endif
                @if (session('error'))
                    <div class="rounded-md border border-danger/20 bg-danger-tint px-4 py-3 text-sm font-medium text-danger" role="alert">{{ session('error') }}</div>
                @endif
            </div>
        @endif

        {{ $slot }}
    </main>

    <footer class="mt-20 bg-ink text-white/70">
        <div class="container-x grid gap-10 py-14 md:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                    <x-logo-mark class="h-8 w-8" />
                    <span class="display text-[28px] leading-none text-white">{{ $storeName }}</span>
                </a>
                <p class="mt-4 max-w-md text-sm leading-6">{{ setting('store_tagline') }} Pay with Easypaisa, JazzCash, NayaPay, SadaPay or Binance and receive your product automatically after verification.</p>
                <div class="mt-5 flex flex-wrap gap-2">
                    @foreach (['Easypaisa', 'JazzCash', 'NayaPay', 'SadaPay', 'Binance'] as $m)
                        <span class="rounded border border-white/15 px-2.5 py-1 text-xs font-medium text-white/80">{{ $m }}</span>
                    @endforeach
                </div>
            </div>
            <div>
                <h2 class="text-sm font-semibold tracking-wider text-white uppercase">Store</h2>
                <ul class="mt-4 space-y-2 text-sm">
                    <li><a class="hover:text-white" href="{{ route('home') }}">All products</a></li>
                    <li><a class="hover:text-white" href="{{ route('orders.track') }}">Track your order</a></li>
                    <li><a class="hover:text-white" href="{{ route('pages.show', 'faq') }}">FAQ</a></li>
                    <li><a class="hover:text-white" href="{{ route('pages.show', 'contact') }}">Contact & support</a></li>
                </ul>
            </div>
            <div>
                <h2 class="text-sm font-semibold tracking-wider text-white uppercase">Policies</h2>
                <ul class="mt-4 space-y-2 text-sm">
                    <li><a class="hover:text-white" href="{{ route('pages.show', 'terms') }}">Terms of service</a></li>
                    <li><a class="hover:text-white" href="{{ route('pages.show', 'refund') }}">Refund & warranty policy</a></li>
                    <li><a class="hover:text-white" href="{{ route('pages.show', 'privacy') }}">Privacy policy</a></li>
                </ul>
            </div>
        </div>
        <div class="border-t border-white/10">
            <div class="container-x flex flex-col gap-2 py-5 text-xs sm:flex-row sm:items-center sm:justify-between">
                <p>&copy; {{ date('Y') }} {{ $storeName }}. All rights reserved.</p>
                <p>All trademarks belong to their respective owners.</p>
            </div>
        </div>
    </footer>

    @if ($wa = admin_whatsapp_number())
        <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="fixed right-5 bottom-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-[var(--shadow-pop)] transition hover:scale-105" aria-label="Chat on WhatsApp">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21c5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2Zm5.8 14.03c-.25.69-1.45 1.32-2 1.37-.51.05-1 .24-3.36-.7-2.84-1.12-4.63-4.03-4.77-4.22-.14-.19-1.14-1.52-1.14-2.9 0-1.38.72-2.06.98-2.34.25-.28.55-.35.74-.35l.53.01c.17 0 .4-.06.62.48.25.6.84 2.07.92 2.22.07.15.12.32.02.51-.1.19-.15.32-.29.49-.15.17-.31.38-.44.51-.15.15-.3.3-.13.6.17.29.77 1.27 1.65 2.06 1.14 1.01 2.09 1.33 2.39 1.48.29.15.47.12.64-.07.17-.2.74-.86.94-1.16.2-.29.39-.24.66-.15.27.1 1.73.82 2.02.97.3.15.5.22.57.34.07.12.07.71-.18 1.4Z"/></svg>
        </a>
    @endif
</body>
</html>

