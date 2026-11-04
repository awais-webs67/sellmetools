@php
    $logoUrl = asset('images/email-logo.png');
    $waNumber = function_exists('admin_whatsapp_number') ? admin_whatsapp_number() : '';
    $receiptUrl = route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token]);
    $pdfReceiptUrl = route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token, 'download' => 'pdf']);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>Order #{{ $order->number }} Delivered - {{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F172A;line-height:1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F1F5F9;padding:32px 14px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background-color:#FFFFFF;border-radius:16px;border:1px solid #E2E8F0;overflow:hidden;box-shadow:0 4px 12px rgba(15,23,42,0.05);">

                    {{-- Light Brand Header (Dark-Mode Safe White Surface + Solid-White PNG Logo) --}}
                    <tr>
                        <td style="background-color:#FFFFFF;background-image:linear-gradient(#FFFFFF,#FFFFFF);padding:22px 28px;border-bottom:1px solid #E2E8F0;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="left" valign="middle">
                                        <a href="{{ url('/') }}" style="text-decoration:none;display:inline-block;background-color:#FFFFFF;background-image:linear-gradient(#FFFFFF,#FFFFFF);border-radius:8px;">
                                            <img src="{{ $logoUrl }}" alt="{{ $storeName }}" width="165" style="display:block;height:36px;width:auto;max-width:175px;border:0;border-radius:6px;">
                                        </a>
                                    </td>
                                    <td align="right" valign="middle">
                                        <span style="display:inline-block;background-color:#ECFDF5;border:1px solid #A7F3D0;color:#047857;font-size:11px;font-weight:700;padding:5px 12px;border-radius:999px;letter-spacing:0.2px;">
                                            Delivered
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Clean Light Status Banner --}}
                    <tr>
                        <td style="background-color:#F8FAFC;padding:28px 28px 24px;text-align:center;border-bottom:1px solid #E2E8F0;">
                            <div style="display:inline-block;width:44px;height:44px;line-height:44px;border-radius:999px;background-color:#059669;color:#FFFFFF;font-size:20px;font-weight:700;text-align:center;">
                                &#10003;
                            </div>
                            <h1 style="margin:12px 0 4px;font-size:23px;font-weight:800;letter-spacing:-0.3px;color:#0F172A;">
                                Order Delivered Successfully
                            </h1>
                            <p style="margin:0 0 10px;font-size:14px;color:#475569;">
                                Please copy your product details below.
                            </p>
                            <p style="margin:0;font-size:12px;color:#64748B;">
                                Order <strong style="color:#0F172A;font-family:monospace;">#{{ $order->number }}</strong> &middot; Paid <strong style="color:#0F172A;">{{ money($order->total) }}</strong> &middot; {{ $order->created_at->format('d M Y, h:i A') }}
                            </p>
                        </td>
                    </tr>

                    {{-- Main Body: Credentials Box --}}
                    <tr>
                        <td style="padding:28px;">
                            @foreach ($deliveredItems as $item)
                                <div style="margin-bottom:18px;border:1px solid #CBD5E1;border-radius:12px;overflow:hidden;background-color:#FFFFFF;">
                                    <div style="background-color:#F8FAFC;padding:12px 16px;border-bottom:1px solid #E2E8F0;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="left" style="font-size:14px;font-weight:700;color:#0F172A;">
                                                    {{ $item->product_name }}
                                                </td>
                                                <td align="right" style="font-size:12px;font-weight:600;color:#475569;">
                                                    Qty: {{ $item->quantity }}
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div style="background-color:#F8FAFC;padding:16px 18px;border-left:4px solid #059669;font-family:Consolas,Monaco,'Courier New',monospace;font-size:13.5px;font-weight:600;color:#0F172A;white-space:pre-wrap;word-break:break-word;line-height:1.65;">@foreach ($item->deliveries as $del){{ trim($del->content) }}@if (! $loop->last)

----------------------------------------
@endif @endforeach</div>
                                </div>
                            @endforeach

                            {{-- Primary CTA & Receipt / WhatsApp Buttons --}}
                            <div style="text-align:center;margin:24px 0 14px;">
                                <a href="{{ $orderUrl }}" style="display:inline-block;background-color:#0F172A;color:#FFFFFF;font-weight:700;font-size:13.5px;padding:13px 26px;border-radius:10px;text-decoration:none;margin:4px;">
                                    Open Order Page
                                </a>
                            </div>

                            <div style="text-align:center;margin:10px 0 20px;">
                                <a href="{{ $receiptUrl }}" style="display:inline-block;background-color:#FFFFFF;border:1px solid #CBD5E1;color:#0F172A;font-weight:600;font-size:12.5px;padding:10px 18px;border-radius:8px;text-decoration:none;margin:4px;">
                                    View Receipt
                                </a>
                                <a href="{{ $pdfReceiptUrl }}" style="display:inline-block;background-color:#FFFFFF;border:1px solid #CBD5E1;color:#0F172A;font-weight:600;font-size:12.5px;padding:10px 18px;border-radius:8px;text-decoration:none;margin:4px;">
                                    Download Receipt
                                </a>
                                @if ($waNumber !== '')
                                    <a href="https://wa.me/{{ $waNumber }}" style="display:inline-block;background-color:#25D366;color:#FFFFFF;font-weight:700;font-size:12.5px;padding:10px 18px;border-radius:8px;text-decoration:none;margin:4px;">
                                        WhatsApp Support
                                    </a>
                                @endif
                            </div>

                            <p style="margin:0;font-size:12px;color:#64748B;text-align:center;line-height:1.6;">
                                Your delivered credentials are also permanently saved in your <a href="{{ route('account.orders') }}" style="color:#0F172A;font-weight:600;text-decoration:underline;">Customer Order Vault</a>.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color:#F8FAFC;padding:18px 28px;border-top:1px solid #E2E8F0;text-align:center;font-size:11px;color:#64748B;">
                            &copy; {{ date('Y') }} {{ $storeName }} ({{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'sellmetools.com' }}). All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
