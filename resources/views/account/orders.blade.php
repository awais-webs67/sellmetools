<x-layouts.store title="My orders">
    <div class="container-x py-10 lg:py-14">
        <span class="eyebrow">Account</span>
        <h1 class="display mt-2 text-5xl">My orders</h1>

        @if ($orders->isEmpty())
            <div class="card mt-8 p-12 text-center">
                <p class="text-lg font-semibold text-ink">No orders yet</p>
                <a href="{{ route('home') }}" class="btn btn-primary mt-5">Start shopping</a>
            </div>
        @else
            <div class="card mt-8 overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead class="border-b border-line bg-grey-soft text-xs tracking-wider text-muted uppercase">
                        <tr><th class="px-5 py-3">Order</th><th class="px-5 py-3">Items</th><th class="px-5 py-3">Total</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Date</th><th class="px-5 py-3"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($orders as $order)
                            <tr>
                                <td class="px-5 py-4 font-mono font-semibold text-ink">{{ $order->number }}</td>
                                <td class="px-5 py-4">{{ $order->items->map(fn ($i) => $i->product_name.' × '.$i->quantity)->implode(', ') }}</td>
                                <td class="px-5 py-4 font-semibold text-ink">{{ money($order->total) }}</td>
                                <td class="px-5 py-4">
                                    <span @class(['badge', 'badge-success' => $order->status === \App\Enums\OrderStatus::Completed, 'badge-brand' => in_array($order->status, \App\Enums\OrderStatus::holdingStock(), true) || $order->status === \App\Enums\OrderStatus::NeedsAttention, 'badge-muted' => $order->status->isFinal() && $order->status !== \App\Enums\OrderStatus::Completed])>{{ $order->status->label() }}</span>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">{{ $order->created_at->format('d M Y') }}</td>
                                <td class="px-5 py-4 text-right"><a href="{{ $order->customerUrl() }}" class="btn btn-outline px-3 py-1.5 text-xs">View</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-6">{{ $orders->links() }}</div>
        @endif
    </div>
</x-layouts.store>

