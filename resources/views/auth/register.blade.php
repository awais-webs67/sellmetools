<x-layouts.store title="Create account" robots="noindex, nofollow">
    <div class="container-x py-10 lg:py-16">
        <div class="mx-auto max-w-md">
            <span class="eyebrow">Customer Registration</span>
            <h1 class="display mt-1 text-4xl sm:text-5xl">Create your account</h1>
            <p class="mt-2 text-sm text-body">Keep every purchase, wallet balance, and delivered credential safe in your Customer Panel.</p>
            <form method="post" action="{{ route('register') }}" class="card mt-6 space-y-4 p-5 sm:p-6">
                @csrf
                <div>
                    <label for="name" class="label">Full Name <span class="text-danger">*</span></label>
                    <input id="name" name="name" required autocomplete="name" class="input" value="{{ old('name') }}" placeholder="Ali Khan">
                    @error('name')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="email" class="label">Email Address <span class="text-danger">*</span></label>
                    <input id="email" name="email" type="email" required autocomplete="email" class="input" value="{{ old('email') }}" placeholder="you@gmail.com">
                    @error('email')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div x-data="{ rawPhone: @js(old('phone', '')), normalize() { let d = this.rawPhone.replace(/\D+/g, ''); if (d.startsWith('00')) d = d.slice(2); if (d.length === 11 && d.startsWith('03')) d = '92' + d.slice(1); else if (d.length === 10 && d.startsWith('3')) d = '92' + d; else if (d.length === 13 && d.startsWith('9203')) d = '92' + d.slice(3); this.rawPhone = d; } }">
                    <label for="phone" class="label">WhatsApp Number <span class="text-danger">*</span> <span class="font-normal text-xs text-muted">(Format: 923XXXXXXXXX)</span></label>
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
                    <p class="mt-1 text-[11px] text-muted">Enter <code>03001234567</code> or <code>923001234567</code> — auto-converts to exact WhatsApp format.</p>
                    @error('phone')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="password" class="label">Password <span class="text-danger">*</span></label>
                        <input id="password" name="password" type="password" required autocomplete="new-password" class="input" placeholder="Min 8 characters">
                    </div>
                    <div>
                        <label for="password_confirmation" class="label">Confirm Password <span class="text-danger">*</span></label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="input" placeholder="Repeat password">
                    </div>
                </div>
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
                <button class="btn btn-primary w-full py-3 font-bold">Create Account →</button>
                <p class="text-center text-sm">Already registered? <a href="{{ route('login') }}" class="font-semibold text-brand-text underline">Log in</a></p>
            </form>
        </div>
    </div>
</x-layouts.store>
