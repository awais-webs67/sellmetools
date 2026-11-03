@php
    $logoUrl = asset('images/logo.png');
    $waNumber = function_exists('admin_whatsapp_number') ? admin_whatsapp_number() : '';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #{{ $order->number }} Delivered - {{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F172A;line-height:1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F1F5F9;padding:32px 14px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background-color:#FFFFFF;border-radius:16px;border:1px solid #E2E8F0;overflow:hidden;box-shadow:0 10px 25px -5px rgba(15,23,42,0.06);">

                    {{-- Top White Brand Logo Header --}}
                    <tr>
                        <td style="background-color:#FFFFFF;padding:22px 28px;border-bottom:1px solid #F1F5F9;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="left" valign="middle">
                                        <a href="{{ url('/') }}" style="text-decoration:none;display:inline-block;">
                                            <img src="{{ $logoUrl }}" alt="{{ $storeName }}" width="160" style="display:block;height:34px;width:auto;max-width:170px;border:0;">
                                        </a>
                                    </td>
                                    <td align="right" valign="middle">
                                        <span style="display:inline-block;background-color:#ECFDF5;border:1px solid #A7F3D0;color:#047857;font-size:11px;font-weight:700;padding:5px 12px;border-radius:999px;letter-spacing:0.3px;text-transform:uppercase;">
                                            ● Verified &amp; Delivered
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Dark Hero Banner --}}
                    <tr>
                        <td style="background-color:#14171A;background-image:linear-gradient(135deg,#14171A 0%,#2B0912 100%);padding:30px 28px;text-align:center;color:#FFFFFF;">
                            <p style="margin:0;font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#34D399;">
                                Instant Digital Delivery Vault
                            </p>
                            <h1 style="margin:8px 0 6px;font-size:24px;font-weight:800;letter-spacing:-0.3px;color:#FFFFFF;">
                                Your Access Credentials Are Ready!
                            </h1>
                            <p style="margin:0;font-size:13px;color:rgba(255,255,255,0.78);">
                                Order <strong style="color:#FFFFFF;">#{{ $order->number }}</strong> · {{ $order->created_at->format('d M Y, h:i A') }}
                            </p>
                        </td>
                    </tr>

                    {{-- Main Body --}}
                    <tr>
                        <td style="padding:30px 28px;">
                            <p style="margin:0 0 14px;font-size:15px;font-weight:600;color:#0F172A;">
                                Assalam o Alaikum {{ $order->customer_name ?: 'Valued Customer' }},
                            </p>
                            <p style="margin:0 0 22px;font-size:14px;color:#475569;line-height:1.65;">
                                Thank you for shopping with <strong>{{ $storeName }}</strong>. Your payment has been verified and your subscription credentials have been unlocked below.
                            </p>

                            {{-- Delivered Credentials Vault Boxes --}}
                            @foreach ($deliveredItems as $item)
                                <div style="margin-bottom:20px;border:1px solid #CBD5E1;border-radius:12px;overflow:hidden;background-color:#F8FAFC;">
                                    <div style="background-color:#F1F5F9;padding:12px 16px;border-bottom:1px solid #E2E8F0;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="left" style="font-size:14px;font-weight:700;color:#0F172A;">
                                                    {{ $item->product_name }}
                                                </td>
                                                <td align="right" style="font-size:12px;font-weight:700;color:#475569;">
                                                    Qty: {{ $item->quantity }}
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div style="background-color:#0F172A;padding:16px 18px;border-left:4px solid #10B981;font-family:Consolas,Monaco,'Courier New',monospace;font-size:13px;color:#34D399;white-space:pre-wrap;word-break:break-word;line-height:1.6;">@foreach ($item->deliveries as $del){{ trim($del->content) }}@if (! $loop->last)

----------------------------------------
@endif @endforeach</div>
                                </div>
                            @endforeach

                            {{-- Order Summary Bar --}}
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:22px 0;background-color:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:14px 16px;">
                                <tr>
                                    <td style="font-size:12px;color:#64748B;">
                                        Order Reference: <strong style="color:#0F172A;font-family:monospace;">{{ $order->number }}</strong>
                                    </td>
                                    <td align="right" style="font-size:13px;font-weight:700;color:#0F172A;">
                                        Total Paid: {{ money($order->total) }}
                                    </td>
                                </tr>
                            </table>

                            {{-- Primary CTA Button --}}
                            <div style="text-align:center;margin:28px 0 22px;">
                                <a href="{{ $orderUrl }}" style="display:inline-block;background-color:#9B1B30;color:#FFFFFF;font-weight:700;font-size:14px;padding:14px 30px;border-radius:10px;text-decoration:none;box-shadow:0 4px 12px rgba(155,27,48,0.25);">
                                    Open Live Order Page &amp; Receipt →
                                </a>
                            </div>

                            <p style="margin:0;font-size:12px;color:#64748B;text-align:center;line-height:1.6;">
                                Your delivered credentials are also permanently saved in your <a href="{{ route('account.orders') }}" style="color:#0F172A;font-weight:600;text-decoration:underline;">Customer Order Vault</a>.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color:#F8FAFC;padding:22px 28px;border-top:1px solid #E2E8F0;text-align:center;font-size:12px;color:#64748B;">
                            @if ($waNumber !== '')
                                <p style="margin:0 0 8px;">
                                    Need help or warranty support? Reply to this email or chat on <a href="https://wa.me/{{ $waNumber }}" style="color:#047857;font-weight:700;text-decoration:none;">WhatsApp (+{{ $waNumber }})</a>
                                </p>
                            @endif
                            <p style="margin:0;font-size:11px;color:#94A3B8;">
                                &copy; {{ date('Y') }} {{ $storeName }} ({{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'sellmetools.com' }}). A Project by AivraSol Softwares.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
