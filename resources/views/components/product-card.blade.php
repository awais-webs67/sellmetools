@props(['product'])
@php
    $discount = $product->compare_at_price && $product->compare_at_price > $product->price
        ? round(100 - ($product->price / $product->compare_at_price) * 100)
        : null;
@endphp
<article class="group card relative flex flex-col overflow-hidden transition duration-200 hover:-translate-y-1 hover:border-brand/40 hover:shadow-[var(--shadow-pop)]"
         x-data="stockBadge({{ $product->id }}, {{ (int) $product->available_stock }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, @js(money($product->price)))"
         data-stock-id="{{ $product->id }}">
    <a href="{{ route('products.show', $product) }}" class="relative block aspect-[16/10] overflow-hidden bg-grey-soft">
        @if ($img = $product->imageUrl())
            <img src="{{ $img }}" alt="{{ $product->name }}" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" :class="!inStock && 'grayscale opacity-60'">
        @else
            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand to-ink text-white" :class="!inStock && 'grayscale opacity-60'">
                <span class="display text-5xl tracking-wider text-white/90">{{ \Illuminate\Support\Str::of($product->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}</span>
            </div>
        @endif

        <div class="absolute top-3 left-3 flex flex-wrap gap-1.5">
            @if ($product->is_featured)<span class="badge bg-brand text-white shadow-xs">Featured</span>@endif
            @if ($discount)<span class="badge bg-ink text-white">-{{ $discount }}%</span>@endif
        </div>

        <span class="absolute top-3 right-3 shadow-xs" :class="inStock ? 'badge badge-success' : 'badge badge-danger'">
            <span class="dot" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
            <span x-text="label">{{ $product->inStock() ? 'In stock' : 'Out of stock' }}</span>
        </span>
    </a>

    <div class="flex flex-1 flex-col p-4 sm:p-5">
        @if ($product->category)
            <p class="text-[11px] font-semibold tracking-wider text-brand uppercase">{{ $product->category->name }}</p>
        @endif
        <h2 class="mt-1 line-clamp-2 text-base font-bold leading-snug text-ink group-hover:text-brand">
            <a href="{{ route('products.show', $product) }}">{{ $product->name }}</a>
        </h2>
        @if ($product->short_description)
            <p class="mt-1 line-clamp-1 text-xs text-muted">{{ $product->short_description }}</p>
        @endif

        <div class="mt-auto flex items-center justify-between gap-3 border-t border-line pt-4 mt-4">
            <div>
                @if ($discount)
                    <p class="text-xs text-muted line-through">{{ money($product->compare_at_price) }}</p>
                @endif
                <p class="display text-3xl text-ink" x-text="priceText">{{ money($product->price) }}</p>
            </div>
            <a href="{{ route('products.show', $product) }}"
               class="btn px-4 py-2 text-xs font-semibold"
               :class="inStock ? 'btn-primary' : 'btn-outline opacity-60'">
                <span x-text="inStock ? 'Buy Now' : 'View'">Buy Now</span>
            </a>
        </div>
    </div>
</article>
