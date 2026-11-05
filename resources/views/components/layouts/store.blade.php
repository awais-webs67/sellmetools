@props([
    'title' => null,
    'description' => null,
    'keywords' => null,
    'image' => null,
    'type' => 'website',
    'canonical' => null,
    'prevUrl' => null,
    'nextUrl' => null,
    'robots' => 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1',
])
@if (request()->header('X-SMT-Filter') === '1')
    <!DOCTYPE html><html><body>{{ $slot }}</body></html>
    @php return; @endphp
@endif
@php
    $storeName = setting('store_name', 'SellMeTools');
    $defaultSeoTitle = setting('seo_title') ?: setting('store_tagline');
    $rawTitle = trim((string) ($title ?: $defaultSeoTitle));
    $rawTitle = str_replace([' — '.$storeName, ' – '.$storeName, ' | '.$storeName], ' | '.$storeName, $rawTitle);
    $rawTitle = str_replace(['—', '–'], '-', $rawTitle);
    $pageTitle = (stripos($rawTitle, (string) $storeName) !== false || mb_strlen($rawTitle) > 54)
        ? $rawTitle
        : "{$rawTitle} | {$storeName}";
    $metaDescription = str_replace(['—', '–'], '-', \Illuminate\Support\Str::limit(trim((string) ($description ?: (setting('seo_description') ?: setting('store_tagline')))), 155));
    $metaKeywords = $keywords ?: setting('seo_keywords');
    $canonicalUrl = $canonical ?: url()->current();
    $logoSetting = setting('store_logo');
    $defaultOgImage = $logoSetting
        ? (\Illuminate\Support\Str::startsWith($logoSetting, ['http://', 'https://']) ? $logoSetting : url('/media/'.ltrim($logoSetting, '/')))
        : asset('images/og-image.png');
    $ogImage = $image ?: $defaultOgImage;
    $announcement = setting('announcement');
    $rawGoogleVerify = trim((string) setting('google_site_verification'));
    $googleVerify = preg_match('/content=["\']([^"\']+)["\']/i', $rawGoogleVerify, $m) ? trim($m[1]) : $rawGoogleVerify;
    $rawBingVerify = trim((string) setting('bing_site_verification'));
    $bingVerify = preg_match('/content=["\']([^"\']+)["\']/i', $rawBingVerify, $bm) ? trim($bm[1]) : $rawBingVerify;
    $gaId = setting('google_analytics_id');
    $storeTheme = (string) setting('store_theme', 'black');
    $themeColorHex = $storeTheme === 'crimson' ? '#9B1B30' : '#000000';

    $orgSchema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'OnlineStore',
        '@id' => url('/').'#organization',
        'name' => $storeName,
        'url' => url('/'),
        'logo' => $logoSetting ? $defaultOgImage : asset('images/logo.png'),
        'description' => $metaDescription,
        'currenciesAccepted' => 'USD, USDT, PKR',
        'paymentAccepted' => 'Binance Pay, USDT (TRC20), Cryptocurrency, Easypaisa, JazzCash, NayaPay, SadaPay, Bank Transfer',
        'priceRange' => '$1 - $100 / PKR 100 - PKR 25000',
        'areaServed' => 'Worldwide',
        'knowsAbout' => [
            'AI Tools Subscriptions',
            'ChatGPT Plus Subscription',
            'Canva Pro Subscription',
            'CapCut Pro Subscription',
            'Digital Software Licenses',
            'Cloud & Streaming Subscriptions',
        ],
        'founder' => [
            '@type' => 'Organization',
            'name' => 'AivraSol Softwares',
            'url' => 'https://aivrasol.com',
        ],
        'parentOrganization' => [
            '@type' => 'Organization',
            'name' => 'AivraSol Softwares',
            'url' => 'https://aivrasol.com',
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
        'alternateName' => ['SellMeTools.com', 'Sell Me Tools', 'AivraSol SellMeTools'],
        'description' => $metaDescription,
        'publisher' => ['@id' => url('/').'#organization'],
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => route('shop').'?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="{{ $storeTheme }}">
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
    <meta name="author" content="{{ $storeName }} | AivraSol Softwares">
    <meta name="theme-color" content="{{ $themeColorHex }}">

    <link rel="canonical" href="{{ $canonicalUrl }}">
    @if ($prevUrl)
        <link rel="prev" href="{{ $prevUrl }}">
    @endif
    @if ($nextUrl)
        <link rel="next" href="{{ $nextUrl }}">
    @endif
    <link rel="alternate" hreflang="en" href="{{ $canonicalUrl }}">
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
    <meta property="og:image:alt" content="{{ $storeName }}">

    {{-- Twitter / X Card --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    @if ($googleVerify)
        <meta name="google-site-verification" content="{{ $googleVerify }}">
    @endif
    @if ($bingVerify)
        <meta name="msvalidate.01" content="{{ $bingVerify }}">
    @endif

    <link rel="icon" href="{{ asset('favicon.png') }}?v=8" type="image/png" sizes="64x64">
    <link rel="icon" href="{{ asset('favicon.svg') }}?v=8" type="image/svg+xml">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=8">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=8">
    <link rel="manifest" href="/manifest.json">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ $storeName }}">
    <link rel="sitemap" type="application/xml" title="Sitemap" href="{{ route('sitemap') }}">
    <link rel="alternate" type="text/plain" title="LLMs.txt" href="{{ route('llms') }}">

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
    <script>window.SMT = { stockUrl: @json(route('stock', auth()->user()?->is_reseller ? ['reseller' => auth()->id()] : [])), syncUrl: @json(route('stock.sync')), searchUrl: @json(route('search.suggest')), catalog: null, usdtRate: {{ \App\Models\Setting::usdtRate() }}, currencySymbol: @json(setting('currency_symbol', 'Rs')) };</script>
</head>
<body class="flex min-h-screen flex-col">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded focus:bg-ink focus:px-4 focus:py-2 focus:text-white">Skip to content</a>

    @if ($announcement)
        <div class="bg-ink text-center text-xs font-medium text-white sm:text-sm">
            <div class="container-x py-2">{{ $announcement }}</div>
        </div>
    @endif

    {{-- Professional Stale Page Popup Modal (Replaces old top sync banner) --}}
    <div x-data x-show="$store.stock.stale" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/65 backdrop-blur-sm"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="relative w-full max-w-sm rounded-2xl bg-[#14171A] text-white p-6 shadow-2xl border border-white/15 text-center transform transition-all"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             @click.outside="$store.stock.stale = false">

            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand/20 border border-brand/40 text-[#ff6b81]">
                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                    <path d="M3 3v5h5"/>
                    <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                    <path d="M16 21h5v-5"/>
                </svg>
            </div>

            <h3 class="display mt-4 text-2xl text-white">Live Stock &amp; Prices</h3>
            <p class="mt-2 text-xs sm:text-sm text-white/75 leading-relaxed">
                Stock or prices may have updated while you were in another tab. Please refresh the page data to get the latest live availability.
                <span class="block mt-1 text-[11px] text-white/50">(Any details you typed remain safe)</span>
            </p>

            <div class="mt-6 flex flex-col gap-2">
                <button type="button"
                        @click="$store.stock.manualSync()"
                        :disabled="$store.stock.syncing"
                        class="btn btn-primary w-full py-3 text-sm font-bold shadow-lg">
                    <span x-show="!$store.stock.syncing">↻ Refresh Page Data Now</span>
                    <span x-show="$store.stock.syncing" class="flex items-center justify-center gap-2">
                        <span class="h-4 w-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                        Syncing live data...
                    </span>
                </button>
                <button type="button"
                        @click="$store.stock.stale = false"
                        class="btn btn-ghost text-white/70 hover:text-white py-2 text-xs">
                    Dismiss &amp; Continue
                </button>
            </div>
        </div>
    </div>

    {{-- Floating Success Toast when synced (100% Mobile-Responsive Full-Width Flex Wrapper) --}}
    <div x-data x-show="$store.stock.justSynced" x-cloak
         class="fixed top-4 inset-x-0 z-50 flex justify-center px-3 pointer-events-none"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 -translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-4">
        <div class="pointer-events-auto inline-flex max-w-full items-center justify-center gap-2 rounded-full border border-emerald-500/35 bg-[#0F291E]/95 px-4 py-2 text-center text-[11px] sm:text-xs font-semibold text-[#4ade80] shadow-xl backdrop-blur-md">
            <span class="h-2 w-2 shrink-0 rounded-full bg-[#22c55e]"></span>
            <span class="leading-snug">Real-time prices &amp; stock synced successfully!</span>
        </div>
    </div>

    <header x-data="{ open: false }" class="sticky top-0 z-40 w-full max-w-full border-b border-line bg-white/95 shadow-[0_1px_2px_rgba(0,0,0,.06)] backdrop-blur">
        {{-- Instant Top Click Progress Bar --}}
        <div id="smt-nav-progress" class="pointer-events-none fixed top-0 left-0 z-50 h-0.5 w-0 bg-brand opacity-0 transition-all duration-200 ease-out"></div>

        <div class="container-x flex h-14 sm:h-16 lg:h-[74px] items-center justify-between gap-2.5 sm:gap-4 lg:gap-5 xl:gap-6 min-w-0">
            <a href="{{ route('home') }}" class="flex shrink-0 items-center transition active:scale-95" aria-label="{{ $storeName }} home">
                <img src="{{ asset('images/logo.png') }}?v=6" alt="{{ $storeName }}" width="180" height="40" fetchpriority="high" decoding="async" class="h-6 sm:h-7 lg:h-8 xl:h-9 w-auto shrink-0 object-contain">
            </a>

            <form action="{{ route('shop') }}" method="get" class="relative hidden flex-1 min-w-0 max-w-md xl:max-w-lg md:block" role="search"
                  x-data="liveSearch" @click.outside="open = false">
                <label for="site-search" class="sr-only">Search products</label>
                <div class="relative">
                    <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input id="site-search" type="search" name="q" value="{{ request('q') }}" placeholder="Search AI tools &amp; subscriptions..." class="input w-full rounded-xl bg-grey-soft/75 py-2 pl-10 pr-8 text-sm focus:bg-white" x-bind="field">
                    <button type="button" x-show="q.length > 0" x-cloak @click="q = ''; reset(); $el.previousElementSibling.focus()" class="absolute top-1/2 right-2.5 -translate-y-1/2 rounded-full p-1 text-muted hover:bg-grey hover:text-ink cursor-pointer" aria-label="Clear search">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                </div>
                <x-search-suggest />
            </form>

            <nav class="hidden items-center gap-0.5 xl:gap-1 lg:flex shrink-0" aria-label="Main">
                <a href="{{ route('shop') }}" @class(['btn btn-ghost px-2.5 xl:px-3 py-2 text-[13px] whitespace-nowrap', 'text-brand font-bold' => request()->routeIs('shop')])>Shop</a>
                <a href="{{ route('orders.track') }}" @class(['btn btn-ghost px-2.5 xl:px-3 py-2 text-[13px] whitespace-nowrap', 'text-brand font-bold' => request()->routeIs('orders.track')])>Track Order</a>
                <a href="{{ route('pages.show', 'about') }}" @class(['btn btn-ghost px-2.5 xl:px-3 py-2 text-[13px] whitespace-nowrap hidden xl:inline-flex', 'text-brand font-bold' => request()->fullUrlIs(route('pages.show', 'about'))])>About</a>
                <a href="{{ route('pages.show', 'contact') }}" @class(['btn btn-ghost px-2.5 xl:px-3 py-2 text-[13px] whitespace-nowrap hidden xl:inline-flex', 'text-brand font-bold' => request()->fullUrlIs(route('pages.show', 'contact'))])>Contact</a>
                @auth
                    @if (auth()->user()->is_reseller && filled(auth()->user()->reseller_slug))
                        <a href="{{ route('reseller.portal', auth()->user()->reseller_slug) }}" @class(['btn btn-ghost px-2.5 xl:px-3 py-2 text-[13px] whitespace-nowrap font-bold text-brand', 'bg-brand-tint' => request()->routeIs('reseller.portal')])>★ Reseller</a>
                    @endif
                    <a href="{{ route('account.orders') }}" class="btn btn-ghost px-2.5 xl:px-3 py-2 text-[13px] whitespace-nowrap">
                        My Account
                        @if ((float) auth()->user()->wallet_balance > 0)
                            <span class="ml-1 rounded-full bg-brand px-2 py-0.5 text-[11px] font-bold text-white">{{ money(auth()->user()->wallet_balance) }}</span>
                        @endif
                    </a>
                    <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-ghost px-2.5 xl:px-3 py-2 text-[13px] whitespace-nowrap">Logout</button></form>
                @else
                    <a href="{{ route('login') }}" class="btn btn-ghost px-2.5 xl:px-3 py-2 text-[13px] whitespace-nowrap">Login</a>
                    <a href="{{ route('register') }}" class="btn btn-primary px-3.5 py-2 text-[13px] whitespace-nowrap">Create Account</a>
                @endauth
            </nav>

            {{-- PKR / USDT Currency Switcher + Mobile Menu Trigger --}}
            <div class="flex items-center gap-1.5 sm:gap-2 shrink-0">
                <div class="inline-flex items-center rounded-full border border-line-strong bg-grey-soft p-0.5 shadow-2xs"
                     role="group"
                     aria-label="Switch currency between PKR and USDT"
                     title="Both PKR and USDT accepted · 1 USDT = Rs {{ number_format(\App\Models\Setting::usdtRate(), 0) }}">
                    <button type="button"
                            @click="$store.currency.set('PKR')"
                            :aria-pressed="!$store.currency.isUsdt"
                            :class="!$store.currency.isUsdt ? 'bg-brand text-white shadow-xs' : 'text-muted hover:text-ink'"
                            class="inline-flex items-center gap-1 rounded-full px-2 py-1 sm:px-2.5 sm:py-1 text-[10px] sm:text-xs font-bold tracking-wide transition cursor-pointer">
                        <span class="hidden sm:inline">Rs</span>
                        <span>PKR</span>
                    </button>
                    <button type="button"
                            @click="$store.currency.set('USDT')"
                            :aria-pressed="$store.currency.isUsdt"
                            :class="$store.currency.isUsdt ? 'bg-[#0F9D58] text-white shadow-xs' : 'text-muted hover:text-ink'"
                            class="inline-flex items-center gap-1 rounded-full px-2 py-1 sm:px-2.5 sm:py-1 text-[10px] sm:text-xs font-bold tracking-wide transition cursor-pointer">
                        <span class="hidden sm:inline">₮</span>
                        <span>USDT</span>
                    </button>
                </div>

                {{-- Clean Simple Hamburger Trigger --}}
                <button type="button"
                        @click="open = !open"
                        :aria-expanded="open"
                        aria-controls="mobile-nav"
                        class="inline-flex h-9 w-9 sm:h-10 sm:w-10 shrink-0 items-center justify-center rounded-lg border border-line bg-white text-ink hover:bg-grey-soft active:scale-95 transition lg:hidden cursor-pointer">
                    <span class="sr-only">Menu</span>
                    <svg x-show="!open" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    <svg x-show="open" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
        </div>

        {{-- Responsive Mobile Search Bar Row (Visible on screens < md) --}}
        <div class="border-t border-line/70 bg-white px-3.5 sm:px-6 py-2 md:hidden">
            <form action="{{ route('shop') }}" method="get" class="relative" role="search" x-data="liveSearch" @click.outside="open = false">
                <label for="mobile-header-search" class="sr-only">Search products</label>
                <div class="relative">
                    <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input id="mobile-header-search" type="search" name="q" value="{{ request('q') }}" placeholder="Search AI tools, software &amp; subscriptions..." class="input w-full rounded-xl bg-grey-soft/80 py-2 pl-10 pr-8 text-sm focus:bg-white" x-bind="field">
                    <button type="button" x-show="q.length > 0" x-cloak @click="q = ''; reset(); $el.previousElementSibling.focus()" class="absolute top-1/2 right-2.5 -translate-y-1/2 rounded-full p-1 text-muted hover:bg-grey hover:text-ink cursor-pointer" aria-label="Clear search">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                </div>
                <x-search-suggest />
            </form>
        </div>

        {{-- Simple Clean Mobile Menu --}}
        <div id="mobile-nav"
             x-show="open"
             x-cloak
             @click.outside="open = false"
             class="border-t border-line bg-white shadow-lg lg:hidden">
            <div class="container-x py-3 space-y-2.5">
                {{-- Simple Links --}}
                <nav class="flex flex-col divide-y divide-line text-sm font-semibold text-ink" aria-label="Mobile navigation">
                    <a href="{{ route('home') }}" @class(['py-2.5 hover:text-brand', 'text-brand font-bold' => request()->routeIs('home')])>Home</a>
                    <a href="{{ route('shop') }}" @class(['py-2.5 hover:text-brand', 'text-brand font-bold' => request()->routeIs('shop')])>Shop</a>
                    <a href="{{ route('orders.track') }}" @class(['py-2.5 hover:text-brand', 'text-brand font-bold' => request()->routeIs('orders.track')])>Track Order</a>
                    <a href="{{ route('pages.show', 'about') }}" @class(['py-2.5 hover:text-brand', 'text-brand font-bold' => request()->fullUrlIs(route('pages.show', 'about'))])>About</a>
                    <a href="{{ route('pages.show', 'contact') }}" @class(['py-2.5 hover:text-brand', 'text-brand font-bold' => request()->fullUrlIs(route('pages.show', 'contact'))])>Contact</a>
                    @auth
                        @if (auth()->user()->is_reseller && filled(auth()->user()->reseller_slug))
                            <a href="{{ route('reseller.portal', auth()->user()->reseller_slug) }}" class="py-2.5 font-bold text-brand">★ Reseller Portal</a>
                        @endif
                    @endauth
                </nav>

                {{-- Account Buttons --}}
                @auth
                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <a href="{{ route('account.orders') }}" class="btn btn-outline py-2 text-xs font-bold">
                            My Account
                            @if ((float) auth()->user()->wallet_balance > 0)
                                <span class="rounded-full bg-brand px-1.5 py-0.5 text-[10px] font-bold text-white">{{ money(auth()->user()->wallet_balance) }}</span>
                            @endif
                        </a>
                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline w-full py-2 text-xs font-bold text-danger">Logout</button>
                        </form>
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <a href="{{ route('login') }}" class="btn btn-outline py-2 text-xs font-bold">Login</a>
                        <a href="{{ route('register') }}" class="btn btn-primary py-2 text-xs font-bold">Create Account</a>
                    </div>
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
                <a href="{{ route('home') }}" class="flex items-center">
                    <img src="{{ asset('images/logo-white.png') }}?v=4" alt="{{ $storeName }}" width="180" height="40" loading="lazy" decoding="async" class="h-8 sm:h-9 md:h-10 w-auto object-contain">
                </a>
                <p class="mt-4 max-w-md text-sm leading-6">{{ setting('store_tagline') }} Pay with Easypaisa, JazzCash, NayaPay, SadaPay or Binance and receive your product automatically after verification.</p>
                <div class="mt-5 flex flex-wrap gap-2">
                    @foreach (['Easypaisa', 'JazzCash', 'NayaPay', 'SadaPay', 'Binance'] as $m)
                        <span class="rounded border border-white/15 px-2.5 py-1 text-xs font-medium text-white/80">{{ $m }}</span>
                    @endforeach
                </div>
                @php
                    $footerAddressEnabled = (string) setting('footer_address_enabled', '1') === '1';
                    $footerAddress = trim((string) setting('footer_address', 'Vilage Dongray Kalan Post Office Pindi Purbian city Zafarwal district narowal'));
                @endphp
                @if ($footerAddressEnabled && $footerAddress !== '')
                    <div data-nosnippet class="mt-4 flex items-start gap-2 text-xs text-white/60 max-w-md leading-relaxed">
                        <svg class="h-4 w-4 shrink-0 text-white/50 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span>{{ $footerAddress }}</span>
                    </div>
                @endif
            </div>
            <div>
                <p class="text-sm font-semibold tracking-wider text-white uppercase">Store</p>
                <ul class="mt-4 space-y-2 text-sm">
                    <li><a class="hover:text-white" href="{{ route('shop') }}">Shop all products</a></li>
                    <li><a class="hover:text-white" href="{{ route('orders.track') }}">Track your order</a></li>
                    <li><a class="hover:text-white" href="{{ route('pages.show', 'about') }}">About {{ $storeName }}</a></li>
                    <li><a class="hover:text-white" href="{{ route('pages.show', 'faq') }}">FAQ &amp; help guide</a></li>
                    <li><a class="hover:text-white" href="{{ route('pages.show', 'contact') }}">Contact &amp; support</a></li>
                </ul>
            </div>
            <div>
                <p class="text-sm font-semibold tracking-wider text-white uppercase">Policies</p>
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
                <p class="text-white/60">A Project by <a href="https://aivrasol.com" target="_blank" rel="noopener" class="font-semibold text-white/95 hover:text-white underline decoration-white/40 underline-offset-2 transition">AivraSol Softwares</a></p>
                <p>All trademarks belong to their respective owners.</p>
            </div>
        </div>
    </footer>

    @if ($wa = admin_whatsapp_number())
        <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="fixed right-5 bottom-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-[var(--shadow-pop)] transition hover:scale-105 active:scale-95" aria-label="Chat on WhatsApp">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21c5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2Zm5.8 14.03c-.25.69-1.45 1.32-2 1.37-.51.05-1 .24-3.36-.7-2.84-1.12-4.63-4.03-4.77-4.22-.14-.19-1.14-1.52-1.14-2.9 0-1.38.72-2.06.98-2.34.25-.28.55-.35.74-.35l.53.01c.17 0 .4-.06.62.48.25.6.84 2.07.92 2.22.07.15.12.32.02.51-.1.19-.15.32-.29.49-.15.17-.31.38-.44.51-.15.15-.3.3-.13.6.17.29.77 1.27 1.65 2.06 1.14 1.01 2.09 1.33 2.39 1.48.29.15.47.12.64-.07.17-.2.74-.86.94-1.16.2-.29.39-.24.66-.15.27.1 1.73.82 2.02.97.3.15.5.22.57.34.07.12.07.71-.18 1.4Z"/></svg>
        </a>
    @endif

    @php
        $promoEnabled = (string) setting('promo_popup_enabled', '0') === '1';
        $promoType = (string) setting('promo_popup_type', 'both');
        $promoVersion = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) setting('promo_popup_version', 'v1')) ?: 'v1';
        $promoBadge = trim((string) setting('promo_popup_badge', ''));
        $promoTitle = trim((string) setting('promo_popup_title', ''));
        $promoBody = trim((string) setting('promo_popup_body', ''));
        $promoRawImg = trim((string) setting('promo_popup_image', ''));
        $promoImgUrl = $promoRawImg !== ''
            ? (\Illuminate\Support\Str::startsWith($promoRawImg, ['http://', 'https://']) ? $promoRawImg : url('/media/'.ltrim($promoRawImg, '/')))
            : '';
        $promoCtaText = trim((string) setting('promo_popup_cta_text', 'Join Official Channel'));
        $promoCtaUrl = trim((string) setting('promo_popup_cta_url', ''));
        $promoVersionHash = $promoVersion.'_'.substr(md5($promoTitle.$promoImgUrl.$promoCtaUrl), 0, 6);
    @endphp

    @if ($promoEnabled && ($promoImgUrl !== '' || $promoTitle !== '' || $promoBody !== ''))
        <div id="smt-promo-modal"
             style="display:none;"
             class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-xs">
            <div class="relative w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <button type="button"
                        id="smt-promo-close-btn"
                        class="absolute top-3 right-3 z-10 inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-white/95 text-slate-600 shadow-xs hover:bg-slate-100 hover:text-slate-900 transition cursor-pointer"
                        aria-label="Close promotional banner">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>

                @if (in_array($promoType, ['both', 'image'], true) && $promoImgUrl !== '')
                    @if ($promoCtaUrl !== '')
                        <a href="{{ $promoCtaUrl }}" target="_blank" rel="noopener" class="block bg-slate-100">
                            <img src="{{ $promoImgUrl }}" alt="{{ $promoTitle ?: $storeName }}" class="max-h-64 w-full object-cover">
                        </a>
                    @else
                        <div class="bg-slate-100">
                            <img src="{{ $promoImgUrl }}" alt="{{ $promoTitle ?: $storeName }}" class="max-h-64 w-full object-cover">
                        </div>
                    @endif
                @endif

                <div class="p-5 sm:p-6 space-y-4">
                    @if (in_array($promoType, ['both', 'text'], true) && ($promoBadge !== '' || $promoTitle !== '' || $promoBody !== ''))
                        <div>
                            @if ($promoBadge !== '')
                                <span class="inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-slate-700">
                                    {{ $promoBadge }}
                                </span>
                            @endif
                            @if ($promoTitle !== '')
                                <h3 class="mt-2 text-lg sm:text-xl font-bold tracking-tight text-slate-900 leading-snug">
                                    {{ $promoTitle }}
                                </h3>
                            @endif
                            @if ($promoBody !== '')
                                <p class="mt-1.5 text-xs sm:text-sm text-slate-600 leading-relaxed whitespace-pre-line">{{ $promoBody }}</p>
                            @endif
                        </div>
                    @endif

                    <div class="space-y-2.5 pt-1">
                        @if ($promoCtaUrl !== '')
                            <a href="{{ $promoCtaUrl }}"
                               target="_blank"
                               rel="noopener"
                               id="smt-promo-cta-link"
                               class="flex w-full items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-xs sm:text-sm font-semibold text-white shadow-xs hover:bg-slate-800 transition">
                                <span>{{ $promoCtaText ?: 'Open Link' }}</span>
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            </a>
                        @endif

                        <div class="flex items-center justify-between gap-2 pt-1 border-t border-slate-100">
                            <button type="button"
                                    id="smt-promo-dont-show-btn"
                                    class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 hover:text-slate-900 transition cursor-pointer">
                                <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <span>Don't show this again</span>
                            </button>
                            <button type="button"
                                    id="smt-promo-later-btn"
                                    class="text-xs font-semibold text-slate-600 hover:text-slate-900 transition cursor-pointer">
                                Maybe later
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script>
            (function () {
                const PROMO_KEY = @json('smt_promo_dismissed_' . $promoVersionHash);
                const SESSION_KEY = @json('smt_promo_closed_' . $promoVersionHash);
                const modal = document.getElementById('smt-promo-modal');
                if (!modal) return;
                if (localStorage.getItem(PROMO_KEY) === '1' || sessionStorage.getItem(SESSION_KEY) === '1') {
                    return;
                }
                setTimeout(() => {
                    modal.style.display = 'flex';
                }, 650);

                function closeForSession() {
                    sessionStorage.setItem(SESSION_KEY, '1');
                    modal.style.display = 'none';
                }
                function dismissForever() {
                    localStorage.setItem(PROMO_KEY, '1');
                    sessionStorage.setItem(SESSION_KEY, '1');
                    modal.style.display = 'none';
                }

                document.getElementById('smt-promo-close-btn')?.addEventListener('click', closeForSession);
                document.getElementById('smt-promo-later-btn')?.addEventListener('click', closeForSession);
                document.getElementById('smt-promo-dont-show-btn')?.addEventListener('click', dismissForever);
                document.getElementById('smt-promo-cta-link')?.addEventListener('click', dismissForever);
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) closeForSession();
                });
            })();
        </script>
    @endif

    {{-- Chrome-Style Top PWA Install Popup & Service Worker --}}
    <div id="smt-chrome-install-popup"
         style="display:none;"
         class="fixed top-3 inset-x-3 mx-auto z-50 max-w-md rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-2xl">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <img src="/images/pwa-icon-192.png" alt="{{ $storeName }}" class="h-10 w-10 rounded-xl border border-slate-200 shrink-0">
                <div class="min-w-0">
                    <p class="text-xs sm:text-sm font-bold text-slate-900 truncate">Install {{ $storeName }}</p>
                    <p class="text-[11px] text-slate-500 truncate">{{ request()->getHost() }} · Add to Home screen</p>
                </div>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button"
                        id="smt-chrome-install-accept"
                        class="rounded-lg bg-slate-900 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 transition cursor-pointer">
                    Install
                </button>
                <button type="button"
                        id="smt-chrome-install-dismiss"
                        aria-label="Dismiss install prompt"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition cursor-pointer">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
    </div>
    <script>
        (function () {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('/sw.js').catch(() => {});
            }
            let deferredPrompt = null;
            const DISMISS_KEY = 'smt_store_pwa_dismissed';
            const isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
            const popup = document.getElementById('smt-chrome-install-popup');
            const acceptBtn = document.getElementById('smt-chrome-install-accept');
            const dismissBtn = document.getElementById('smt-chrome-install-dismiss');

            if (isStandalone) return;

            window.addEventListener('beforeinstallprompt', (e) => {
                deferredPrompt = e;
                if (localStorage.getItem(DISMISS_KEY) !== '1' && popup) {
                    popup.style.display = 'block';
                }
            });

            window.addEventListener('appinstalled', () => {
                deferredPrompt = null;
                if (popup) popup.style.display = 'none';
            });

            acceptBtn?.addEventListener('click', async () => {
                if (!deferredPrompt) return;
                deferredPrompt.prompt();
                const choice = await deferredPrompt.userChoice.catch(() => null);
                deferredPrompt = null;
                if (popup) popup.style.display = 'none';
                if (choice && choice.outcome === 'dismissed') {
                    localStorage.setItem(DISMISS_KEY, '1');
                }
            });

            dismissBtn?.addEventListener('click', () => {
                localStorage.setItem(DISMISS_KEY, '1');
                if (popup) popup.style.display = 'none';
            });
        })();
    </script>
</body>
</html>
