<x-layouts.store title="Account Login" robots="noindex, nofollow">
    <div class="container-x py-10 sm:py-16 lg:py-20">
        <div class="mx-auto max-w-md">
            <section class="card overflow-hidden border-2 border-line shadow-2xl">
                {{-- Branded Top Header --}}
                <div class="bg-gradient-to-br from-[#1F070E] via-[#2B0912] to-[#120408] p-6 sm:p-8 text-white text-center">
                    <img src="{{ asset('images/logo-white.png') }}" alt="{{ setting('store_name') }}" class="h-9 sm:h-10 w-auto mx-auto object-contain">
                    <span class="eyebrow mt-4 text-[#ff8fa3] justify-center">Member Access</span>
                    <h1 class="display mt-2 text-3xl sm:text-4xl text-white">Welcome Back</h1>
                    <p class="mt-2 text-xs sm:text-sm text-white/75">
                        Access your private credentials vault, order history, and account wallet balance.
                    </p>
                </div>

                {{-- Login Form --}}
                <form method="post" action="{{ route('login') }}" class="p-6 sm:p-8 space-y-4">
                    @csrf
                    <div>
                        <label for="email" class="label text-xs sm:text-sm font-semibold">Email Address <span class="text-danger">*</span></label>
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

                    <div>
                        <label for="password" class="label text-xs sm:text-sm font-semibold">Password <span class="text-danger">*</span></label>
                        <input id="password"
                               name="password"
                               type="password"
                               required
                               autocomplete="current-password"
                               class="input py-2.5 text-sm"
                               placeholder="••••••••">
                        @error('password')<p class="field-error text-xs">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex items-center justify-between text-xs sm:text-sm">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox" name="remember" class="h-4 w-4 rounded accent-brand">
                            <span class="text-body font-medium">Remember me</span>
                        </label>
                        <a href="{{ route('password.request') }}" class="font-semibold text-brand hover:underline">
                            Forgot password?
                        </a>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-full text-sm sm:text-base font-bold shadow-md mt-2">
                        Sign In to Customer Panel →
                    </button>

                    <div class="border-t border-line pt-4 text-center">
                        <p class="text-xs sm:text-sm text-muted">
                            New to {{ setting('store_name') }}?
                            <a href="{{ route('register') }}" class="font-bold text-brand hover:underline">
                                Create an account
                            </a>
                        </p>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-layouts.store>
