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
    $initials = \Illuminate\Support\Str::of($product->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
@endphp
<article class="group relative flex flex-col rounded-2xl border border-line bg-white p-2 transition duration-200 ease-out hover:border-line-strong hover:shadow-[var(--shadow-pop)] active:scale-[0.985]"
         x-data="stockBadge({{ $product->id }}, {{ (int) $product->available_stock }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, @js(money($effectivePrice)))"
         data-stock-id="{{ $product->id }}">
    <div class="relative aspect-[4/3] overflow-hidden rounded-xl bg-paper">
        @if ($img = $product->imageUrl())
            <img src="{{ $img }}" alt="" loading="lazy" decoding="async"
                 class="h-full w-full object-cover transition duration-500 ease-out group-hover:scale-[1.03]"
                 :class="!inStock && 'grayscale opacity-60'">
        @else
            <div class="flex h-full w-full items-center justify-center bg-[linear-gradient(140deg,#8A0F27,#3B0611)]" :class="!inStock && 'grayscale opacity-60'">
                <span class="font-[family-name:var(--font-display)] text-3xl font-semibold tracking-tight text-white/90 sm:text-4xl">{{ $initials }}</span>
            </div>
        @endif

        @if ($discount)
            <span class="absolute top-2 left-2 rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-brand-text shadow-sm">Save {{ $discount }}%</span>
        @elseif ($product->is_featured)
            <span class="absolute top-2 left-2 rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-ink shadow-sm">Popular</span>
        @endif
    </div>

    <div class="flex flex-1 flex-col px-1.5 pt-3 pb-1.5 sm:px-2">
        <div class="flex items-center gap-1.5 text-[11px] sm:text-xs text-muted">
            @if ($product->category)
                <span class="truncate">{{ $product->category->name }}</span>
                <span aria-hidden="true">·</span>
            @endif
            <span class="inline-flex shrink-0 items-center gap-1" :class="inStock ? 'text-success' : 'text-danger'">
                <span class="dot h-1.5 w-1.5" :class="inStock ? 'bg-success' : 'bg-danger'"></span>
                <span x-text="label">{{ $initialStockLabel }}</span>
            </span>
        </div>

        <h3 class="mt-1 line-clamp-2 min-h-[2.6em] text-[13.5px] sm:text-[15px] font-semibold leading-snug text-ink">
            <a href="{{ route('products.show', $product) }}" class="after:absolute after:inset-0 after:rounded-2xl">{{ $product->name }}</a>
        </h3>

        @if ($reviewCount > 0 && $rating)
            <p class="mt-1 text-[11px] sm:text-xs text-muted">
                <span class="text-[#D99A1E]" aria-hidden="true">★</span>
                <span class="font-medium text-ink">{{ number_format((float) $rating, 1) }}</span> ({{ $reviewCount }})
            </p>
        @endif

        <div class="mt-auto flex items-end justify-between gap-2 pt-3">
            <div class="min-w-0 leading-none">
                <p class="font-[family-name:var(--font-display)] text-lg sm:text-xl font-semibold tracking-tight text-ink" x-text="priceText">{{ money($effectivePrice) }}</p>
                @if ($comparePrice)
                    <p class="mt-1 text-[11px] sm:text-xs text-muted line-through">{{ money($comparePrice) }}</p>
                @endif
            </div>
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-line-strong text-ink transition duration-200 group-hover:border-brand group-hover:bg-brand group-hover:text-white sm:h-10 sm:w-10" aria-hidden="true">
                <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </span>
        </div>
    </div>
</article>
