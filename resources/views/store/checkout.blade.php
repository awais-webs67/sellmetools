@php
    $user = auth()->user();
    $effectivePrice = isset($unitPrice) ? (float) $unitPrice : (float) $product->priceFor($user);
    $hasResellerDiscount = $effectivePrice < (float) $product->price;
    $walletBalance = (float) ($user?->wallet_balance ?? 0);
@endphp
<x-layouts.store title="Checkout" robots="noindex, nofollow">
    <div class="container-x py-5 sm:py-10">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <span class="eyebrow">Secure checkout</span>
                <h1 class="display mt-1 text-3xl sm:text-5xl">Complete your order</h1>
            </div>
            <a href="{{ route('products.show', $product) }}"
               onclick="try { localStorage.removeItem('smt_checkout_draft_{{ $product->id }}'); } catch(e) {}"
               class="btn btn-outline px-4 py-2 text-xs sm:text-sm font-semibold text-muted hover:text-danger hover:border-danger">
                ✕ Cancel &amp; Return to Product
            </a>
        </div>

        <form method="post"
              action="{{ route('checkout.store', $product) }}"
              class="mt-5 sm:mt-8 grid gap-6 lg:grid-cols-[1fr_380px]"
              data-stock-id="{{ $product->id }}"
              x-data="checkoutForm({{ $product->id }}, {{ (int) old('quantity', $quantity) }}, {{ $effectivePrice }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, {{ (int) $product->available_stock }}, '{{ old('payment_method_id', $methods->first()?->id) }}')"
              @input="saveDraft()"
              @change="saveDraft()"
              @submit="submitting = true">
            @csrf

            <div class="min-w-0 space-y-5">
                <section class="card p-4 sm:p-6">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="text-base sm:text-lg font-semibold">1. Your details</h2>
                            <p class="mt-0.5 text-xs sm:text-sm">Your details stay saved even if live prices &amp; stock refresh.</p>
                        </div>
                        <span class="badge badge-success text-[11px]">Auto-Saved</span>
                    </div>
                    <div class="mt-4 grid gap-3.5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="email" class="label text-xs sm:text-sm">Email address <span class="text-danger">*</span></label>
                            <input id="email" name="email" type="email" required autocomplete="email" class="input" value="{{ old('email', $user?->email) }}" placeholder="you@gmail.com">
                            @error('email')<p class="field-error text-xs">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="name" class="label text-xs sm:text-sm">Full Name</label>
                            <input id="name" name="name" type="text" autocomplete="name" class="input" value="{{ old('name', $user?->name) }}" placeholder="Your Name">
                            @error('name')<p class="field-error text-xs">{{ $message }}</p>@enderror
                        </div>
                        <div x-data="{ rawPhone: @js(old('phone', $user?->phone ?? '')), normalize() { let d = (this.rawPhone || '').replace(/\D+/g, ''); if (d.startsWith('00')) d = d.slice(2); if (d.length === 11 && d.startsWith('03')) d = '92' + d.slice(1); else if (d.length === 10 && d.startsWith('3')) d = '92' + d; else if (d.length === 13 && d.startsWith('9203')) d = '92' + d.slice(3); this.rawPhone = d; } }">
                            <label for="phone" class="label text-xs sm:text-sm">WhatsApp Number <span class="text-danger">*</span> <span class="text-[11px] font-normal text-muted">(923XXXXXXXXX)</span></label>
                            <input id="phone"
                                   name="phone"
                                   type="tel"
                                   required
                                   autocomplete="tel"
                                   x-model="rawPhone"
                                   @blur="normalize()"
                                   pattern="^(923\d{9}|03\d{9}|\d{10,15})$"
                                   title="Enter valid WhatsApp number e.g. 923001234567 or 03001234567"
                                   class="input font-mono"
                                   placeholder="923001234567">
                            <p class="mt-1 text-[10px] text-muted">Auto-formats <code>03XX...</code> to <code>923XX...</code></p>
                            @error('phone')<p class="field-error text-xs">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                @if ($user && $walletBalance > 0)
                    <section class="card border-2 border-brand/30 bg-brand-tint/40 p-4 sm:p-6">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <span class="badge badge-brand text-[10px]">⚡ Instant 1-Click Auto Delivery</span>
                                <h2 class="mt-1 text-base sm:text-lg font-bold text-ink">Pay with Account Wallet Balance</h2>
                                <p class="text-xs text-body">Available Balance: <strong class="font-mono text-ink">{{ money($walletBalance) }}</strong></p>
                            </div>
                            <button type="submit"
                                    name="pay_with_wallet"
                                    value="1"
                                    :disabled="({{ $walletBalance }} < (livePrice * qty)) || !inStock || submitting"
                                    class="btn btn-primary px-5 py-2.5 text-xs sm:text-sm font-bold disabled:opacity-50">
                                <span x-show="{{ $walletBalance }} >= (livePrice * qty)">👛 Instant Buy with Wallet →</span>
                                <span x-show="{{ $walletBalance }} < (livePrice * qty)" x-cloak>Insufficient Wallet Balance</span>
                            </button>
                        </div>
                    </section>
                @endif

                <section class="card p-4 sm:p-6">
                    <h2 class="text-base sm:text-lg font-semibold">2. Select Payment Method</h2>
                    @if ($methods->isEmpty())
                        <p class="mt-3 text-danger text-sm">No payment methods are available right now. Please contact support.</p>
                    @else
                        <div class="mt-4 grid gap-2.5 sm:grid-cols-2">
                            @foreach ($methods as $method)
                                <label class="relative flex min-w-0 cursor-pointer items-center gap-3 rounded-xl border-2 p-3 sm:p-4 transition"
                                       :class="method == '{{ $method->id }}' ? 'border-brand bg-brand-tint' : 'border-line hover:border-line-strong'">
                                    <input type="radio" name="payment_method_id" value="{{ $method->id }}" x-model="method" class="sr-only">
                                    @if ($logoUrl = $method->logoUrl())
                                        <img src="{{ $logoUrl }}" alt="{{ $method->name }}" class="h-8 w-8 sm:h-9 sm:w-9 shrink-0 rounded object-contain">
                                    @else
                                        <span class="flex h-8 w-8 sm:h-9 sm:w-9 shrink-0 items-center justify-center rounded-lg bg-ink text-xs sm:text-sm font-bold text-white">{{ mb_substr($method->name, 0, 1) }}</span>
                                    @endif
                                    <span class="min-w-0 flex-1 pr-4">
                                        <span class="block truncate text-xs sm:text-sm font-semibold text-ink">{{ $method->name }}</span>
                                        <span class="block truncate text-[11px] text-muted">
                                            {{ $method->currency === 'USDT' ? 'Pay in USDT · Instant' : 'Pay in PKR · Instant' }}
                                        </span>
                                    </span>
                                    <svg x-show="method == '{{ $method->id }}'" class="absolute top-3 right-3 h-4 w-4 sm:h-5 sm:w-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                </label>
                            @endforeach
                        </div>
                        @error('payment_method_id')<p class="field-error text-xs">{{ $message }}</p>@enderror
                    @endif
                </section>
            </div>

            <aside class="min-w-0 lg:sticky lg:top-24 lg:self-start">
                <div class="card p-4 sm:p-6">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-base sm:text-lg font-semibold">Order summary</h2>
                        <button type="button"
                                @click="$store.stock.manualSync()"
                                class="text-xs font-semibold text-brand hover:underline">
                            <span x-show="!$store.stock.syncing">↻ Sync Live Price</span>
                            <span x-show="$store.stock.syncing">Syncing...</span>
                        </button>
                    </div>

                    <div class="mt-3.5 flex gap-3.5">
                        <div class="h-14 w-14 sm:h-16 sm:w-16 shrink-0 overflow-hidden rounded-lg bg-brand-tint">
                            @if ($img = $product->imageUrl())<img src="{{ $img }}" alt="{{ $product->name }}" class="h-full w-full object-cover">@endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm sm:text-base font-semibold text-ink break-words">{{ $product->name }}</p>
                            <div class="mt-1 flex flex-wrap items-center justify-between gap-1.5">
                                <p class="text-xs sm:text-sm font-semibold text-ink">
                                    @if ($hasResellerDiscount)
                                        <span class="line-through text-muted mr-1">{{ money($product->price) }}</span>
                                        <span class="text-brand font-bold">{{ setting('currency_symbol') }} <span x-text="livePrice.toLocaleString()">{{ number_format($effectivePrice) }}</span></span>
                                        <span class="badge badge-brand ml-1 text-[10px]">VIP Reseller Rate</span>
                                    @else
                                        {{ setting('currency_symbol') }} <span x-text="livePrice.toLocaleString()">{{ number_format($effectivePrice) }}</span> each
                                    @endif
                                </p>
                                <span :class="inStock ? 'badge badge-success' : 'badge badge-danger'" class="text-[10px]">
                                    <span class="dot" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
                                    <span x-text="stockLabel">Stock: {{ (int) $product->available_stock }}</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center justify-between">
                        <label for="quantity" class="text-xs sm:text-sm font-medium text-ink">Quantity</label>
                        <input id="quantity" name="quantity" type="number" min="1" max="{{ $product->max_per_order }}" x-model.number="qty" class="input w-20 py-1.5 text-center text-sm">
                    </div>
                    @error('quantity')<p class="field-error text-xs">{{ $message }}</p>@enderror

                    <div class="mt-4 flex items-end justify-between border-t border-line pt-4">
                        <span class="text-sm font-medium text-ink">Total</span>
                        <span class="display text-3xl sm:text-4xl text-ink">{{ setting('currency_symbol') }} <span x-text="totalFormatted"></span></span>
                    </div>

                    <label class="mt-4 flex items-start gap-2 text-xs sm:text-sm">
                        <input type="checkbox" name="agree" value="1" required class="mt-0.5 h-4 w-4 shrink-0 accent-brand" @checked(old('agree', true))>
                        <span>I agree to the <a href="{{ route('pages.show', 'terms') }}" target="_blank" class="text-brand-text underline">terms</a> &amp; <a href="{{ route('pages.show', 'refund') }}" target="_blank" class="text-brand-text underline">refund policy</a>.</span>
                    </label>
                    @error('agree')<p class="field-error text-xs">{{ $message }}</p>@enderror

                    <button type="submit" class="btn btn-primary btn-lg mt-4 w-full text-sm sm:text-base" :disabled="!inStock || submitting" @disabled($methods->isEmpty())>
                        <span x-show="!submitting && inStock">Place order &amp; pay →</span>
                        <span x-show="!submitting && !inStock" x-cloak>Out of Stock</span>
                        <span x-show="submitting" x-cloak>Creating Order...</span>
                    </button>

                    <a href="{{ route('products.show', $product) }}"
                       onclick="try { localStorage.removeItem('smt_checkout_draft_{{ $product->id }}'); } catch(e) {}"
                       class="btn btn-outline mt-2.5 w-full py-2 text-xs font-semibold text-muted hover:text-danger">
                        ✕ Cancel Checkout
                    </a>

                    <p class="mt-2.5 text-center text-[11px] text-muted">Stock reserved for {{ setting('order_expiry_minutes') }} minutes.</p>
                </div>
            </aside>
        </form>
    </div>
</x-layouts.store>
