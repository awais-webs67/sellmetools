@props([
    'title',
    'eyebrow' => 'Information',
    'description' => null,
    'subtitle' => null,
])
@php
    $storeName = (string) setting('store_name', 'SellMeTools');
    $wa = admin_whatsapp_number();
    $navLinks = [
        ['label' => 'About Us', 'route' => route('pages.show', 'about'), 'page' => 'about'],
        ['label' => 'Contact & Support', 'route' => route('pages.show', 'contact'), 'page' => 'contact'],
        ['label' => 'FAQ & Help', 'route' => route('pages.show', 'faq'), 'page' => 'faq'],
        ['label' => 'Refund & Warranty', 'route' => route('pages.show', 'refund'), 'page' => 'refund'],
        ['label' => 'Terms of Service', 'route' => route('pages.show', 'terms'), 'page' => 'terms'],
        ['label' => 'Privacy Policy', 'route' => route('pages.show', 'privacy'), 'page' => 'privacy'],
    ];
    $currentPage = request()->route('page');
@endphp
<x-layouts.store :title="$title" :description="$description">
    {{-- Header Banner --}}
    <section class="relative overflow-hidden bg-[#0B0D10] text-white py-10 sm:py-14">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(255,255,255,.08),transparent_55%),radial-gradient(ellipse_at_bottom_left,rgba(148,163,184,.10),transparent_50%)]" aria-hidden="true"></div>
        <div class="container-x relative">
            <nav class="text-xs text-white/60 mb-3" aria-label="Breadcrumb">
                <ol class="flex items-center gap-2">
                    <li><a href="{{ route('home') }}" class="hover:text-white transition">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="text-white font-semibold">{{ $title }}</li>
                </ol>
            </nav>
            <span class="eyebrow text-white/75 before:bg-white/60">{{ $eyebrow }}</span>
            <h1 class="display mt-1.5 text-3xl sm:text-5xl lg:text-6xl text-white">{{ $title }}</h1>
            <p class="mt-2 max-w-2xl text-xs sm:text-sm leading-relaxed text-white/75">
                {{ $subtitle ?: ($description ?: "Official information, customer support and store policies for {$storeName} in Pakistan.") }}
            </p>

            {{-- Page Switcher Tabs --}}
            <nav class="mt-6 -mx-1 flex items-center gap-1.5 overflow-x-auto px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Information pages">
                @foreach ($navLinks as $item)
                    <a href="{{ $item['route'] }}"
                       @class([
                           'shrink-0 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition whitespace-nowrap',
                           'border-white bg-white text-ink shadow-xs' => $currentPage === $item['page'],
                           'border-white/15 bg-white/[0.06] text-white/80 hover:border-white/35 hover:text-white' => $currentPage !== $item['page'],
                       ])>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        </div>
    </section>

    {{-- Content Body --}}
    <div class="container-x py-8 sm:py-12">
        <div class="grid gap-8 lg:grid-cols-[1fr_320px] lg:items-start">
            <div class="card p-6 sm:p-8 lg:p-10 shadow-xs border border-line bg-white">
                <div class="prose-store text-body leading-relaxed max-w-none">
                    {{ $slot }}
                </div>
            </div>

            {{-- Sidebar --}}
            <aside class="space-y-5 lg:sticky lg:top-24">
                <div class="overflow-hidden rounded-2xl bg-[#120408] p-6 text-white">
                    <span class="text-[10px] font-bold tracking-widest text-[#ff8fa3] uppercase">24/7 Helpdesk</span>
                    <h2 class="display mt-1 text-2xl sm:text-3xl text-white">Need Fast Assistance?</h2>
                    <p class="mt-2 text-xs sm:text-sm leading-relaxed text-white/75">
                        Have an Order ID or a question about any digital tool, software license or subscription plan? Reach our team directly.
                    </p>
                    <div class="mt-5 space-y-2.5">
                        @if ($wa)
                            <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="btn w-full bg-[#25D366] py-2.5 text-xs sm:text-sm font-bold text-white hover:bg-[#1ebe5b]">
                                Chat on WhatsApp
                            </a>
                        @endif
                        <a href="{{ route('pages.show', 'contact') }}" class="btn w-full border border-white/20 py-2.5 text-xs sm:text-sm font-semibold text-white hover:bg-white/10">
                            Open Contact Form
                        </a>
                        <a href="{{ route('orders.track') }}" class="btn w-full border border-white/20 py-2.5 text-xs sm:text-sm font-semibold text-white hover:bg-white/10">
                            Track an Existing Order
                        </a>
                    </div>
                </div>

                <div class="card p-5 bg-white">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-muted">Quick Store Links</h3>
                    <ul class="mt-3 space-y-2 text-xs sm:text-sm font-medium">
                        <li><a href="{{ route('shop') }}" class="flex items-center justify-between text-ink hover:text-brand"><span>Shop All Subscriptions</span><span>→</span></a></li>
                        <li><a href="{{ route('pages.show', 'about') }}" class="flex items-center justify-between text-ink hover:text-brand"><span>About {{ $storeName }}</span><span>→</span></a></li>
                        <li><a href="{{ route('pages.show', 'faq') }}" class="flex items-center justify-between text-ink hover:text-brand"><span>Frequently Asked Questions</span><span>→</span></a></li>
                        <li><a href="{{ route('pages.show', 'refund') }}" class="flex items-center justify-between text-ink hover:text-brand"><span>Refund &amp; Warranty Policy</span><span>→</span></a></li>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.store>
