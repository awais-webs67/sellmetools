@props(['product'])
@php
    $discount = $product->compare_at_price && $product->compare_at_price > $product->price
        ? round(100 - ($product->price / $product->compare_at_price) * 100)
        : null;
@endphp
<article class="group card relative flex flex-col overflow-hidden transition duration-200 hover:-translate-y-0.5 hover:shadow-[var(--shadow-pop)]"
         x-data="stockBadge({{ $product->id }}, {{ (int) $product->available_stock }}, {{ $product->stock_unlimited ? 'true' : 'false' }})"
         data-stock-id="{{ $product->id }}">
    <a href="{{ route('products.show', $product) }}" class="relative block aspect-[4/3] overflow-hidden bg-grey-soft" tabindex="-1" aria-hidden="true">
        @if ($img = $product->imageUrl())
            <img src="{{ $img }}" alt="" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]" :class="!inStock && 'grayscale opacity-60'">
        @else
            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand-tint to-grey-soft" :class="!inStock && 'grayscale opacity-60'">
                <span class="display text-5xl text-brand/80">{{ \Illuminate\Support\Str::of($product->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}</span>
            </div>
        @endif
        <div class="absolute top-3 left-3 flex flex-wrap gap-1.5">
            @if ($product->is_featured)<span class="badge bg-ink text-white">Hot</span>@endif
            @if ($discount)<span class="badge bg-brand text-white">-{{ $discount }}%</span>@endif
        </div>
    </a>

    <div class="flex flex-1 flex-col p-4">
        @if ($product->category)
            <p class="text-[11px] font-semibold tracking-[.12em] text-muted uppercase">{{ $product->category->name }}</p>
        @endif
        <h3 class="mt-1 line-clamp-2 text-[15px] font-semibold leading-snug text-ink">
            <a href="{{ route('products.show', $product) }}" class="after:absolute after:inset-0">{{ $product->name }}</a>
        </h3>

        <div class="mt-auto flex items-end justify-between gap-3 pt-4">
            <div>
                @if ($discount)
                    <p class="text-xs text-muted line-through">{{ money($product->compare_at_price) }}</p>
                @endif
                <p class="display text-[28px] text-ink">{{ money($product->price) }}</p>
            </div>
            <span class="relative z-10 shrink-0" :class="inStock ? 'badge-success badge' : 'badge-danger badge'">
                <span class="dot" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
                <span x-text="label">{{ $product->inStock() ? 'In stock' : 'Out of stock' }}</span>
            </span>
        </div>
    </div>
</article>

