<x-layouts.store title="Track Your Order" robots="noindex, nofollow">
    <div class="container-x py-10 sm:py-16 lg:py-20">
        <div class="mx-auto max-w-md">
            {{-- Branded Card with Elevation --}}
            <section class="card overflow-hidden border-2 border-line shadow-xl">
                {{-- Card Header --}}
                <div class="bg-brand bg-[radial-gradient(120%_120%_at_100%_0%,rgba(255,255,255,.10),transparent_55%)] p-6 sm:p-8 text-white text-center relative">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 border border-white/20 text-white shadow-inner">
                        <svg class="h-7 w-7 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.3-4.3"/>
                        </svg>
                    </div>
                    <span class="eyebrow mt-4 text-white/70 justify-center">Order Status &amp; Vault</span>
                    <h1 class="display mt-2 text-3xl sm:text-3xl text-white">Track Your Order</h1>
                    <p class="mt-2 text-xs sm:text-sm text-white/75">
                        Enter your Order ID and the email you used during checkout to view live status or retrieve your delivered credentials.
                    </p>
                </div>

                {{-- Lookup Form --}}
                <form method="post" action="{{ route('orders.lookup') }}" class="p-6 sm:p-8 space-y-4">
                    @csrf
                    <div>
                        <label for="number" class="label text-xs sm:text-sm font-semibold">
                            Order Number <span class="text-danger">*</span>
                        </label>
                        <input id="number"
                               name="number"
                               required
                               autofocus
                               class="input font-mono uppercase tracking-wider text-sm py-2.5"
                               placeholder="SMT-XXXXXXXX"
                               value="{{ old('number', request('number')) }}">
                        <p class="mt-1 text-[11px] text-muted">Format: <code>SMT-XXXXXX</code> (found in your SMS or confirmation)</p>
                        @error('number')<p class="field-error text-xs">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="email" class="label text-xs sm:text-sm font-semibold">
                            Email Address <span class="text-danger">*</span>
                        </label>
                        <input id="email"
                               name="email"
                               type="email"
                               required
                               class="input text-sm py-2.5"
                               placeholder="you@gmail.com"
                               value="{{ old('email', request('email')) }}">
                        @error('email')<p class="field-error text-xs">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-full text-sm sm:text-base font-bold shadow-md mt-2">
                        Find &amp; View Order →
                    </button>

                    <div class="border-t border-line pt-4 text-center">
                        <p class="text-xs text-muted">
                            Need help locating your order?
                            @if ($wa = admin_whatsapp_number())
                                <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="font-bold text-brand hover:underline block sm:inline mt-1 sm:mt-0">
                                    Contact us on WhatsApp
                                </a>
                            @endif
                        </p>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-layouts.store>
