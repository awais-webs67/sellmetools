@props(['product'])
@php
    $discount = $product->compare_at_price && $product->compare_at_price > $product->price
        ? round(100 - ($product->price / $product->compare_at_price) * 100)
        : null;
    $initialStockLabel = ! $product->inStock()
        ? 'Sold Out (0)'
        : ($product->stock_unlimited ? 'Unlimited Stock' : 'Stock: '.(int) $product->available_stock);
    $reviewsCount = (int) ($product->approved_reviews_count ?? 0);
    $avgRating = $reviewsCount > 0 ? round((float) $product->approved_reviews_avg_rating, 1) : 5.0;
@endphp
<article class="group card relative flex flex-col overflow-hidden transition duration-200 hover:-translate-y-1 hover:border-brand/40 hover:shadow-[var(--shadow-pop)]"
         x-data="stockBadge({{ $product->id }}, {{ (int) $product->available_stock }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, @js(money($product->price)))"
         data-stock-id="{{ $product->id }}">
    <a href="{{ route('products.show', $product) }}" class="relative block aspect-[16/11] overflow-hidden bg-grey-soft">
        @if ($img = $product->imageUrl())
            <img src="{{ $img }}" alt="{{ $product->name }}" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" :class="!inStock && 'grayscale opacity-60'">
        @else
            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand to-ink text-white" :class="!inStock && 'grayscale opacity-60'">
                <span class="display text-3xl sm:text-5xl tracking-wider text-white/90">{{ \Illuminate\Support\Str::of($product->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}</span>
            </div>
        @endif

        <div class="absolute top-2 left-2 flex flex-wrap gap-1 sm:top-3 sm:left-3">
            @if ($product->is_featured)<span class="badge bg-brand px-2 py-0.5 text-[10px] sm:text-xs text-white shadow-xs">Hot</span>@endif
            @if ($discount)<span class="badge bg-ink px-2 py-0.5 text-[10px] sm:text-xs text-white">-{{ $discount }}%</span>@endif
        </div>

        <span class="absolute top-2 right-2 px-2 py-0.5 text-[10px] sm:top-3 sm:right-3 sm:px-2.5 sm:text-xs shadow-xs" :class="inStock ? 'badge badge-success' : 'badge badge-danger'">
            <span class="dot" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
            <span x-text="label">{{ $initialStockLabel }}</span>
        </span>
    </a>

    <div class="flex flex-1 flex-col p-2.5 sm:p-4">
        <div class="flex items-center justify-between gap-1">
            @if ($product->category)
                <p class="truncate text-[10px] sm:text-[11px] font-semibold tracking-wider text-brand uppercase">{{ $product->category->name }}</p>
            @else
                <span></span>
            @endif
            <span class="inline-flex shrink-0 items-center gap-0.5 text-[11px] font-semibold text-amber-500" title="Customer rating">
                ★ <span class="text-ink">{{ number_format($avgRating, 1) }}</span>
                @if ($reviewsCount > 0)
                    <span class="text-[10px] text-muted">({{ $reviewsCount }})</span>
                @endif
            </span>
        </div>

        <h2 class="mt-1 line-clamp-2 text-xs sm:text-[15px] font-bold leading-snug text-ink group-hover:text-brand">
            <a href="{{ route('products.show', $product) }}">{{ $product->name }}</a>
        </h2>

        @if ($product->short_description)
            <p class="mt-0.5 hidden line-clamp-1 text-xs text-muted sm:block">{{ $product->short_description }}</p>
        @endif

        <div class="mt-auto flex items-end justify-between gap-1.5 border-t border-line pt-2.5 mt-2.5 sm:pt-3.5 sm:mt-3.5">
            <div class="min-w-0">
                @if ($discount)
                    <p class="text-[10px] sm:text-xs text-muted line-through">{{ money($product->compare_at_price) }}</p>
                @endif
                <p class="display text-xl sm:text-3xl text-ink leading-none" x-text="priceText">{{ money($product->price) }}</p>
            </div>
            <a href="{{ route('products.show', $product) }}"
               class="btn shrink-0 px-2.5 py-1.5 text-[11px] sm:px-4 sm:py-2 sm:text-xs font-semibold"
               :class="inStock ? 'btn-primary' : 'btn-outline opacity-60'">
                <span x-text="inStock ? 'Buy' : 'View'">Buy</span>
            </a>
        </div>
    </div>
</article>
