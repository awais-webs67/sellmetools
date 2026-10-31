<x-layouts.store :title="$product->name" :description="$product->short_description">
    <div class="container-x py-8 lg:py-12">
        <nav class="text-sm" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2 text-muted">
                <li><a href="{{ route('home') }}" class="hover:text-ink">Shop</a></li>
                @if ($product->category)
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('home', ['category' => $product->category->slug]) }}#products" class="hover:text-ink">{{ $product->category->name }}</a></li>
                @endif
                <li aria-hidden="true">/</li>
                <li class="text-ink" aria-current="page">{{ $product->name }}</li>
            </ol>
        </nav>

        <div class="mt-6 grid gap-10 lg:grid-cols-[1fr_440px]"
             x-data="stockBadge({{ $product->id }}, {{ (int) $product->available_stock }}, {{ $product->stock_unlimited ? 'true' : 'false' }})"
             data-stock-id="{{ $product->id }}">
            <div>
                <div class="card aspect-[16/10] overflow-hidden bg-grey-soft">
                    @if ($img = $product->imageUrl())
                        <img src="{{ $img }}" alt="{{ $product->name }}" class="h-full w-full object-cover">
                    @else
                        <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand-tint to-grey-soft">
                            <span class="display px-6 text-center text-6xl text-brand/80">{{ $product->name }}</span>
                        </div>
                    @endif
                </div>

                <div class="mt-10 space-y-8">
                    @if ($product->description)
                        <section>
                            <h2 class="text-lg font-semibold">Description</h2>
                            <div class="prose-store mt-3">{!! nl2br(e(strip_tags($product->description))) !!}</div>
                        </section>
                    @endif
                    <section class="card p-6">
                        <h2 class="text-lg font-semibold">How delivery works</h2>
                        <ol class="mt-3 list-decimal space-y-1.5 pl-5 text-[15px]">
                            <li>Place your order – stock is held for {{ setting('order_expiry_minutes') }} minutes.</li>
                            <li>Send the exact amount and submit your transaction ID.</li>
                            <li>After verification your product appears on the order page (and stays in “My orders”).</li>
                        </ol>
                    </section>
                </div>
            </div>

            <aside class="lg:sticky lg:top-28 lg:self-start">
                <div class="card p-6">
                    @if ($product->category)
                        <p class="text-xs font-semibold tracking-[.12em] text-muted uppercase">{{ $product->category->name }}</p>
                    @endif
                    <h1 class="mt-1 text-2xl font-bold leading-tight">{{ $product->name }}</h1>
                    @if ($product->short_description)
                        <p class="mt-2 text-[15px]">{{ $product->short_description }}</p>
                    @endif

                    <div class="mt-5 flex items-end justify-between gap-4 border-y border-line py-5">
                        <div>
                            @if ($product->compare_at_price && $product->compare_at_price > $product->price)
                                <p class="text-sm text-muted line-through">{{ money($product->compare_at_price) }}</p>
                            @endif
                            <p class="display text-5xl text-ink">{{ money($product->price) }}</p>
                        </div>
                        <span :class="inStock ? 'badge badge-success' : 'badge badge-danger'" class="text-sm">
                            <span class="dot" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
                            <span x-text="label">{{ $product->inStock() ? 'In stock' : 'Out of stock' }}</span>
                        </span>
                    </div>

                    <form method="get" action="{{ route('checkout.create', $product) }}" class="mt-5 space-y-4" x-data="{ qty: 1, max: {{ $product->max_per_order }} }">
                        <div>
                            <label class="label" for="qty">Quantity</label>
                            <div class="flex w-36 items-center rounded border border-line-strong">
                                <button type="button" class="px-3 py-2.5 text-lg text-ink disabled:opacity-40" @click="qty = Math.max(1, qty - 1)" :disabled="qty <= 1" aria-label="Decrease">−</button>
                                <input id="qty" name="qty" type="number" min="1" :max="max" x-model.number="qty" class="w-full border-0 text-center font-semibold text-ink focus:ring-0 focus:outline-none" inputmode="numeric">
                                <button type="button" class="px-3 py-2.5 text-lg text-ink disabled:opacity-40" @click="qty = Math.min(max, data.unlimited ? max : Math.max(1, data.qty), qty + 1)" :disabled="qty >= max || (!data.unlimited && qty >= data.qty)" aria-label="Increase">+</button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-full" :disabled="!inStock">
                            <span x-show="inStock">Buy now</span>
                            <span x-show="!inStock" x-cloak>Out of stock</span>
                        </button>
                    </form>

                    @if ($product->warranty)
                        <div class="mt-6 rounded-md bg-brand-tint p-4">
                            <p class="text-sm font-semibold text-brand-text">Warranty & terms</p>
                            <p class="mt-1 text-sm leading-6 whitespace-pre-line text-slate">{{ $product->warranty }}</p>
                        </div>
                    @endif

                    <ul class="mt-6 space-y-2 text-sm">
                        <li class="flex gap-2"><svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg> Instant delivery after payment verification</li>
                        <li class="flex gap-2"><svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg> Easypaisa · JazzCash · NayaPay · SadaPay · Binance</li>
                        <li class="flex gap-2"><svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg> Order page stays available for future access</li>
                    </ul>
                </div>
            </aside>
        </div>

        @if ($related->isNotEmpty())
            <section class="mt-16">
                <h2 class="display text-4xl">You may also like</h2>
                <div class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($related as $item)
                        <x-product-card :product="$item" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.store>
