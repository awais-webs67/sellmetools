<x-layouts.store title="Checkout" robots="noindex, nofollow">
    <div class="container-x py-8 lg:py-12">
        <span class="eyebrow">Secure checkout</span>
        <h1 class="display mt-2 text-5xl">Complete your order</h1>

        <form method="post"
              action="{{ route('checkout.store', $product) }}"
              class="mt-8 grid gap-8 lg:grid-cols-[1fr_400px]"
              data-stock-id="{{ $product->id }}"
              x-data="checkoutForm({{ $product->id }}, {{ (int) old('quantity', $quantity) }}, {{ (float) $product->price }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, {{ (int) $product->available_stock }}, '{{ old('payment_method_id', $methods->first()?->id) }}')"
              @input="saveDraft()"
              @change="saveDraft()"
              @submit="submitting = true">
            @csrf

            <div class="space-y-6">
                <section class="card p-6">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="text-lg font-semibold">1. Your details</h2>
                            <p class="mt-1 text-sm">Your details stay saved even if live prices &amp; stock refresh.</p>
                        </div>
                        <span class="badge badge-success text-xs">Auto-Saved Draft</span>
                    </div>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="email" class="label">Email address <span class="text-danger">*</span></label>
                            <input id="email" name="email" type="email" required autocomplete="email" class="input" value="{{ old('email', auth()->user()?->email) }}">
                            @error('email')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="name" class="label">Name</label>
                            <input id="name" name="name" type="text" autocomplete="name" class="input" value="{{ old('name', auth()->user()?->name) }}">
                            @error('name')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="phone" class="label">WhatsApp / phone</label>
                            <input id="phone" name="phone" type="tel" autocomplete="tel" class="input" placeholder="03xx xxxxxxx" value="{{ old('phone', auth()->user()?->phone) }}">
                            @error('phone')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <section class="card p-6">
                    <h2 class="text-lg font-semibold">2. Select Payment Wallet / Gateway</h2>
                    @if ($methods->isEmpty())
                        <p class="mt-3 text-danger">No payment methods are available right now. Please contact support.</p>
                    @else
                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            @foreach ($methods as $method)
                                <label class="relative flex cursor-pointer items-center gap-3 rounded-xl border-2 p-4 transition"
                                       :class="method == '{{ $method->id }}' ? 'border-brand bg-brand-tint' : 'border-line hover:border-line-strong'">
                                    <input type="radio" name="payment_method_id" value="{{ $method->id }}" x-model="method" class="sr-only">
                                    @if ($logoUrl = $method->logoUrl())
                                        <img src="{{ $logoUrl }}" alt="{{ $method->name }}" class="h-9 w-9 rounded object-contain">
                                    @else
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-ink text-sm font-bold text-white">{{ mb_substr($method->name, 0, 1) }}</span>
                                    @endif
                                    <span class="min-w-0 pr-5">
                                        <span class="block truncate font-semibold text-ink">{{ $method->name }}</span>
                                        <span class="block text-xs text-muted">
                                            {{ $method->currency === 'USDT' ? 'Pay in USDT (Auto/Manual)' : 'Pay in PKR · Instant Verify' }}
                                        </span>
                                    </span>
                                    <svg x-show="method == '{{ $method->id }}'" class="absolute top-3 right-3 h-5 w-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                </label>
                            @endforeach
                        </div>
                        @error('payment_method_id')<p class="field-error">{{ $message }}</p>@enderror
                    @endif
                </section>
            </div>

            <aside class="lg:sticky lg:top-28 lg:self-start">
                <div class="card p-6">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold">Order summary</h2>
                        <button type="button"
                                @click="$store.stock.manualSync()"
                                class="text-xs font-semibold text-brand hover:underline">
                            <span x-show="!$store.stock.syncing">↻ Sync Live Price</span>
                            <span x-show="$store.stock.syncing">Syncing...</span>
                        </button>
                    </div>

                    <div class="mt-4 flex gap-4">
                        <div class="h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-brand-tint">
                            @if ($img = $product->imageUrl())<img src="{{ $img }}" alt="{{ $product->name }}" class="h-full w-full object-cover">@endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-ink">{{ $product->name }}</p>
                            <div class="mt-1 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-semibold text-ink">{{ setting('currency_symbol') }} <span x-text="livePrice.toLocaleString()">{{ number_format((float) $product->price) }}</span> each</p>
                                <span :class="inStock ? 'badge badge-success' : 'badge badge-danger'" class="text-[11px]">
                                    <span class="dot" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
                                    <span x-text="stockLabel">Stock: {{ (int) $product->available_stock }}</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-between">
                        <label for="quantity" class="text-sm font-medium text-ink">Quantity</label>
                        <input id="quantity" name="quantity" type="number" min="1" max="{{ $product->max_per_order }}" x-model.number="qty" class="input w-24 text-center">
                    </div>
                    @error('quantity')<p class="field-error">{{ $message }}</p>@enderror

                    <div class="mt-5 flex items-end justify-between border-t border-line pt-5">
                        <span class="font-medium text-ink">Total</span>
                        <span class="display text-4xl text-ink">{{ setting('currency_symbol') }} <span x-text="totalFormatted"></span></span>
                    </div>

                    <label class="mt-5 flex items-start gap-2.5 text-sm">
                        <input type="checkbox" name="agree" value="1" required class="mt-1 h-4 w-4 accent-brand" @checked(old('agree', true))>
                        <span>I agree to the <a href="{{ route('pages.show', 'terms') }}" target="_blank" class="text-brand-text underline">terms</a> and the <a href="{{ route('pages.show', 'refund') }}" target="_blank" class="text-brand-text underline">refund &amp; warranty policy</a>.</span>
                    </label>
                    @error('agree')<p class="field-error">{{ $message }}</p>@enderror

                    <button type="submit" class="btn btn-primary btn-lg mt-5 w-full" :disabled="!inStock || submitting" @disabled($methods->isEmpty())>
                        <span x-show="!submitting && inStock">Place order &amp; pay →</span>
                        <span x-show="!submitting && !inStock" x-cloak>Out of Stock</span>
                        <span x-show="submitting" x-cloak>Reserving Stock &amp; Creating Order...</span>
                    </button>
                    <p class="mt-3 text-center text-xs text-muted">Stock is reserved exclusively for you for {{ setting('order_expiry_minutes') }} minutes.</p>
                </div>
            </aside>
        </form>
    </div>
</x-layouts.store>
