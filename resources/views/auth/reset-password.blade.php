<x-layouts.store title="Choose New Password" robots="noindex, nofollow">
    <div class="container-x py-10 sm:py-16 lg:py-20">
        <div class="mx-auto max-w-md">
            <section class="card overflow-hidden border-2 border-line shadow-2xl">
                {{-- Branded Top Header --}}
                <div class="surface-banner p-6 sm:p-8 text-white text-center">
                    <img src="{{ asset('images/logo-white.png') }}" alt="{{ setting('store_name') }}" class="h-8 sm:h-9 w-auto mx-auto mb-4 object-contain">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-white/10 border border-white/20 text-white shadow-inner">
                        <svg class="h-7 w-7 text-[#ff6b81]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m21 2-2 2m-6 6 4-4m-6 6 4-4m-2 6-4-4"/>
                            <circle cx="7.5" cy="15.5" r="5.5"/>
                        </svg>
                    </div>
                    <span class="eyebrow mt-4 text-[#ff8fa3] justify-center">Account Security</span>
                    <h1 class="display mt-2 text-3xl sm:text-4xl text-white">New Password</h1>
                    <p class="mt-2 text-xs sm:text-sm text-white/75">
                        Please enter your registered email and choose a strong new password for your account.
                    </p>
                </div>

                {{-- Reset Password Form --}}
                <form method="post" action="{{ route('password.update') }}" class="p-6 sm:p-8 space-y-4">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">

                    <div>
                        <label for="email" class="label text-xs sm:text-sm font-semibold">Email Address <span class="text-danger">*</span></label>
                        <input id="email"
                               name="email"
                               type="email"
                               required
                               autocomplete="email"
                               class="input py-2.5 text-sm"
                               value="{{ old('email', $email) }}"
                               placeholder="you@gmail.com">
                        @error('email')<p class="field-error text-xs">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="password" class="label text-xs sm:text-sm font-semibold">New Password <span class="text-danger">*</span></label>
                        <input id="password"
                               name="password"
                               type="password"
                               required
                               autofocus
                               autocomplete="new-password"
                               class="input py-2.5 text-sm"
                               placeholder="Min 8 characters">
                        @error('password')<p class="field-error text-xs">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="label text-xs sm:text-sm font-semibold">Confirm New Password <span class="text-danger">*</span></label>
                        <input id="password_confirmation"
                               name="password_confirmation"
                               type="password"
                               required
                               autocomplete="new-password"
                               class="input py-2.5 text-sm"
                               placeholder="Repeat password">
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-full text-sm sm:text-base font-bold shadow-md mt-2">
                        Set New Password &amp; Sign In →
                    </button>
                </form>
            </section>
        </div>
    </div>
</x-layouts.store>

