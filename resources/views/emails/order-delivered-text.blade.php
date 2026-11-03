{{ $storeName }} - Order #{{ $order->number }} Delivered

Hello {{ $order->customer_name ?: 'Valued Customer' }},

Thank you for your order at {{ $storeName }}. Your payment has been verified and your access details for Order #{{ $order->number }} ({{ $order->created_at->format('d M Y, h:i A') }}) are below:

@foreach ($deliveredItems as $item)
----------------------------------------
Product: {{ $item->product_name }} (Qty: {{ $item->quantity }})
@foreach ($item->deliveries as $del)
{{ trim($del->content) }}
@endforeach
----------------------------------------
@endforeach

Open your live Order Page & Receipt:
{{ $orderUrl }}

If you need any assistance, simply reply to this email.

Thank you,
{{ $storeName }} Support Team
{{ url('/') }}

