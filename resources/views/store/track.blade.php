<x-layouts.store title="Track order">
    <div class="container-x py-12 lg:py-16">
        <div class="mx-auto max-w-lg">
            <span class="eyebrow">Order lookup</span>
            <h1 class="display mt-2 text-5xl">Track your order</h1>
            <p class="mt-2">Enter your order number and the email you used at checkout.</p>
            <form method="post" action="{{ route('orders.lookup') }}" class="card mt-6 space-y-4 p-6">
                @csrf
                <div>
                    <label for="number" class="label">Order number</label>
                    <input id="number" name="number" required class="input font-mono uppercase" placeholder="SMT-XXXXXXXX" value="{{ old('number') }}">
                    @error('number')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="email" class="label">Email</label>
                    <input id="email" name="email" type="email" required class="input" value="{{ old('email') }}">
                    @error('email')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <button class="btn btn-primary w-full">Find order</button>
            </form>
        </div>
    </div>
</x-layouts.store>

