<x-layouts.store title="Checkout">
    <div class="container-x py-8 lg:py-12">
        <span class="eyebrow">Secure checkout</span>
        <h1 class="display mt-2 text-5xl">Complete your order</h1>

        <form method="post" action="{{ route('checkout.store', $product) }}" class="mt-8 grid gap-8 lg:grid-cols-[1fr_400px]"
              x-data="{ qty: {{ (int) old('quantity', $quantity) }}, price: {{ (float) $product->price }}, method: '{{ old('payment_method_id', $methods->first()?->id) }}' }">
            @csrf

            <div class="space-y-6">
                <section class="card p-6">
                    <h2 class="text-lg font-semibold">1. Your details</h2>
                    <p class="mt-1 text-sm">We use your email to send order updates. Your product is shown on the order page.</p>
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
                    <h2 class="text-lg font-semibold">2. Payment method</h2>
                    @if ($methods->isEmpty())
                        <p class="mt-3 text-danger">No payment methods are available right now. Please contact support.</p>
                    @else
                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            @foreach ($methods as $method)
                                <label class="relative flex cursor-pointer items-center gap-3 rounded-md border-2 p-4 transition"
                                       :class="method == '{{ $method->id }}' ? 'border-brand bg-brand-tint' : 'border-line hover:border-line-strong'">
                                    <input type="radio" name="payment_method_id" value="{{ $method->id }}" x-model="method" class="sr-only">
                                    @if ($method->logo)
                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($method->logo) }}" alt="" class="h-9 w-9 rounded object-contain">
                                    @else
                                        <span class="flex h-9 w-9 items-center justify-center rounded bg-ink text-sm font-bold text-white">{{ mb_substr($method->name, 0, 1) }}</span>
                                    @endif
                                    <span>
                                        <span class="block font-semibold text-ink">{{ $method->name }}</span>
                                        <span class="block text-xs text-muted">Pay in {{ $method->currency }}</span>
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
                    <h2 class="text-lg font-semibold">Order summary</h2>
                    <div class="mt-4 flex gap-4">
                        <div class="h-16 w-16 shrink-0 overflow-hidden rounded bg-brand-tint">
                            @if ($img = $product->imageUrl())<img src="{{ $img }}" alt="" class="h-full w-full object-cover">@endif
                        </div>
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $product->name }}</p>
                            <p class="text-sm">{{ money($product->price) }} each</p>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-between">
                        <label for="quantity" class="text-sm font-medium text-ink">Quantity</label>
                        <input id="quantity" name="quantity" type="number" min="1" max="{{ $product->max_per_order }}" x-model.number="qty" class="input w-24 text-center">
                    </div>
                    @error('quantity')<p class="field-error">{{ $message }}</p>@enderror

                    <div class="mt-5 flex items-end justify-between border-t border-line pt-5">
                        <span class="font-medium text-ink">Total</span>
                        <span class="display text-4xl text-ink">{{ setting('currency_symbol') }} <span x-text="(Math.max(1, qty || 1) * price).toLocaleString()"></span></span>
                    </div>

                    <label class="mt-5 flex items-start gap-2.5 text-sm">
                        <input type="checkbox" name="agree" value="1" class="mt-1 h-4 w-4 accent-brand" @checked(old('agree'))>
                        <span>I agree to the <a href="{{ route('pages.show', 'terms') }}" target="_blank" class="text-brand-text underline">terms</a> and the <a href="{{ route('pages.show', 'refund') }}" target="_blank" class="text-brand-text underline">refund & warranty policy</a>.</span>
                    </label>
                    @error('agree')<p class="field-error">{{ $message }}</p>@enderror

                    <button type="submit" class="btn btn-primary btn-lg mt-5 w-full" @disabled($methods->isEmpty())>Place order & pay</button>
                    <p class="mt-3 text-center text-xs text-muted">Stock is reserved for {{ setting('order_expiry_minutes') }} minutes after you place the order.</p>
                </div>
            </aside>
        </form>
    </div>
</x-layouts.store>

