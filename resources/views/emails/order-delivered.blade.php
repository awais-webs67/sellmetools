<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order {{ $order->number }} Delivered - {{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#F4F6F8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;line-height:1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F4F6F8;padding:28px 14px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:580px;background-color:#FFFFFF;border-radius:12px;border:1px solid #E5E7EB;overflow:hidden;">
                    <tr>
                        <td style="padding:24px 28px;border-bottom:1px solid #E5E7EB;background-color:#111827;color:#FFFFFF;">
                            <p style="margin:0;font-size:12px;font-weight:600;letter-spacing:0.8px;text-transform:uppercase;color:#34D399;">Order Delivered</p>
                            <h1 style="margin:6px 0 0;font-size:20px;font-weight:700;color:#FFFFFF;">{{ $storeName }} · Order #{{ $order->number }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 16px;font-size:15px;color:#111827;">
                                Hello {{ $order->customer_name ?: 'Valued Customer' }},
                            </p>
                            <p style="margin:0 0 20px;font-size:14px;color:#374151;">
                                Thank you for your order at <strong>{{ $storeName }}</strong>. Your payment has been verified and your access details for Order <strong>#{{ $order->number }}</strong> ({{ $order->created_at->format('d M Y, h:i A') }}) are ready below.
                            </p>

                            @foreach ($deliveredItems as $item)
                                <div style="margin-bottom:18px;border:1px solid #D1D5DB;border-radius:10px;background-color:#F9FAFB;padding:16px;">
                                    <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#111827;">
                                        {{ $item->product_name }} (Qty: {{ $item->quantity }})
                                    </p>
                                    <div style="background-color:#FFFFFF;border:1px solid #E5E7EB;border-radius:8px;padding:12px 14px;font-family:Consolas,Monaco,'Courier New',monospace;font-size:13px;color:#111827;white-space:pre-wrap;word-break:break-word;line-height:1.5;">@foreach ($item->deliveries as $del){{ trim($del->content) }}@if (! $loop->last)

---
@endif @endforeach</div>
                                </div>
                            @endforeach

                            <div style="margin:26px 0;text-align:center;">
                                <a href="{{ $orderUrl }}" style="display:inline-block;background-color:#111827;color:#FFFFFF;font-weight:600;font-size:14px;padding:12px 24px;border-radius:8px;text-decoration:none;">
                                    Open Order Page &amp; Receipt
                                </a>
                            </div>

                            <p style="margin:0;font-size:13px;color:#4B5563;">
                                You can also view your order history and delivered items anytime by signing in to your account at <a href="{{ url('/') }}" style="color:#111827;text-decoration:underline;">{{ url('/') }}</a>. If you have any questions, simply reply to this email.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#F9FAFB;padding:18px 28px;border-top:1px solid #E5E7EB;font-size:12px;color:#6B7280;text-align:center;">
                            <p style="margin:0;">&copy; {{ date('Y') }} {{ $storeName }} ({{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'sellmetools.com' }}). All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
