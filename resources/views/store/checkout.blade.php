@php
    $user = auth()->user();
    $effectivePrice = isset($unitPrice) ? (float) $unitPrice : (float) $product->priceFor($user);
    $hasResellerDiscount = $effectivePrice < (float) $product->price;
    $walletBalance = (float) ($user?->wallet_balance ?? 0);
@endphp
<x-layouts.store title="Checkout" robots="noindex, nofollow">
    <div class="container-x py-5 sm:py-8">
        {{-- Header Strip --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-4">
            <div>
                <span class="eyebrow">Encrypted Checkout</span>
                <h1 class="display mt-1 text-3xl sm:text-5xl">Complete Your Order</h1>
            </div>
            <a href="{{ route('products.show', $product) }}"
               onclick="try { localStorage.removeItem('smt_checkout_draft_{{ $product->id }}'); } catch(e) {}"
               class="btn btn-outline px-4 py-2 text-xs sm:text-sm font-semibold text-muted hover:text-danger hover:border-danger transition">
                ✕ Return to Product
            </a>
        </div>

        <form method="post"
              action="{{ route('checkout.store', $product) }}"
              class="mt-6 grid gap-6 lg:grid-cols-[1fr_390px]"
              data-stock-id="{{ $product->id }}"
              x-data="checkoutForm({{ $product->id }}, {{ (int) old('quantity', $quantity) }}, {{ $effectivePrice }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, {{ (int) $product->available_stock }}, '{{ old('payment_method_id', $methods->first()?->id) }}')"
              @input="saveDraft()"
              @change="saveDraft()"
              @submit="submitting = true">
            @csrf

            <div class="min-w-0 space-y-5">
                {{-- STEP 1: Customer Details --}}
                <section class="card p-5 sm:p-7 shadow-xs">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-3.5">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand text-xs font-bold text-white">1</span>
                            <div>
                                <h2 class="text-base sm:text-lg font-bold text-ink">Contact &amp; Delivery Details</h2>
                                <p class="text-xs text-muted">Where your order confirmation and credentials will be linked</p>
                            </div>
                        </div>
                        <span class="badge badge-success text-[11px] font-semibold">✓ Auto-Saved</span>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="email" class="label text-xs sm:text-sm font-semibold">Email Address <span class="text-danger">*</span></label>
                            <input id="email" name="email" type="email" required autocomplete="email" class="input py-2.5 text-sm" value="{{ old('email', $user?->email) }}" placeholder="you@gmail.com">
                            <p class="mt-1 text-[11px] text-muted">Your credentials receipt will be accessible with this email.</p>
                            @error('email')<p class="field-error text-xs">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="name" class="label text-xs sm:text-sm font-semibold">Full Name</label>
                            <input id="name" name="name" type="text" autocomplete="name" class="input py-2.5 text-sm" value="{{ old('name', $user?->name) }}" placeholder="Ali Khan">
                            @error('name')<p class="field-error text-xs">{{ $message }}</p>@enderror
                        </div>
                        <div x-data="{ rawPhone: @js(old('phone', $user?->phone ?? '')), normalize() { let d = (this.rawPhone || '').replace(/\D+/g, ''); if (d.startsWith('00')) d = d.slice(2); if (d.length === 11 && d.startsWith('03')) d = '92' + d.slice(1); else if (d.length === 10 && d.startsWith('3')) d = '92' + d; else if (d.length === 13 && d.startsWith('9203')) d = '92' + d.slice(3); this.rawPhone = d; } }">
                            <label for="phone" class="label text-xs sm:text-sm font-semibold">WhatsApp Number <span class="text-danger">*</span> <span class="text-[11px] font-normal text-muted">(923XXXXXXXXX)</span></label>
                            <input id="phone"
                                   name="phone"
                                   type="tel"
                                   required
                                   autocomplete="tel"
                                   x-model="rawPhone"
                                   @blur="normalize()"
                                   pattern="^(923\d{9}|03\d{9}|\d{10,15})$"
                                   title="Enter valid WhatsApp number e.g. 923001234567 or 03001234567"
                                   class="input font-mono py-2.5 text-sm"
                                   placeholder="923001234567">
                            <p class="mt-1 text-[10px] text-muted">Auto-formats <code>03XX...</code> to <code>923XX...</code> for instant support &amp; delivery.</p>
                            @error('phone')<p class="field-error text-xs">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                {{-- VIP Account Wallet Checkout (If balance exists) --}}
                @if ($user && $walletBalance > 0)
                    <section class="card border-2 border-brand/30 bg-gradient-to-br from-brand-tint/50 to-white p-5 sm:p-6 shadow-xs">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <span class="badge badge-brand text-[10px] font-bold">⚡ 1-Click Instant Buy</span>
                                <h2 class="mt-1.5 text-base sm:text-lg font-bold text-ink">Pay with Wallet Balance</h2>
                                <p class="text-xs text-body mt-0.5">Available Balance: <strong class="font-mono text-ink text-sm">{{ money($walletBalance) }}</strong></p>
                            </div>
                            <button type="submit"
                                    name="pay_with_wallet"
                                    value="1"
                                    :disabled="({{ $walletBalance }} < (livePrice * qty)) || !inStock || submitting"
                                    class="btn btn-primary px-5 py-3 text-xs sm:text-sm font-bold shadow-md disabled:opacity-50">
                                <span x-show="{{ $walletBalance }} >= (livePrice * qty)">👛 Instant Buy with Wallet →</span>
                                <span x-show="{{ $walletBalance }} < (livePrice * qty)" x-cloak>Insufficient Balance</span>
                            </button>
                        </div>
                    </section>
                @endif

                {{-- STEP 2: Payment Method Selection --}}
                <section class="card p-5 sm:p-7 shadow-xs">
                    <div class="flex items-center gap-2.5 border-b border-line pb-3.5">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand text-xs font-bold text-white">2</span>
                        <div>
                            <h2 class="text-base sm:text-lg font-bold text-ink">Select Payment Method</h2>
                            <p class="text-xs text-muted">Choose how you wish to pay in PKR or USDT</p>
                        </div>
                    </div>

                    @if ($methods->isEmpty())
                        <div class="mt-4 rounded-xl bg-danger-tint p-4 text-xs sm:text-sm text-danger">
                            No active payment methods found. Please contact our support team on WhatsApp.
                        </div>
                    @else
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach ($methods as $method)
                                <label class="relative flex min-w-0 cursor-pointer items-center gap-3.5 rounded-xl border-2 p-3.5 sm:p-4 transition active:scale-[0.98]"
                                       :class="method == '{{ $method->id }}' ? 'border-brand bg-brand-tint/60 shadow-xs' : 'border-line hover:border-line-strong bg-white'">
                                    <input type="radio" name="payment_method_id" value="{{ $method->id }}" x-model="method" class="sr-only">
                                    @if ($logoUrl = $method->logoUrl())
                                        <img src="{{ $logoUrl }}" alt="{{ $method->name }}" class="h-9 w-9 sm:h-10 sm:w-10 shrink-0 rounded-lg object-contain border border-line bg-white p-1">
                                    @else
                                        <span class="flex h-9 w-9 sm:h-10 sm:w-10 shrink-0 items-center justify-center rounded-lg bg-ink text-xs sm:text-sm font-bold text-white shadow-xs">{{ mb_substr($method->name, 0, 1) }}</span>
                                    @endif
                                    <span class="min-w-0 flex-1 pr-5">
                                        <span class="block truncate text-xs sm:text-sm font-bold text-ink">{{ $method->name }}</span>
                                        <span class="block truncate text-[11px] text-muted mt-0.5">
                                            {{ $method->currency === 'USDT' ? 'USDT (TRC20 / Binance Pay)' : 'Pakistani Wallets / Bank Transfer' }}
                                        </span>
                                    </span>
                                    <div x-show="method == '{{ $method->id }}'" class="absolute top-3.5 right-3.5 flex h-5 w-5 items-center justify-center rounded-full bg-brand text-white shadow-xs">
                                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                        @error('payment_method_id')<p class="field-error text-xs">{{ $message }}</p>@enderror
                    @endif
                </section>
            </div>

            {{-- SIDEBAR: Order Summary & Placement --}}
            <aside class="min-w-0 lg:sticky lg:top-24 lg:self-start">
                <div class="card p-5 sm:p-6 shadow-lg border-2 border-line">
                    <div class="flex items-center justify-between gap-2 border-b border-line pb-3">
                        <h2 class="text-base sm:text-lg font-bold text-ink">Order Summary</h2>
                        <button type="button"
                                @click="$store.stock.manualSync()"
                                class="text-xs font-semibold text-brand hover:underline">
                            <span x-show="!$store.stock.syncing">↻ Sync Price</span>
                            <span x-show="$store.stock.syncing">Syncing...</span>
                        </button>
                    </div>

                    {{-- Product Snippet --}}
                    <div class="mt-4 flex gap-3.5">
                        <div class="h-16 w-16 shrink-0 overflow-hidden rounded-xl bg-brand-tint border border-line">
                            @if ($img = $product->imageUrl())
                                <img src="{{ $img }}" alt="{{ $product->name }}" class="h-full w-full object-cover">
                            @else
                                <div class="flex h-full w-full items-center justify-center bg-brand text-white font-bold text-lg">SMT</div>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm sm:text-base font-bold text-ink break-words leading-tight">{{ $product->name }}</p>
                            <div class="mt-1 flex flex-wrap items-center justify-between gap-1.5">
                                <p class="text-xs sm:text-sm font-semibold text-ink">
                                    @if ($hasResellerDiscount)
                                        <span class="line-through text-muted mr-1" x-text="$store.currency.format({{ (float) $product->price }})">{{ money($product->price) }}</span>
                                        <span class="text-brand font-bold" x-text="$store.currency.format(livePrice)">{{ money($effectivePrice) }}</span>
                                        <span class="badge badge-brand ml-1 text-[10px]">VIP</span>
                                    @else
                                        <span x-text="$store.currency.format(livePrice)">{{ money($effectivePrice) }}</span> each
                                    @endif
                                </p>
                                <span :class="inStock ? 'badge badge-success' : 'badge badge-danger'" class="text-[10px]">
                                    <span class="dot" :class="inStock ? 'bg-success live-dot' : 'bg-danger'"></span>
                                    <span x-text="stockLabel">Stock: {{ (int) $product->available_stock }}</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    {{-- Quantity Selector --}}
                    <div class="mt-5 flex items-center justify-between border-t border-line pt-4">
                        <label for="quantity" class="text-xs sm:text-sm font-semibold text-ink">Quantity</label>
                        <input id="quantity" name="quantity" type="number" min="1" max="{{ $product->max_per_order }}" x-model.number="qty" class="input w-24 py-1.5 text-center font-bold text-sm">
                    </div>
                    @error('quantity')<p class="field-error text-xs">{{ $message }}</p>@enderror

                    {{-- Total Price Bar --}}
                    <div class="mt-4 flex items-end justify-between border-t border-line pt-4">
                        <div>
                            <span class="block text-sm font-medium text-ink">Total Payable</span>
                            <span class="block text-[11px] font-semibold text-muted" x-text="totalSecondary"></span>
                        </div>
                        <span class="display text-3xl sm:text-4xl text-brand" x-text="totalPrimary">{{ setting('currency_symbol') }} <span x-text="totalFormatted"></span></span>
                    </div>

                    {{-- Agreement Checkbox --}}
                    <label class="mt-4 flex items-start gap-2.5 text-xs text-body cursor-pointer">
                        <input type="checkbox" name="agree" value="1" required class="mt-0.5 h-4 w-4 shrink-0 rounded accent-brand" @checked(old('agree', true))>
                        <span>I understand and agree to the <a href="{{ route('pages.show', 'terms') }}" target="_blank" class="text-brand-text font-semibold underline">terms</a> &amp; <a href="{{ route('pages.show', 'refund') }}" target="_blank" class="text-brand-text font-semibold underline">warranty policy</a>.</span>
                    </label>
                    @error('agree')<p class="field-error text-xs">{{ $message }}</p>@enderror

                    {{-- Submit Button with Click Feedback --}}
                    <button type="submit" class="btn btn-primary btn-lg mt-5 w-full text-base font-bold shadow-md" :disabled="!inStock || submitting" @disabled($methods->isEmpty())>
                        <span x-show="!submitting && inStock">Place Order &amp; Pay →</span>
                        <span x-show="!submitting && !inStock" x-cloak>Out of Stock</span>
                        <span x-show="submitting" x-cloak class="flex items-center justify-center gap-2">
                            <span class="h-4 w-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                            Creating Order...
                        </span>
                    </button>

                    <a href="{{ route('products.show', $product) }}"
                       onclick="try { localStorage.removeItem('smt_checkout_draft_{{ $product->id }}'); } catch(e) {}"
                       class="btn btn-outline mt-2.5 w-full py-2 text-xs font-semibold text-muted hover:text-danger">
                        ✕ Cancel Checkout
                    </a>

                    <div class="mt-4 rounded-lg bg-grey-soft p-2.5 text-center text-[11px] text-muted">
                        🔒 Stock reserved for {{ setting('order_expiry_minutes') }} minutes once order is created.
                    </div>
                </div>
            </aside>
        </form>
    </div>
</x-layouts.store>
