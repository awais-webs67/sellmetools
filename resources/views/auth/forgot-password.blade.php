<x-layouts.store title="Reset Password" robots="noindex, nofollow">
    <div class="container-x py-10 sm:py-16 lg:py-20">
        <div class="mx-auto max-w-md">
            <section class="card overflow-hidden border-2 border-line shadow-2xl">
                {{-- Branded Top Header --}}
                <div class="bg-gradient-to-br from-[#1F070E] via-[#2B0912] to-[#120408] p-6 sm:p-8 text-white text-center">
                    <img src="{{ asset('images/logo-white.png') }}" alt="{{ setting('store_name') }}" class="h-8 sm:h-9 w-auto mx-auto mb-4 object-contain">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-white/10 border border-white/20 text-white shadow-inner">
                        <svg class="h-7 w-7 text-[#ff6b81]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </div>
                    <span class="eyebrow mt-4 text-[#ff8fa3] justify-center">Account Security</span>
                    <h1 class="display mt-2 text-3xl sm:text-4xl text-white">Forgot Password?</h1>
                    <p class="mt-2 text-xs sm:text-sm text-white/75">
                        No worries! Enter your registered email address and we'll send you a secure link to reset your password.
                    </p>
                </div>

                {{-- Form --}}
                <form method="post" action="{{ route('password.email') }}" class="p-6 sm:p-8 space-y-4">
                    @csrf
                    <div>
                        <label for="email" class="label text-xs sm:text-sm font-semibold">Your Registered Email <span class="text-danger">*</span></label>
                        <input id="email"
                               name="email"
                               type="email"
                               required
                               autofocus
                               autocomplete="email"
                               class="input py-2.5 text-sm"
                               value="{{ old('email') }}"
                               placeholder="you@gmail.com">
                        @error('email')<p class="field-error text-xs">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-full text-sm sm:text-base font-bold shadow-md mt-2">
                        Send Password Reset Link →
                    </button>

                    <div class="border-t border-line pt-4 text-center">
                        <p class="text-xs sm:text-sm text-muted">
                            Remember your password?
                            <a href="{{ route('login') }}" class="font-bold text-brand hover:underline">
                                Return to login
                            </a>
                        </p>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-layouts.store>

