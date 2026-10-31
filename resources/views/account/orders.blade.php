<x-layouts.store title="My Account & Order History">
    <div class="container-x py-8 sm:py-12">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <span class="eyebrow">Customer Dashboard</span>
                <h1 class="display mt-2 text-4xl sm:text-5xl">My Order History</h1>
                <p class="mt-1 text-sm text-muted">Signed in as <strong class="text-ink">{{ auth()->user()->name }}</strong> ({{ auth()->user()->email }})</p>
            </div>
            <a href="{{ route('home') }}#products" class="btn btn-primary">Browse More Tools</a>
        </div>

        @if ($orders->isEmpty())
            <div class="card mt-8 p-12 text-center">
                <p class="text-lg font-semibold text-ink">No orders in your history yet</p>
                <p class="mt-1 text-sm text-muted">All tools and subscriptions you purchase will be saved here permanently.</p>
                <a href="{{ route('home') }}#products" class="btn btn-primary mt-5">Start shopping</a>
            </div>
        @else
            <div class="mt-8 space-y-4">
                @foreach ($orders as $order)
                    <div class="card overflow-hidden p-4 sm:p-6">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="font-mono text-base font-bold text-ink">{{ $order->number }}</span>
                                <span @class([
                                    'badge',
                                    'badge-success' => $order->status === \App\Enums\OrderStatus::Completed,
                                    'badge-brand' => in_array($order->status, \App\Enums\OrderStatus::holdingStock(), true) || $order->status === \App\Enums\OrderStatus::NeedsAttention,
                                    'badge-muted' => $order->status->isFinal() && $order->status !== \App\Enums\OrderStatus::Completed,
                                ])>{{ $order->status->label() }}</span>
                                <span class="text-xs text-muted">{{ $order->created_at->format('d M Y, h:i A') }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="display text-2xl text-ink">{{ money($order->total) }}</span>
                                <a href="{{ $order->customerUrl() }}" class="btn btn-outline px-3.5 py-1.5 text-xs">Open Order</a>
                            </div>
                        </div>

                        <div class="mt-4 space-y-3">
                            @foreach ($order->items as $item)
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ $item->product_name }} <span class="text-muted">× {{ $item->quantity }}</span></p>
                                    @if ($item->deliveries->isNotEmpty())
                                        <div class="mt-2 space-y-2">
                                            @foreach ($item->deliveries as $delivery)
                                                <div class="flex flex-col gap-2 rounded-lg border border-line bg-grey-soft p-3 sm:flex-row sm:items-center sm:justify-between" x-data="copy(@js($delivery->content))">
                                                    <code class="font-mono text-xs break-all text-ink">{{ $delivery->content }}</code>
                                                    <button type="button" class="btn btn-dark shrink-0 px-3 py-1.5 text-xs" @click="copy" x-text="copied ? 'Copied!' : 'Copy Credentials'">Copy Credentials</button>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-6">{{ $orders->links() }}</div>
        @endif
    </div>
</x-layouts.store>
