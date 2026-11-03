@props(['product', 'priority' => false])
@php
    $user = auth()->user();
    $isFree = $product->isFree($user);
    $effectivePrice = $product->priceFor($user);
    $comparePrice = $effectivePrice < (float) $product->price
        ? (float) $product->price
        : ($product->compare_at_price && $product->compare_at_price > $effectivePrice ? (float) $product->compare_at_price : null);
    $discount = ! $isFree && $comparePrice && $comparePrice > $effectivePrice && $effectivePrice > 0
        ? round(100 - ($effectivePrice / $comparePrice) * 100)
        : null;
    $initialStockLabel = ! $product->inStock()
        ? 'Sold Out'
        : ($product->stock_unlimited ? 'In Stock' : (int) $product->available_stock.' in stock');
    $rating = $product->approved_reviews_avg_rating ?? null;
    $reviewCount = (int) ($product->approved_reviews_count ?? 0);
    $initials = \Illuminate\Support\Str::of($product->name)
        ->explode(' ')
        ->filter()
        ->take(2)
        ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
        ->implode('');
@endphp
<article class="group relative flex flex-col overflow-hidden rounded-xl border border-line bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition duration-150 ease-out hover:-translate-y-0.5 hover:border-brand/45 hover:shadow-[0_12px_24px_-8px_rgba(20,23,26,0.16)] active:scale-[0.98] active:opacity-85 select-none"
         x-data="stockBadge({{ $product->id }}, {{ (int) $product->available_stock }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, @js($isFree ? 'FREE' : money($effectivePrice)), {{ (float) $effectivePrice }})"
         data-stock-id="{{ $product->id }}"
         data-category-slug="{{ $product->category?->slug ?? '' }}"
         data-search-text="{{ mb_strtolower($product->name.' '.($product->category?->name ?? '').' '.($product->short_description ?? '')) }}">
    {{-- Media Box: Full uncropped image (object-contain) with soft ambient backdrop --}}
    <a href="{{ route('products.show', $product) }}" class="surface-dark-solid relative block h-36 sm:h-40 overflow-hidden" tabindex="-1" aria-hidden="true">
        @if ($img = $product->imageUrl())
            {{-- Soft ambient backdrop fills side/top gaps so object-contain never leaves ugly empty bars (uses native lazy-loading!) --}}
            <img src="{{ $img }}" alt="" width="40" height="25" @if (! $priority) loading="lazy" @endif decoding="async" aria-hidden="true" class="pointer-events-none absolute inset-0 h-full w-full object-cover scale-110 opacity-35 blur-md" :class="!inStock && 'grayscale'">
            {{-- Full uncropped product image --}}
            <img src="{{ $img }}" alt="{{ $product->name }}" width="400" height="250" @if ($priority) fetchpriority="high" @else loading="lazy" @endif decoding="async" class="relative z-10 h-full w-full object-contain p-1.5 transition duration-300 group-hover:scale-[1.03]" :class="!inStock && 'grayscale opacity-65'">
        @else
            <div class="surface-banner relative flex h-full w-full items-center gap-3 overflow-hidden px-3.5 sm:px-4 text-white" :class="!inStock && 'grayscale opacity-60'">
                <div class="pointer-events-none absolute inset-0 opacity-15 [background-image:radial-gradient(rgba(255,255,255,0.55)_1px,transparent_1px)] [background-size:14px_14px]"></div>
                <div class="pointer-events-none absolute -right-6 -bottom-6 h-24 w-24 rounded-full bg-white/10 blur-xl"></div>

                <div class="relative flex h-12 w-12 sm:h-13 sm:w-13 shrink-0 items-center justify-center rounded-xl border border-white/20 bg-white/10 shadow-inner backdrop-blur-xs transition duration-300 group-hover:scale-105 group-hover:border-white/35">
                    <span class="display text-2xl sm:text-3xl tracking-wider text-white leading-none">{{ $initials }}</span>
                </div>

                <div class="relative min-w-0 flex-1 pr-14 sm:pr-16">
                    <span class="block truncate text-[10px] font-bold tracking-widest text-white/70 uppercase">
                        {{ $product->category?->name ?? 'Digital Tool' }}
                    </span>
                    <span class="mt-0.5 block truncate text-[11px] font-medium text-white/75">
                        Verified Subscription
                    </span>
                </div>
            </div>
        @endif

        {{-- Top Badges Overlay --}}
        <div class="absolute inset-x-2 top-2 z-20 flex items-center justify-between gap-1.5">
            @if ($isFree)
                <span class="rounded bg-success px-2 py-0.5 text-[10px] font-bold tracking-wide text-white shadow-xs">🎁 FREE</span>
            @elseif ($discount)
                <span class="rounded bg-brand px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-white shadow-xs">-{{ $discount }}%</span>
            @elseif ($product->is_featured)
                <span class="rounded bg-black/65 backdrop-blur-xs border border-white/15 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-white/95">Featured</span>
            @else
                <span></span>
            @endif

            <span class="inline-flex items-center gap-1 rounded-full bg-white/95 px-2 py-0.5 text-[10px] font-bold shadow-xs backdrop-blur-xs"
                  :class="inStock ? 'text-success' : 'text-danger'">
                <span class="dot h-1.5 w-1.5" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
                <span x-text="label">{{ $initialStockLabel }}</span>
            </span>
        </div>
    </a>

    {{-- Compact Card Body --}}
    <div class="flex flex-1 flex-col p-3 sm:p-3.5">
        <h3 class="line-clamp-1 text-[13px] sm:text-[15px] font-bold leading-snug text-ink transition group-hover:text-brand" title="{{ $product->name }}">
            <a href="{{ route('products.show', $product) }}" class="after:absolute after:inset-0">{{ $product->name }}</a>
        </h3>

        <div class="mt-1 flex items-center justify-between gap-2 text-[11px] text-muted">
            <span class="truncate">{{ $product->short_description ?: ($product->category?->name ? $product->category->name.' · Instant Delivery' : 'Instant Delivery · PKR Checkout') }}</span>
            @if ($reviewCount > 0 && $rating)
                <span class="shrink-0 font-semibold text-ink">
                    <span class="text-[#f59e0b]">★</span> {{ number_format((float) $rating, 1) }}
                    <span class="font-normal text-muted">({{ $reviewCount }})</span>
                </span>
            @else
                <span class="shrink-0 font-semibold text-brand hidden sm:inline">⚡ Instant</span>
            @endif
        </div>

        <div class="mt-2.5 flex items-center justify-between gap-2 border-t border-line pt-2.5">
            <div class="flex items-baseline gap-1.5 min-w-0">
                @if ($isFree)
                    <p class="display text-xl sm:text-2xl text-success leading-none">FREE</p>
                @else
                    <p class="display text-xl sm:text-2xl text-ink leading-none" x-text="priceText">{{ money($effectivePrice) }}</p>
                @endif
                @if ($comparePrice)
                    <p class="truncate text-[10px] sm:text-xs font-medium text-muted line-through" x-text="$store.currency.format({{ (float) $comparePrice }}, true)">{{ money($comparePrice) }}</p>
                @endif
            </div>

            <span class="relative z-10 inline-flex shrink-0 items-center gap-1 rounded-lg px-2.5 py-1.5 text-[11px] sm:px-3 sm:py-1.5 sm:text-xs font-bold transition pointer-events-none"
                  :class="inStock ? 'bg-brand text-white shadow-xs group-hover:bg-brand-dark' : 'border border-line-strong text-muted opacity-70'">
                <span x-text="inStock ? '{{ $isFree ? 'Get Free' : 'Buy Now' }}' : 'View'">{{ $product->inStock() ? ($isFree ? 'Get Free' : 'Buy Now') : 'View' }}</span>
                <svg class="h-3 w-3 hidden sm:inline transition-transform group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            </span>
        </div>
    </div>
</article>
