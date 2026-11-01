<x-layouts.store title="Login" robots="noindex, nofollow">
    <div class="container-x py-12 lg:py-16">
        <div class="mx-auto max-w-md">
            <h1 class="display text-5xl">Welcome back</h1>
            <p class="mt-2">Log in to see all your orders in one place.</p>
            <form method="post" action="{{ route('login') }}" class="card mt-6 space-y-4 p-6">
                @csrf
                <div>
                    <label for="email" class="label">Email</label>
                    <input id="email" name="email" type="email" required autofocus autocomplete="email" class="input" value="{{ old('email') }}">
                    @error('email')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="password" class="label">Password</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password" class="input">
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" class="h-4 w-4 accent-brand"> Remember me</label>
                <button class="btn btn-primary w-full">Log in</button>
                <p class="text-center text-sm">New here? <a href="{{ route('register') }}" class="font-semibold text-brand-text underline">Create an account</a></p>
            </form>
        </div>
    </div>
</x-layouts.store>

