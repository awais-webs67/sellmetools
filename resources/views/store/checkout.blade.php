@php
    $user = auth()->user();
    $isFree = $product->isFree($user);
    $effectivePrice = isset($unitPrice) ? (float) $unitPrice : (float) $product->priceFor($user);
    $hasResellerDiscount = ! $isFree && $effectivePrice < (float) $product->price;
    $walletBalance = (float) ($user?->wallet_balance ?? 0);
    $waAdmin = admin_whatsapp_number();
@endphp
<x-layouts.store title="Checkout" robots="noindex, nofollow">
    <div class="container-x max-w-5xl py-6 sm:py-10">
        {{-- Minimal Header --}}
        <div class="flex items-center justify-between gap-3 pb-5">
            <div>
                <a href="{{ route('products.show', $product) }}"
                   onclick="try { localStorage.removeItem('smt_checkout_draft_{{ $product->id }}'); } catch(e) {}"
                   class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted hover:text-ink transition">
                    <span aria-hidden="true">←</span> Back to product
                </a>
                <h1 class="display mt-1 text-3xl sm:text-4xl text-ink">Checkout</h1>
            </div>
            <span class="badge badge-muted text-xs font-semibold">🔒 Instant Reserve</span>
        </div>

        <form method="post"
              action="{{ route('checkout.store', $product) }}"
              class="grid gap-6 lg:grid-cols-[1fr_360px]"
              data-stock-id="{{ $product->id }}"
              x-data="checkoutForm({{ $product->id }}, {{ (int) old('quantity', $quantity) }}, {{ $effectivePrice }}, {{ $product->stock_unlimited ? 'true' : 'false' }}, {{ (int) $product->available_stock }}, '{{ old('payment_method_id', $methods->first()?->id) }}')"
              @input="saveDraft()"
              @change="saveDraft()"
              @submit="submitting = true">
            @csrf

            <div class="min-w-0 space-y-5">
                {{-- STEP 1: Contact Details (Minimal & Clean) --}}
                <section class="card p-5 sm:p-6">
                    <div class="flex items-center gap-2.5 pb-4 border-b border-line">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-ink text-xs font-bold text-white">1</span>
                        <h2 class="text-base font-bold text-ink">Delivery Details</h2>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="email" class="label text-xs font-semibold">Email Address <span class="text-danger">*</span></label>
                            <input id="email" name="email" type="email" required autocomplete="email" class="input py-2.5 text-sm" value="{{ old('email', $user?->email) }}" placeholder="you@gmail.com">
                            @error('email')<p class="field-error text-xs">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="name" class="label text-xs font-semibold">Your Name</label>
                            <input id="name" name="name" type="text" autocomplete="name" class="input py-2.5 text-sm" value="{{ old('name', $user?->name) }}" placeholder="Ali Khan">
                            @error('name')<p class="field-error text-xs">{{ $message }}</p>@enderror
                        </div>

                        <div x-data="{ rawPhone: @js(old('phone', $user?->phone ?? '')), normalize() { let d = (this.rawPhone || '').replace(/\D+/g, ''); if (d.startsWith('00')) d = d.slice(2); if (d.length === 11 && d.startsWith('03')) d = '92' + d.slice(1); else if (d.length === 10 && d.startsWith('3')) d = '92' + d; else if (d.length === 13 && d.startsWith('9203')) d = '92' + d.slice(3); this.rawPhone = d; } }">
                            <label for="phone" class="label text-xs font-semibold">WhatsApp Number <span class="text-danger">*</span></label>
                            <input id="phone"
                                   name="phone"
                                   type="tel"
                                   required
                                   autocomplete="tel"
                                   x-model="rawPhone"
                                   @blur="normalize()"
                                   pattern="^(923\d{9}|03\d{9}|\d{10,15})$"
                                   title="Enter valid WhatsApp number e.g. 03001234567"
                                   class="input font-mono py-2.5 text-sm"
                                   placeholder="03001234567">
                            @error('phone')<p class="field-error text-xs">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                @if ($isFree)
                    {{-- Clean Free Product Card --}}
                    <section class="card border-2 border-success/35 bg-success-tint/50 p-5">
                        <div class="flex items-center gap-3.5">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-success text-lg text-white">🎁</span>
                            <div>
                                <h2 class="text-base font-bold text-ink">100% Free Product</h2>
                                <p class="text-xs text-body mt-0.5">No payment needed. Click <strong>Get Free Access</strong> to unlock immediately.</p>
                            </div>
                        </div>
                    </section>
                @else
                    {{-- STEP 2: Payment Method + Integrated 1-Click Wallet --}}
                    <section class="card p-5 sm:p-6">
                        <div class="flex items-center justify-between gap-2 pb-4 border-b border-line">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-ink text-xs font-bold text-white">2</span>
                                <h2 class="text-base font-bold text-ink">Payment Method</h2>
                            </div>
                            <span class="text-xs font-medium text-muted">PKR &amp; USDT</span>
                        </div>

                        {{-- High-Contrast 1-Click Account Wallet Spotlight Bar --}}
                        <div class="surface-banner mt-4 flex flex-col gap-3 rounded-xl border border-white/15 p-4 text-white shadow-md sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/15 text-base text-white">👛</span>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-white">Account Wallet</span>
                                        <span class="rounded-full bg-[#22c55e]/20 px-2 py-0.5 text-[10px] font-bold text-[#4ade80]">⚡ 1-Click Instant</span>
                                    </div>
                                    <p class="text-xs text-white/75 mt-0.5">
                                        @if ($user)
                                            Balance: <strong class="font-mono text-white">{{ money($walletBalance) }}</strong>
                                        @else
                                            Instant delivery without Transaction ID
                                        @endif
                                    </p>
                                </div>
                            </div>

                            <div class="shrink-0">
                                @if ($user)
                                    <button type="submit"
                                            x-show="{{ $walletBalance }} >= (livePrice * qty)"
                                            name="pay_with_wallet"
                                            value="1"
                                            :disabled="({{ $walletBalance }} < (livePrice * qty)) || !inStock || submitting"
                                            class="btn bg-[#16a34a] text-white hover:bg-[#15803d] w-full sm:w-auto px-4 py-2 text-xs font-bold shadow-sm">
                                        Pay with Wallet →
                                    </button>
                                    <div x-show="{{ $walletBalance }} < (livePrice * qty)" x-cloak>
                                        @if ($waAdmin !== '')
                                            <a href="https://wa.me/{{ $waAdmin }}?text={{ urlencode('Assalam o Alaikum! I want to top-up my SellMeTools Wallet Balance for account: '.$user->email) }}"
                                               target="_blank"
                                               rel="noopener"
                                               class="btn btn-hero-primary w-full sm:w-auto px-3.5 py-1.5 text-xs font-bold">
                                                + Top-Up Wallet
                                            </a>
                                        @else
                                            <a href="{{ route('account.orders') }}" class="btn btn-hero-primary w-full sm:w-auto px-3.5 py-1.5 text-xs font-bold">
                                                Wallet →
                                            </a>
                                        @endif
                                    </div>
                                @else
                                    <a href="{{ route('login') }}" class="btn btn-hero-primary w-full sm:w-auto px-3.5 py-1.5 text-xs font-bold">
                                        Login for Wallet
                                    </a>
                                @endif
                            </div>
                        </div>

                        {{-- Gateway Grid --}}
                        @if ($methods->isEmpty())
                            <div class="mt-4 rounded-xl bg-danger-tint p-4 text-xs text-danger">
                                No payment gateways active. Please contact support on WhatsApp.
                            </div>
                        @else
                            <div class="mt-3.5 grid gap-2.5 sm:grid-cols-2">
                                @foreach ($methods as $method)
                                    <label class="relative flex min-w-0 cursor-pointer items-center gap-3 rounded-xl border-2 p-3.5 transition active:scale-[0.99]"
                                           :class="method == '{{ $method->id }}' ? 'border-ink bg-grey-soft/80 shadow-2xs' : 'border-line hover:border-line-strong bg-white'">
                                        <input type="radio" name="payment_method_id" value="{{ $method->id }}" x-model="method" class="sr-only">
                                        @if ($logoUrl = $method->logoUrl())
                                            <img src="{{ $logoUrl }}" alt="{{ $method->name }}" class="h-9 w-9 shrink-0 rounded-lg object-contain border border-line bg-white p-1">
                                        @else
                                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-ink text-xs font-bold text-white">{{ mb_substr($method->name, 0, 1) }}</span>
                                        @endif
                                        <span class="min-w-0 flex-1 pr-6">
                                            <span class="block truncate text-sm font-bold text-ink">{{ $method->name }}</span>
                                            <span class="block truncate text-[11px] text-muted">{{ $method->currency === 'USDT' ? 'USDT Crypto' : 'PKR Transfer' }}</span>
                                        </span>
                                        <span x-show="method == '{{ $method->id }}'" class="absolute right-3.5 flex h-5 w-5 items-center justify-center rounded-full bg-ink text-white">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            @error('payment_method_id')<p class="field-error text-xs">{{ $message }}</p>@enderror
                        @endif
                    </section>
                @endif
            </div>

            {{-- RIGHT SIDEBAR: Clean Receipt Summary --}}
            <aside class="min-w-0 lg:sticky lg:top-24 lg:self-start">
                <div class="card p-5 sm:p-6">
                    {{-- Product Row --}}
                    <div class="flex items-center gap-3.5 pb-4 border-b border-line">
                        <div class="h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-ink border border-line">
                            @if ($img = $product->imageUrl())
                                <img src="{{ $img }}" alt="{{ $product->name }}" class="h-full w-full object-contain p-1">
                            @else
                                <div class="flex h-full w-full items-center justify-center text-white font-bold text-sm">SMT</div>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-ink line-clamp-2 leading-snug">{{ $product->name }}</p>
                            <div class="mt-1 flex items-center gap-2">
                                <span :class="inStock ? 'text-success' : 'text-danger'" class="text-[11px] font-bold">
                                    ● <span x-text="stockLabel">{{ $product->stock_unlimited ? 'In Stock' : (int) $product->available_stock.' in stock' }}</span>
                                </span>
                                @if ($hasResellerDiscount)
                                    <span class="badge badge-brand text-[10px]">VIP Price</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Touch-Friendly Quantity Stepper + Unit Price --}}
                    <div class="py-4 space-y-3 border-b border-line text-xs sm:text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-muted">Unit Price</span>
                            <span class="font-bold text-ink">
                                @if ($isFree)
                                    <span class="text-success">FREE</span>
                                @else
                                    @if ($hasResellerDiscount)
                                        <span class="line-through text-muted font-normal mr-1" x-text="$store.currency.format({{ (float) $product->price }})">{{ money($product->price) }}</span>
                                    @endif
                                    <span x-text="$store.currency.format(livePrice)">{{ money($effectivePrice) }}</span>
                                @endif
                            </span>
                        </div>

                        <div class="flex items-center justify-between">
                            <label for="quantity" class="text-muted">Quantity</label>
                            <div class="inline-flex items-center rounded-lg border border-line-strong bg-grey-soft p-0.5">
                                <button type="button"
                                        @click="qty = Math.max(1, (Number(qty) || 1) - 1)"
                                        class="flex h-7 w-7 items-center justify-center rounded-md text-sm font-bold text-ink hover:bg-white">−</button>
                                <input id="quantity"
                                       name="quantity"
                                       type="number"
                                       min="1"
                                       max="{{ $product->max_per_order }}"
                                       x-model.number="qty"
                                       class="w-10 bg-transparent text-center font-mono text-xs font-bold text-ink focus:outline-none">
                                <button type="button"
                                        @click="qty = Math.min({{ (int) $product->max_per_order }}, (Number(qty) || 1) + 1)"
                                        class="flex h-7 w-7 items-center justify-center rounded-md text-sm font-bold text-ink hover:bg-white">+</button>
                            </div>
                        </div>
                        @error('quantity')<p class="field-error text-xs">{{ $message }}</p>@enderror
                    </div>

                    {{-- Total Payable --}}
                    <div class="py-4 flex items-baseline justify-between">
                        <div>
                            <span class="block text-sm font-bold text-ink">Total</span>
                            @unless ($isFree)
                                <span class="block text-[11px] text-muted" x-text="totalSecondary"></span>
                            @endunless
                        </div>
                        @if ($isFree)
                            <span class="display text-3xl text-success">FREE</span>
                        @else
                            <span class="display text-3xl text-ink" x-text="totalPrimary">{{ setting('currency_symbol') }} <span x-text="totalFormatted"></span></span>
                        @endif
                    </div>

                    {{-- Compact Terms Checkbox --}}
                    <label class="flex items-center gap-2 text-xs text-muted cursor-pointer">
                        <input type="checkbox" name="agree" value="1" required class="h-4 w-4 shrink-0 rounded accent-ink" @checked(old('agree', true))>
                        <span>I agree to the <a href="{{ route('pages.show', 'terms') }}" target="_blank" class="text-ink font-semibold underline">terms</a> &amp; <a href="{{ route('pages.show', 'refund') }}" target="_blank" class="text-ink font-semibold underline">warranty</a>.</span>
                    </label>
                    @error('agree')<p class="field-error text-xs">{{ $message }}</p>@enderror

                    {{-- Primary Action Button --}}
                    <button type="submit" class="btn btn-primary btn-lg mt-4 w-full text-sm sm:text-base font-bold shadow-sm" :disabled="!inStock || submitting" @disabled(! $isFree && $methods->isEmpty())>
                        <span x-show="!submitting && inStock">{{ $isFree ? 'Get Free Access →' : 'Continue to Payment →' }}</span>
                        <span x-show="!submitting && !inStock" x-cloak>Out of Stock</span>
                        <span x-show="submitting" x-cloak class="inline-flex items-center gap-2">
                            <span class="h-4 w-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                            Processing...
                        </span>
                    </button>

                    <div class="mt-3 text-center">
                        <a href="{{ route('products.show', $product) }}"
                           onclick="try { localStorage.removeItem('smt_checkout_draft_{{ $product->id }}'); } catch(e) {}"
                           class="text-xs font-medium text-muted hover:text-danger transition">
                            Cancel Checkout
                        </a>
                    </div>
                </div>
            </aside>
        </form>
    </div>
</x-layouts.store>
