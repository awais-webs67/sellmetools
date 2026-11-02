<x-layouts.store title="Create Account" robots="noindex, nofollow">
    <div class="container-x py-10 sm:py-14 lg:py-18">
        <div class="mx-auto max-w-lg">
            <section class="card overflow-hidden border-2 border-line shadow-2xl">
                {{-- Branded Top Header --}}
                <div class="surface-banner p-6 sm:p-8 text-white text-center">
                    <img src="{{ asset('images/logo-white.png') }}" alt="{{ setting('store_name') }}" class="h-9 sm:h-10 w-auto mx-auto object-contain">
                    <span class="eyebrow mt-4 text-[#ff8fa3] justify-center">Instant Registration</span>
                    <h1 class="display mt-2 text-3xl sm:text-4xl text-white">Create Your Account</h1>
                    <p class="mt-2 text-xs sm:text-sm text-white/75">
                        Keep every tool purchase, wallet balance, and delivered credential safe in your personal Customer Vault.
                    </p>
                </div>

                {{-- Registration Form --}}
                <form method="post" action="{{ route('register') }}" class="p-6 sm:p-8 space-y-4">
                    @csrf
                    <div>
                        <label for="name" class="label text-xs sm:text-sm font-semibold">Full Name <span class="text-danger">*</span></label>
                        <input id="name" name="name" required autocomplete="name" class="input py-2.5 text-sm" value="{{ old('name') }}" placeholder="Ali Khan">
                        @error('name')<p class="field-error text-xs">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="email" class="label text-xs sm:text-sm font-semibold">Email Address <span class="text-danger">*</span></label>
                        <input id="email" name="email" type="email" required autocomplete="email" class="input py-2.5 text-sm" value="{{ old('email') }}" placeholder="you@gmail.com">
                        @error('email')<p class="field-error text-xs">{{ $message }}</p>@enderror
                    </div>

                    <div x-data="{ rawPhone: @js(old('phone', '')), normalize() { let d = this.rawPhone.replace(/\D+/g, ''); if (d.startsWith('00')) d = d.slice(2); if (d.length === 11 && d.startsWith('03')) d = '92' + d.slice(1); else if (d.length === 10 && d.startsWith('3')) d = '92' + d; else if (d.length === 13 && d.startsWith('9203')) d = '92' + d.slice(3); this.rawPhone = d; } }">
                        <label for="phone" class="label text-xs sm:text-sm font-semibold">WhatsApp Number <span class="text-danger">*</span> <span class="font-normal text-[11px] text-muted">(923XXXXXXXXX)</span></label>
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
                        <p class="mt-1 text-[11px] text-muted">Enter <code>03001234567</code> or <code>923001234567</code>, automatically converted to WhatsApp format.</p>
                        @error('phone')<p class="field-error text-xs">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="password" class="label text-xs sm:text-sm font-semibold">Password <span class="text-danger">*</span></label>
                            <input id="password" name="password" type="password" required autocomplete="new-password" class="input py-2.5 text-sm" placeholder="Min 8 characters">
                        </div>
                        <div>
                            <label for="password_confirmation" class="label text-xs sm:text-sm font-semibold">Confirm Password <span class="text-danger">*</span></label>
                            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="input py-2.5 text-sm" placeholder="Repeat password">
                        </div>
                    </div>
                    @error('password')<p class="field-error text-xs">{{ $message }}</p>@enderror

                    <button type="submit" class="btn btn-primary btn-lg w-full text-sm sm:text-base font-bold shadow-md mt-2">
                        Create Account &amp; Unlock Vault →
                    </button>

                    <div class="border-t border-line pt-4 text-center">
                        <p class="text-xs sm:text-sm text-muted">
                            Already registered?
                            <a href="{{ route('login') }}" class="font-bold text-brand hover:underline">
                                Log in to your account
                            </a>
                        </p>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-layouts.store>
