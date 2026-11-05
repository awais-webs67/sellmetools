@php
    $logoUrl = asset('images/email-logo.png');
    $waNumber = function_exists('admin_whatsapp_number') ? admin_whatsapp_number() : '';
    $receiptUrl = route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token]);
    $pdfReceiptUrl = route('orders.receipt', ['order' => $order->number, 'token' => $order->access_token, 'download' => 'pdf']);
    $itemsToDisplay = isset($deliveredItems) && $deliveredItems->isNotEmpty() ? $deliveredItems : $order->items;
    $totalFormatted = (float) $order->total <= 0 ? 'Rs 0 (Free)' : money($order->total);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark light">
    <meta name="supported-color-schemes" content="dark light">
    <title>Order #{{ $order->number }} Delivered - {{ $storeName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#060911;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#F8FAFC;line-height:1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#060911;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background-color:#0D1322;border-radius:18px;border:1px solid #1E293B;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,0.6);">

                    {{-- Top Brand Accent Line --}}
                    <tr>
                        <td style="height:4px;background:linear-gradient(90deg,#9B1B30 0%,#E11D48 50%,#10B981 100%);font-size:0;line-height:0;">&nbsp;</td>
                    </tr>

                    {{-- Dark Luxury Brand Header with High-Contrast Logo Pill --}}
                    <tr>
                        <td style="background-color:#090D18;padding:20px 26px;border-bottom:1px solid #1E293B;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="left" valign="middle">
                                        <a href="{{ url('/') }}" style="text-decoration:none;display:inline-block;background-color:#FFFFFF;background-image:linear-gradient(#FFFFFF,#FFFFFF);padding:7px 14px;border-radius:10px;border:1px solid #E2E8F0;box-shadow:0 4px 12px rgba(0,0,0,0.35);">
                                            <img src="{{ $logoUrl }}" alt="{{ $storeName }}" width="155" style="display:block;height:32px;width:auto;max-width:165px;border:0;">
                                        </a>
                                    </td>
                                    <td align="right" valign="middle">
                                        <span style="display:inline-block;background-color:#064E3B;border:1px solid #10B981;color:#6EE7B7;font-size:11px;font-weight:800;padding:6px 14px;border-radius:999px;letter-spacing:0.6px;text-transform:uppercase;">
                                            &#10003; Delivered
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Dark Hero Banner --}}
                    <tr>
                        <td style="background-color:#111827;background-image:linear-gradient(180deg,#131C31 0%,#0D1322 100%);padding:30px 26px 24px;text-align:center;border-bottom:1px solid #1E293B;">
                            <div style="display:inline-block;width:48px;height:48px;line-height:48px;border-radius:999px;background-color:#064E3B;border:2px solid #10B981;color:#34D399;font-size:22px;font-weight:800;text-align:center;">
                                &#10003;
                            </div>
                            <h1 style="margin:14px 0 6px;font-size:24px;font-weight:800;letter-spacing:-0.4px;color:#FFFFFF;">
                                Order Delivered Successfully
                            </h1>
                            <p style="margin:0 0 20px;font-size:14px;color:#94A3B8;">
                                Please copy your product details below.
                            </p>

                            {{-- Structured Dark Order Summary Strip --}}
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#080C16;border:1px solid #1E293B;border-radius:12px;overflow:hidden;">
                                <tr>
                                    <td width="34%" align="center" style="padding:12px 8px;border-right:1px solid #1E293B;">
                                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#64748B;">Order ID</div>
                                        <div style="margin-top:3px;font-family:Consolas,Monaco,monospace;font-size:13px;font-weight:800;color:#F8FAFC;">#{{ $order->number }}</div>
                                    </td>
                                    <td width="33%" align="center" style="padding:12px 8px;border-right:1px solid #1E293B;">
                                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#64748B;">Total Paid</div>
                                        <div style="margin-top:3px;font-size:13px;font-weight:800;color:#34D399;">{{ $totalFormatted }}</div>
                                    </td>
                                    <td width="33%" align="center" style="padding:12px 8px;">
                                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#64748B;">Date</div>
                                        <div style="margin-top:3px;font-size:12px;font-weight:700;color:#CBD5E1;">{{ $order->created_at->format('d M Y') }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Main Body: Dark Credentials Vault --}}
                    <tr>
                        <td style="padding:26px;">
                            <div style="margin-bottom:12px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.2px;color:#94A3B8;">
                                Delivered Access Credentials
                            </div>

                            @foreach ($itemsToDisplay as $item)
                                @php
                                    $lines = $item->deliveries
                                        ->map(fn ($d) => trim((string) $d->content))
                                        ->filter()
                                        ->values();

                                    if ($lines->isEmpty()) {
                                        if (filled($order->customer_input)) {
                                            $lines = collect(["Activated on your submitted account:\n".trim((string) $order->customer_input)]);
                                        } else {
                                            $lines = collect(["Order fulfilled and activated.\nOrder ID: #{$order->number}"]);
                                        }
                                    }
                                @endphp
                                <div style="margin-bottom:18px;border:1px solid #1E293B;border-radius:14px;overflow:hidden;background-color:#080C16;">
                                    <div style="background-color:#131C31;padding:13px 18px;border-bottom:1px solid #1E293B;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="left" style="font-size:14.5px;font-weight:800;color:#FFFFFF;">
                                                    {{ $item->product_name }}
                                                </td>
                                                <td align="right">
                                                    <span style="display:inline-block;background-color:#1E293B;border:1px solid #334155;color:#38BDF8;font-family:Consolas,Monaco,monospace;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:6px;">
                                                        Qty: {{ $item->quantity }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div style="background-color:#050811;padding:18px 20px;border-left:4px solid #10B981;font-family:Consolas,Monaco,'Courier New',monospace;font-size:13.5px;font-weight:600;color:#ECFDF5;white-space:pre-wrap;word-break:break-word;line-height:1.7;">{{ $lines->implode("\n\n----------------------------------------\n\n") }}</div>
                                </div>
                            @endforeach

                            {{-- Primary CTA --}}
                            <div style="text-align:center;margin:26px 0 14px;">
                                <a href="{{ $orderUrl }}" style="display:inline-block;background-color:#10B981;color:#042F2E;font-weight:800;font-size:14px;padding:14px 30px;border-radius:10px;text-decoration:none;letter-spacing:0.2px;box-shadow:0 4px 14px rgba(16,185,129,0.3);">
                                    Open Order &amp; Copy Details &rarr;
                                </a>
                            </div>

                            {{-- Receipt & WhatsApp Buttons --}}
                            <div style="text-align:center;margin:12px 0 22px;">
                                <a href="{{ $receiptUrl }}" style="display:inline-block;background-color:#162033;border:1px solid #334155;color:#F8FAFC;font-weight:700;font-size:12.5px;padding:10px 18px;border-radius:9px;text-decoration:none;margin:4px;">
                                    View Receipt
                                </a>
                                <a href="{{ $pdfReceiptUrl }}" style="display:inline-block;background-color:#162033;border:1px solid #334155;color:#F8FAFC;font-weight:700;font-size:12.5px;padding:10px 18px;border-radius:9px;text-decoration:none;margin:4px;">
                                    Download Receipt
                                </a>
                                @if ($waNumber !== '')
                                    <a href="https://wa.me/{{ $waNumber }}" style="display:inline-block;background-color:#25D366;color:#FFFFFF;font-weight:800;font-size:12.5px;padding:10px 18px;border-radius:9px;text-decoration:none;margin:4px;">
                                        WhatsApp
                                    </a>
                                @endif
                            </div>

                            <div style="padding:14px 16px;background-color:#090D18;border:1px solid #1E293B;border-radius:10px;text-align:center;">
                                <p style="margin:0;font-size:12px;color:#94A3B8;line-height:1.6;">
                                    Your delivered credentials are also permanently saved in your
                                    <a href="{{ route('account.orders') }}" style="color:#34D399;font-weight:700;text-decoration:underline;">Customer Order Vault</a>.
                                </p>
                            </div>
                        </td>
                    </tr>

                    {{-- Dark Footer --}}
                    <tr>
                        <td style="background-color:#080C16;padding:20px 26px;border-top:1px solid #1E293B;text-align:center;">
                            <div style="margin-bottom:8px;font-size:12px;">
                                <a href="{{ url('/') }}" style="color:#94A3B8;text-decoration:none;margin:0 8px;font-weight:600;">Store</a>
                                <span style="color:#334155;">&middot;</span>
                                <a href="{{ route('shop') }}" style="color:#94A3B8;text-decoration:none;margin:0 8px;font-weight:600;">Catalog</a>
                                <span style="color:#334155;">&middot;</span>
                                <a href="{{ route('account.orders') }}" style="color:#94A3B8;text-decoration:none;margin:0 8px;font-weight:600;">My Orders</a>
                            </div>
                            <div style="font-size:11px;color:#64748B;">
                                &copy; {{ date('Y') }} {{ $storeName }} ({{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'sellmetools.com' }}). All rights reserved.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
