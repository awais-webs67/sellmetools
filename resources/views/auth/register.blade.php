<x-layouts.store title="Create account">
    <div class="container-x py-12 lg:py-16">
        <div class="mx-auto max-w-md">
            <h1 class="display text-5xl">Create your account</h1>
            <p class="mt-2">Keep every purchase and credential safe in “My orders”.</p>
            <form method="post" action="{{ route('register') }}" class="card mt-6 space-y-4 p-6">
                @csrf
                <div>
                    <label for="name" class="label">Name</label>
                    <input id="name" name="name" required autocomplete="name" class="input" value="{{ old('name') }}">
                    @error('name')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="email" class="label">Email</label>
                    <input id="email" name="email" type="email" required autocomplete="email" class="input" value="{{ old('email') }}">
                    @error('email')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="phone" class="label">WhatsApp / phone <span class="font-normal text-muted">(optional)</span></label>
                    <input id="phone" name="phone" type="tel" autocomplete="tel" class="input" value="{{ old('phone') }}">
                    @error('phone')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="password" class="label">Password</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password" class="input">
                    </div>
                    <div>
                        <label for="password_confirmation" class="label">Confirm</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="input">
                    </div>
                </div>
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
                <button class="btn btn-primary w-full">Create account</button>
                <p class="text-center text-sm">Already registered? <a href="{{ route('login') }}" class="font-semibold text-brand-text underline">Log in</a></p>
            </form>
        </div>
    </div>
</x-layouts.store>

