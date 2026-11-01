@props(['product'])
@php
    $user = auth()->user();
    $effectivePrice = $product->priceFor($user);
    $comparePrice = $effectivePrice < (float) $product->price
        ? (float) $product->price
        : ($product->compare_at_price && $product->compare_at_price > $effectivePrice ? (float) $product->compare_at_price : null);
    $discount = $comparePrice && $comparePrice > $effectivePrice
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
<article class="group relative flex flex-col overflow-hidden rounded-2xl border border-line bg-white shadow-[0_1px_3px_rgba(15,23,42,0.04)] transition duration-200 ease-out hover:-translate-y-1 hover:border-brand/45 hover:shadow-[0_14px_30px_-8px_rgba(155,27,48,0.16)] active:scale-[0.99] select-none"
         x-data="stockBadge({{ $product->id }}, {{ (int) $product->available_stock }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, @js(money($effectivePrice)))"
         data-stock-id="{{ $product->id }}">
    <a href="{{ route('products.show', $product) }}" class="relative block aspect-[16/10] overflow-hidden bg-[#160509]" tabindex="-1" aria-hidden="true">
        @if ($img = $product->imageUrl())
            <img src="{{ $img }}" alt="{{ $product->name }}" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" :class="!inStock && 'grayscale opacity-60'">
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/35 via-transparent to-black/15"></div>
        @else
            <div class="relative flex h-full w-full flex-col items-center justify-center overflow-hidden bg-gradient-to-br from-[#23060E] via-[#5E1021] to-[#120307] p-4 text-white" :class="!inStock && 'grayscale opacity-60'">
                <div class="pointer-events-none absolute inset-0 opacity-15 [background-image:radial-gradient(rgba(255,255,255,0.5)_1px,transparent_1px)] [background-size:16px_16px]"></div>
                <div class="relative flex h-14 w-14 sm:h-16 sm:w-16 items-center justify-center rounded-2xl border border-white/20 bg-white/10 shadow-inner backdrop-blur-xs transition duration-300 group-hover:scale-105 group-hover:border-white/35">
                    <span class="display text-3xl sm:text-4xl tracking-wider text-white">{{ $initials }}</span>
                </div>
                <span class="relative mt-2 max-w-[85%] truncate text-[10px] font-semibold tracking-widest text-white/65 uppercase">
                    {{ $product->category?->name ?? 'Digital Subscription' }}
                </span>
            </div>
        @endif

        <div class="absolute inset-x-2.5 top-2.5 flex items-start justify-between gap-1.5">
            @if ($discount)
                <span class="rounded-md bg-brand px-2 py-0.5 text-[10px] sm:text-[11px] font-bold tracking-wide text-white shadow-sm">-{{ $discount }}% OFF</span>
            @elseif ($product->is_featured)
                <span class="rounded-md bg-ink/90 backdrop-blur-xs px-2 py-0.5 text-[10px] sm:text-[11px] font-bold tracking-wide text-white shadow-sm">Featured</span>
            @else
                <span></span>
            @endif

            <span class="inline-flex items-center gap-1.5 rounded-full bg-white/95 px-2.5 py-0.5 text-[10px] sm:text-[11px] font-bold shadow-sm backdrop-blur-xs"
                  :class="inStock ? 'text-success' : 'text-danger'">
                <span class="dot h-1.5 w-1.5" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
                <span x-text="label">{{ $initialStockLabel }}</span>
            </span>
        </div>
    </a>

    <div class="flex flex-1 flex-col p-3.5 sm:p-4 lg:p-5">
        <div class="flex items-center justify-between gap-2">
            @if ($product->category)
                <span class="inline-flex items-center truncate rounded-md bg-brand-tint px-2 py-0.5 text-[10px] font-bold tracking-wider text-brand uppercase">
                    {{ $product->category->name }}
                </span>
            @else
                <span class="inline-flex items-center rounded-md bg-grey-soft px-2 py-0.5 text-[10px] font-bold tracking-wider text-muted uppercase">
                    Verified Tool
                </span>
            @endif

            @if ($reviewCount > 0 && $rating)
                <p class="shrink-0 text-[11px] font-semibold text-ink">
                    <span class="text-[#f59e0b]">★</span> {{ number_format((float) $rating, 1) }}
                    <span class="font-normal text-muted">({{ $reviewCount }})</span>
                </p>
            @else
                <span class="shrink-0 text-[10px] font-medium text-muted hidden sm:inline">⚡ Instant</span>
            @endif
        </div>

        <h3 class="mt-2 line-clamp-2 min-h-[2.5em] text-[13px] sm:text-[15px] font-bold leading-snug text-ink transition group-hover:text-brand">
            <a href="{{ route('products.show', $product) }}" class="after:absolute after:inset-0">{{ $product->name }}</a>
        </h3>

        <p class="mt-1 line-clamp-1 text-[11px] sm:text-xs text-muted">
            {{ $product->short_description ?: 'Instant delivery on order page · PKR checkout' }}
        </p>

        <div class="mt-auto flex items-end justify-between gap-2 border-t border-line pt-3.5 mt-3.5">
            <div class="min-w-0">
                @if ($comparePrice)
                    <p class="text-[10px] sm:text-xs font-medium text-muted line-through">{{ money($comparePrice) }}</p>
                @else
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted">Price in PKR</p>
                @endif
                <p class="display text-xl sm:text-[26px] text-ink leading-none mt-0.5" x-text="priceText">{{ money($effectivePrice) }}</p>
            </div>
            <span class="relative z-10 inline-flex shrink-0 items-center gap-1 rounded-lg px-3 py-1.5 text-[11px] sm:px-3.5 sm:py-2 sm:text-xs font-bold transition pointer-events-none"
                  :class="inStock ? 'bg-brand text-white shadow-xs group-hover:bg-brand-dark' : 'border border-line-strong text-muted opacity-70'">
                <span x-text="inStock ? 'Buy Now' : 'View'">Buy Now</span>
                <svg class="h-3.5 w-3.5 hidden sm:inline transition-transform group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            </span>
        </div>
    </div>
</article>
