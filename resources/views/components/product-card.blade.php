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
@endphp
<article class="group card relative flex flex-col overflow-hidden transition duration-150 ease-out hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-[var(--shadow-pop)] active:scale-[0.98] select-none"
         x-data="stockBadge({{ $product->id }}, {{ (int) $product->available_stock }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, @js(money($effectivePrice)))"
         data-stock-id="{{ $product->id }}">
    <a href="{{ route('products.show', $product) }}" class="relative block aspect-[4/3] overflow-hidden bg-grey-soft" tabindex="-1" aria-hidden="true">
        @if ($img = $product->imageUrl())
            <img src="{{ $img }}" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.04]" :class="!inStock && 'grayscale opacity-60'">
        @else
            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand to-[#2B0912] text-white" :class="!inStock && 'grayscale opacity-60'">
                <span class="display text-4xl sm:text-5xl tracking-wider text-white/90">{{ \Illuminate\Support\Str::of($product->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}</span>
            </div>
        @endif

        <div class="absolute inset-x-2 top-2 flex items-start justify-between gap-1.5">
            @if ($discount)
                <span class="rounded-md bg-brand px-1.5 py-0.5 text-[10px] sm:text-xs font-bold text-white shadow-sm">-{{ $discount }}%</span>
            @elseif ($product->is_featured)
                <span class="rounded-md bg-ink px-1.5 py-0.5 text-[10px] sm:text-xs font-bold text-white shadow-sm">Popular</span>
            @else
                <span></span>
            @endif

            <span class="inline-flex items-center gap-1 rounded-md bg-white/95 px-1.5 py-0.5 text-[10px] sm:text-xs font-semibold shadow-sm"
                  :class="inStock ? 'text-success' : 'text-danger'">
                <span class="dot h-1.5 w-1.5" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
                <span x-text="label">{{ $initialStockLabel }}</span>
            </span>
        </div>
    </a>

    <div class="flex flex-1 flex-col p-3 sm:p-4">
        <div class="flex items-center justify-between gap-2">
            @if ($product->category)
                <p class="truncate text-[10px] sm:text-[11px] font-semibold tracking-wider text-brand uppercase">{{ $product->category->name }}</p>
            @endif
            @if ($reviewCount > 0 && $rating)
                <p class="shrink-0 text-[10px] sm:text-xs font-semibold text-ink">
                    <span class="text-[#f59e0b]">★</span> {{ number_format((float) $rating, 1) }}
                    <span class="font-normal text-muted">({{ $reviewCount }})</span>
                </p>
            @endif
        </div>

        <h3 class="mt-1 line-clamp-2 min-h-[2.5em] text-[13px] sm:text-[15px] font-bold leading-snug text-ink group-hover:text-brand">
            <a href="{{ route('products.show', $product) }}" class="after:absolute after:inset-0">{{ $product->name }}</a>
        </h3>

        <div class="mt-auto flex items-end justify-between gap-2 border-t border-line pt-3 mt-3">
            <div class="min-w-0">
                @if ($comparePrice)
                    <p class="text-[10px] sm:text-xs text-muted line-through">{{ money($comparePrice) }}</p>
                @endif
                <p class="display text-xl sm:text-2xl text-ink leading-none" x-text="priceText">{{ money($effectivePrice) }}</p>
            </div>
            <span class="relative z-10 btn shrink-0 px-3 py-1.5 text-[11px] sm:px-4 sm:py-2 sm:text-xs font-bold pointer-events-none"
                  :class="inStock ? 'btn-primary' : 'btn-outline opacity-60'">
                <span x-text="inStock ? 'Buy Now' : 'View'">Buy Now</span>
            </span>
        </div>
    </div>
</article>
