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
@endphp
<article class="group card relative flex flex-col overflow-hidden transition duration-200 hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-[var(--shadow-pop)]"
         x-data="stockBadge({{ $product->id }}, {{ (int) $product->available_stock }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, @js(money($effectivePrice)))"
         data-stock-id="{{ $product->id }}">
    <a href="{{ route('products.show', $product) }}" class="relative block aspect-[16/10] overflow-hidden bg-grey-soft">
        @if ($img = $product->imageUrl())
            <img src="{{ $img }}" alt="{{ $product->name }}" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" :class="!inStock && 'grayscale opacity-60'">
        @else
            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand to-ink text-white" :class="!inStock && 'grayscale opacity-60'">
                <span class="display text-3xl sm:text-5xl tracking-wider text-white/90">{{ \Illuminate\Support\Str::of($product->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}</span>
            </div>
        @endif

        @if ($discount)
            <span class="badge absolute top-2.5 left-2.5 bg-brand px-2 py-0.5 text-[10px] sm:text-xs text-white shadow-xs">
                -{{ $discount }}%
            </span>
        @endif

        <span class="absolute top-2.5 right-2.5 px-2 py-0.5 text-[10px] sm:px-2.5 sm:text-xs shadow-xs" :class="inStock ? 'badge badge-success' : 'badge badge-danger'">
            <span class="dot" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
            <span x-text="label">{{ $initialStockLabel }}</span>
        </span>
    </a>

    <div class="flex flex-1 flex-col p-3 sm:p-4">
        @if ($product->category)
            <p class="truncate text-[10px] sm:text-[11px] font-semibold tracking-wider text-brand uppercase">{{ $product->category->name }}</p>
        @endif

        <h2 class="mt-1 line-clamp-2 text-xs sm:text-[15px] font-bold leading-snug text-ink group-hover:text-brand">
            <a href="{{ route('products.show', $product) }}">{{ $product->name }}</a>
        </h2>

        <div class="mt-auto flex items-end justify-between gap-2 border-t border-line pt-3 mt-3">
            <div class="min-w-0">
                @if ($comparePrice)
                    <p class="text-[10px] sm:text-xs text-muted line-through">{{ money($comparePrice) }}</p>
                @endif
                <p class="display text-xl sm:text-2xl text-ink leading-none" x-text="priceText">{{ money($effectivePrice) }}</p>
            </div>
            <a href="{{ route('products.show', $product) }}"
               class="btn shrink-0 px-3 py-1.5 text-[11px] sm:px-4 sm:py-2 sm:text-xs font-bold"
               :class="inStock ? 'btn-primary' : 'btn-outline opacity-60'">
                <span x-text="inStock ? 'Buy Now' : 'View'">Buy Now</span>
            </a>
        </div>
    </div>
</article>
